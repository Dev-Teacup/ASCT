<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function fetch_user(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function validate_user_payload(array $data, bool $isCreate): array
{
    $payload = [
        'name' => trim((string)($data['name'] ?? '')),
        'email' => trim((string)($data['email'] ?? '')),
        'password' => (string)($data['password'] ?? ''),
        'role' => trim((string)($data['role'] ?? '')),
    ];

    if ($payload['name'] === '' || $payload['email'] === '' || $payload['role'] === '') {
        error_response('Please fill in all required fields.');
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        error_response('Please enter a valid email address.');
    }

    if (!in_array($payload['role'], ['admin', 'teacher', 'student'], true)) {
        error_response('Invalid user role.');
    }

    if ($isCreate && $payload['password'] === '') {
        error_response('Password is required for new users.');
    }

    return $payload;
}

function student_approval_payload(array $data, array $pendingUser): array
{
    $nameParts = preg_split('/\s+/', trim((string)$pendingUser['name'])) ?: [];
    $firstName = (string)($nameParts[0] ?? '');
    $lastName = trim(implode(' ', array_slice($nameParts, 1)));

    $payload = [
        'student_id' => trim((string)($data['student_id'] ?? '')),
        'first_name' => trim((string)($data['first_name'] ?? $firstName)),
        'last_name' => trim((string)($data['last_name'] ?? $lastName)),
        'email' => trim((string)$pendingUser['email']),
        'phone' => trim((string)($data['phone'] ?? '')),
        'address' => trim((string)($data['address'] ?? '')),
        'course' => trim((string)($data['course'] ?? '')),
        'year_level' => (int)($data['year_level'] ?? 0),
        'birthdate' => trim((string)($data['birthdate'] ?? '')),
    ];

    foreach (['student_id', 'first_name', 'last_name', 'email', 'phone', 'course', 'birthdate'] as $field) {
        if ($payload[$field] === '') {
            error_response('Please complete the student profile before approval.');
        }
    }

    if ($payload['year_level'] < 1) {
        error_response('Please select a valid year level.');
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        error_response('Please enter a valid email address.');
    }

    return $payload;
}

function link_matching_student(int $userId, string $email, string $role): void
{
    if ($role !== 'student') {
        return;
    }

    $stmt = db()->prepare('UPDATE students SET user_id = ? WHERE email = ? AND user_id IS NULL');
    $stmt->execute([$userId, $email]);
}

function require_pending_student_user(int $id): array
{
    $pendingUser = $id > 0 ? fetch_user($id) : null;

    if (!$pendingUser) {
        error_response('User not found.', 404);
    }

    if (($pendingUser['role'] ?? '') !== 'student' || user_account_status($pendingUser) !== 'pending') {
        error_response('Only pending student signups can use this action.');
    }

    return $pendingUser;
}

function fetch_student_by_identity(string $studentId, string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM students WHERE student_id = ? OR email = ? ORDER BY id');
    $stmt->execute([$studentId, $email]);
    $matches = $stmt->fetchAll();

    if (count($matches) > 1) {
        error_response('Student ID or email already belongs to another record.');
    }

    return $matches[0] ?? null;
}

function user_audit_label(array $targetUser): string
{
    return (string)$targetUser['name'];
}

function user_audit_metadata(array $targetUser): array
{
    return [
        'email' => $targetUser['email'],
        'role' => $targetUser['role'],
        'status' => user_account_status($targetUser),
        'requested_student_id' => $targetUser['requested_student_id'],
        'created_at' => $targetUser['created_at'],
    ];
}

try {
    $user = require_user();
    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'list') {
        $stmt = db()->query('SELECT * FROM users ORDER BY id');
        $users = array_map('sanitize_user', $stmt->fetchAll());

        json_response([
            'success' => true,
            'data' => $users,
        ]);
    }

    if ($method === 'POST' && $action === 'create') {
        require_permission($user, 'manage_users', 'Admin access required.');

        $payload = validate_user_payload(request_data(), true);
        $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
        $stmt->execute([
            $payload['name'],
            $payload['email'],
            password_hash($payload['password'], PASSWORD_DEFAULT),
            $payload['role'],
        ]);

        $created = fetch_user((int)db()->lastInsertId());
        link_matching_student((int)$created['id'], $created['email'], $created['role']);

        json_response([
            'success' => true,
            'message' => 'New user account created.',
            'data' => sanitize_user($created),
        ]);
    }

    if ($method === 'POST' && $action === 'update') {
        require_permission($user, 'manage_users', 'Admin access required.');

        $data = request_data();
        $id = (int)($data['id'] ?? 0);
        $existing = $id > 0 ? fetch_user($id) : null;

        if (!$existing) {
            error_response('User not found.', 404);
        }

        $payload = validate_user_payload($data, false);
        $params = [$payload['name'], $payload['email'], $payload['role']];
        $passwordSql = '';

        if ($payload['password'] !== '') {
            $passwordSql = ', password_hash = ?';
            $params[] = password_hash($payload['password'], PASSWORD_DEFAULT);
        }

        $params[] = $id;
        $stmt = db()->prepare("UPDATE users SET name = ?, email = ?, role = ?{$passwordSql} WHERE id = ?");
        $stmt->execute($params);

        $updated = fetch_user($id);
        link_matching_student((int)$updated['id'], $updated['email'], $updated['role']);

        json_response([
            'success' => true,
            'message' => 'User account updated.',
            'data' => sanitize_user($updated),
        ]);
    }

    if ($method === 'POST' && $action === 'approve_student') {
        require_permission($user, 'manage_users', 'Admin access required.');

        $data = request_data();
        $id = (int)($data['user_id'] ?? $data['id'] ?? 0);
        $pendingUser = require_pending_student_user($id);
        $payload = student_approval_payload($data, $pendingUser);
        $student = fetch_student_by_identity($payload['student_id'], $payload['email']);

        if ($student) {
            $linkedUserId = $student['user_id'] === null ? null : (int)$student['user_id'];
            if ($linkedUserId !== null && $linkedUserId !== (int)$pendingUser['id']) {
                error_response('This student record is already linked to another account.');
            }
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            if ($student) {
                $stmt = $pdo->prepare(
                    "UPDATE students
                     SET user_id = ?, student_id = ?, first_name = ?, last_name = ?, email = ?, phone = ?, address = ?, course = ?, year_level = ?, birthdate = ?, status = 'active', deleted_at = NULL
                     WHERE id = ?"
                );
                $stmt->execute([
                    (int)$pendingUser['id'],
                    $payload['student_id'],
                    $payload['first_name'],
                    $payload['last_name'],
                    $payload['email'],
                    $payload['phone'],
                    $payload['address'],
                    $payload['course'],
                    $payload['year_level'],
                    $payload['birthdate'],
                    (int)$student['id'],
                ]);
                $studentId = (int)$student['id'];
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO students (user_id, student_id, first_name, last_name, email, phone, address, course, year_level, birthdate, status, deleted_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NULL)"
                );
                $stmt->execute([
                    (int)$pendingUser['id'],
                    $payload['student_id'],
                    $payload['first_name'],
                    $payload['last_name'],
                    $payload['email'],
                    $payload['phone'],
                    $payload['address'],
                    $payload['course'],
                    $payload['year_level'],
                    $payload['birthdate'],
                ]);
                $studentId = (int)$pdo->lastInsertId();
            }

            $stmt = $pdo->prepare("UPDATE users SET status = 'active', requested_student_id = NULL WHERE id = ?");
            $stmt->execute([(int)$pendingUser['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => 'Student account approved.',
            'data' => [
                'user' => sanitize_user(fetch_user((int)$pendingUser['id'])),
                'student' => fetch_student($studentId),
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'reject_student') {
        require_permission($user, 'manage_users', 'Admin access required.');

        $id = (int)(request_data()['user_id'] ?? request_data()['id'] ?? 0);
        $pendingUser = require_pending_student_user($id);

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([(int)$pendingUser['id']]);
            write_audit_log(
                $user,
                'student_signup_reject',
                'user',
                (int)$pendingUser['id'],
                user_audit_label($pendingUser),
                user_audit_metadata($pendingUser),
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
            'message' => 'Student signup request rejected.',
        ]);
    }

    if ($method === 'POST' && $action === 'delete') {
        require_permission($user, 'manage_users', 'Admin access required.');

        $id = (int)(request_data()['id'] ?? 0);
        $existing = $id > 0 ? fetch_user($id) : null;

        if (!$existing) {
            error_response('User not found.', 404);
        }

        if ($id === (int)$user['id']) {
            error_response('You cannot delete your own account.');
        }

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$id]);
            write_audit_log(
                $user,
                'user_delete',
                'user',
                $id,
                user_audit_label($existing),
                user_audit_metadata($existing),
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
            'message' => 'User account deleted.',
        ]);
    }

    error_response('Unsupported user action.', 404);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        error_response('Email or student ID already exists.');
    }

    error_response('User request failed.', 500);
} catch (Throwable $e) {
    error_response('User request failed.', 500);
}
