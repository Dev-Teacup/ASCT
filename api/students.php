<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function student_payload(array $data): array
{
    return [
        'student_id' => trim((string)($data['student_id'] ?? '')),
        'first_name' => trim((string)($data['first_name'] ?? '')),
        'last_name' => trim((string)($data['last_name'] ?? '')),
        'email' => trim((string)($data['email'] ?? '')),
        'phone' => trim((string)($data['phone'] ?? '')),
        'address' => trim((string)($data['address'] ?? '')),
        'course' => trim((string)($data['course'] ?? '')),
        'year_level' => (int)($data['year_level'] ?? 0),
        'birthdate' => trim((string)($data['birthdate'] ?? '')),
        'status' => trim((string)($data['status'] ?? 'active')),
    ];
}

function validate_student_required(array $payload, array $fields): void
{
    foreach ($fields as $field) {
        if (($payload[$field] ?? '') === '' || ($field === 'year_level' && (int)$payload[$field] < 1)) {
            error_response('Please fill in all required fields.');
        }
    }

    if (!in_array($payload['status'], ['active', 'inactive'], true)) {
        error_response('Invalid student status.');
    }

    if ($payload['email'] !== '' && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        error_response('Please enter a valid email address.');
    }
}

function find_student_user_id(string $email): ?int
{
    if ($email === '') {
        return null;
    }

    $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND role = 'student' LIMIT 1");
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();

    return $id ? (int)$id : null;
}

function student_audit_label(array $student): string
{
    return trim((string)$student['first_name'] . ' ' . (string)$student['last_name']);
}

function student_audit_metadata(array $student): array
{
    return [
        'student_id' => $student['student_id'],
        'email' => $student['email'],
        'course' => $student['course'],
        'year_level' => (int)$student['year_level'],
        'previous_status' => $student['status'],
        'previous_deleted_at' => $student['deleted_at'],
    ];
}

try {
    $user = require_user();
    $role = $user['role'];
    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'list') {
        if ($role === 'student') {
            $stmt = db()->prepare('SELECT * FROM students WHERE user_id = ? OR email = ? ORDER BY id');
            $stmt->execute([(int)$user['id'], $user['email']]);
        } else {
            $stmt = db()->query('SELECT * FROM students ORDER BY id');
        }

        json_response([
            'success' => true,
            'data' => $stmt->fetchAll(),
        ]);
    }

    if ($method === 'POST' && $action === 'create') {
        require_permission($user, 'add_student', 'Only admins can add new students.');

        $payload = student_payload(request_data());
        validate_student_required($payload, ['student_id', 'first_name', 'last_name', 'email', 'phone', 'course', 'year_level', 'birthdate', 'status']);
        $studentUserId = find_student_user_id($payload['email']);

        $stmt = db()->prepare(
            'INSERT INTO students (user_id, student_id, first_name, last_name, email, phone, address, course, year_level, birthdate, status, deleted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $studentUserId,
            $payload['student_id'],
            $payload['first_name'],
            $payload['last_name'],
            $payload['email'],
            $payload['phone'],
            $payload['address'],
            $payload['course'],
            $payload['year_level'],
            $payload['birthdate'],
            $payload['status'],
            $payload['status'] === 'inactive' ? date('Y-m-d H:i:s') : null,
        ]);

        $student = fetch_student((int)db()->lastInsertId());
        json_response(['success' => true, 'message' => 'New student record created successfully.', 'data' => $student]);
    }

    if ($method === 'POST' && $action === 'update') {
        require_permission($user, 'edit_students', 'You do not have permission to edit students.');

        $data = request_data();
        $id = (int)($data['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        require_student_access($user, $student);

        $payload = student_payload($data);
        $allowedFields = $role === 'admin'
            ? ['student_id', 'first_name', 'last_name', 'email', 'phone', 'address', 'course', 'year_level', 'birthdate', 'status']
            : ($role === 'teacher'
                ? ['phone', 'address', 'course', 'year_level', 'status']
                : ['email', 'phone', 'address']);

        $requiredEditable = array_values(array_intersect($allowedFields, ['student_id', 'first_name', 'last_name', 'email', 'phone', 'course', 'year_level', 'birthdate', 'status']));
        $merged = array_merge($student, array_intersect_key($payload, array_flip($allowedFields)));
        validate_student_required($merged, $requiredEditable);

        $assignments = [];
        $params = [];

        foreach ($allowedFields as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $assignments[] = "`$field` = ?";
            $params[] = $field === 'year_level' ? (int)$payload[$field] : $payload[$field];
        }

        if (in_array('email', $allowedFields, true)) {
            $studentUserId = find_student_user_id($payload['email']);
            $assignments[] = '`user_id` = COALESCE(?, `user_id`)';
            $params[] = $studentUserId;
        }

        if (in_array('status', $allowedFields, true)) {
            $assignments[] = '`deleted_at` = CASE WHEN ? = "inactive" AND `deleted_at` IS NULL THEN NOW() WHEN ? = "active" THEN NULL ELSE `deleted_at` END';
            $params[] = $payload['status'];
            $params[] = $payload['status'];
        }

        $statusChangedToInactive = in_array('status', $allowedFields, true)
            && ($student['status'] ?? '') !== 'inactive'
            && $payload['status'] === 'inactive';

        $params[] = $id;
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE students SET ' . implode(', ', $assignments) . ' WHERE id = ?');
            $stmt->execute($params);

            if ($statusChangedToInactive) {
                write_audit_log(
                    $user,
                    'student_soft_delete',
                    'student',
                    $id,
                    student_audit_label($student),
                    student_audit_metadata($student) + ['source' => 'student_update'],
                    $pdo
                );
            }

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => 'Student record updated successfully.',
            'data' => fetch_student($id),
        ]);
    }

    if ($method === 'POST' && $action === 'soft_delete') {
        require_permission($user, 'soft_delete', 'You do not have permission to delete students.');

        $id = (int)(request_data()['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("UPDATE students SET status = 'inactive', deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            write_audit_log(
                $user,
                'student_soft_delete',
                'student',
                $id,
                student_audit_label($student),
                student_audit_metadata($student) + ['source' => 'student_soft_delete'],
                $pdo
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => $student['first_name'] . ' ' . $student['last_name'] . ' has been deactivated.',
            'data' => fetch_student($id),
        ]);
    }

    if ($method === 'POST' && $action === 'hard_delete') {
        require_permission($user, 'hard_delete', 'Only admins can permanently delete records.');

        $id = (int)(request_data()['id'] ?? 0);
        $student = $id > 0 ? fetch_student($id) : null;

        if (!$student) {
            error_response('Student record not found.', 404);
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $stmt->execute([$id]);
            write_audit_log(
                $user,
                'student_hard_delete',
                'student',
                $id,
                student_audit_label($student),
                student_audit_metadata($student),
                $pdo
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => $student['first_name'] . ' ' . $student['last_name'] . ' has been permanently deleted.',
        ]);
    }

    error_response('Unsupported student action.', 404);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        error_response('Student ID or email already exists.');
    }

    error_response('Student request failed.', 500);
} catch (Throwable $e) {
    error_response('Student request failed.', 500);
}
