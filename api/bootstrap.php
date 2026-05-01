<?php
declare(strict_types=1);

function is_https_request(): bool
{
    $https = $_SERVER['HTTPS'] ?? '';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';

    return $https !== '' && strtolower((string)$https) !== 'off'
        || strtolower((string)$forwardedProto) === 'https';
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self'; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "img-src 'self' data:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "frame-ancestors 'none'"
    );
}

send_security_headers();

$sessionPath = dirname(__DIR__) . '/storage/sessions';
if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0775, true);
}
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => is_https_request(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_save_path($sessionPath);
session_start();

require_once __DIR__ . '/../config/database.php';

const PASSWORD_LOGIN_LOCK_THRESHOLD = 3;
const PASSWORD_LOGIN_LOCK_SECONDS = 1800;

function refresh_session_cookie_flags(): void
{
    if (headers_sent() || session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
        return;
    }

    $params = session_get_cookie_params();
    $options = [
        'expires' => 0,
        'path' => $params['path'] ?: '/',
        'secure' => (bool)$params['secure'],
        'httponly' => (bool)$params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ];

    if (($params['domain'] ?? '') !== '') {
        $options['domain'] = $params['domain'];
    }

    setcookie(session_name(), session_id(), $options);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf_token(): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? (request_data()['csrf_token'] ?? ''));
    $expected = csrf_token();

    if ($provided === '' || !hash_equals($expected, $provided)) {
        error_response('Security token is invalid. Please refresh and try again.', 403);
    }
}

function is_state_changing_request(): bool
{
    return in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true);
}

refresh_session_cookie_flags();

function request_data(): array
{
    static $data = null;

    if ($data !== null) {
        return $data;
    }

    $raw = file_get_contents('php://input');
    $json = $raw ? json_decode($raw, true) : null;
    $data = is_array($json) ? $json : $_POST;

    return $data;
}

function request_action(): string
{
    $data = request_data();
    return (string)($_GET['action'] ?? $data['action'] ?? '');
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');

    if (!array_key_exists('csrf_token', $payload)) {
        $payload['csrf_token'] = csrf_token();
    }

    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function error_response(string $message, int $status = 400): never
{
    json_response(['success' => false, 'message' => $message], $status);
}

function log_server_exception(Throwable $e, string $context): void
{
    error_log(sprintf(
        '%s: %s [%s] %s in %s:%d',
        $context,
        $e::class,
        (string)$e->getCode(),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
}

function user_profile_picture_version(int $userId): ?string
{
    $dir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'profile_pictures';

    foreach (['jpg', 'png', 'webp'] as $extension) {
        $path = $dir . DIRECTORY_SEPARATOR . 'user_' . $userId . '.' . $extension;
        if (is_file($path)) {
            return (string)(filemtime($path) ?: time());
        }
    }

    return null;
}

function sanitize_user(array $user): array
{
    unset($user['password_hash'], $user['failed_login_attempts'], $user['locked_until']);
    $user['profile_picture_version'] = user_profile_picture_version((int)$user['id']);
    return $user;
}

function user_account_status(array $user): string
{
    return (string)($user['status'] ?? 'active');
}

function require_active_account(array $user): void
{
    if (user_account_status($user) !== 'active') {
        error_response('Your account is waiting for admin approval.', 403);
    }
}

function account_lock_remaining_seconds(array $user): int
{
    $lockedUntil = trim((string)($user['locked_until'] ?? ''));

    if ($lockedUntil === '') {
        return 0;
    }

    $unlockAt = strtotime($lockedUntil);
    if ($unlockAt === false) {
        return 0;
    }

    return max(0, $unlockAt - time());
}

function account_lock_message(int $remainingSeconds): string
{
    if ($remainingSeconds <= 60) {
        return 'Account locked due to too many failed password attempts. Try again in less than 1 minute.';
    }

    $minutes = (int)ceil($remainingSeconds / 60);
    return 'Account locked due to too many failed password attempts. Try again in ' . $minutes . ' minutes.';
}

function reset_account_login_failures(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare(
        'UPDATE users
         SET failed_login_attempts = 0, locked_until = NULL
         WHERE id = ? AND (failed_login_attempts <> 0 OR locked_until IS NOT NULL)'
    );
    $stmt->execute([$userId]);
}

function require_unlocked_account(array $user, ?PDO $pdo = null): array
{
    $remainingSeconds = account_lock_remaining_seconds($user);

    if ($remainingSeconds > 0) {
        error_response(account_lock_message($remainingSeconds), 423);
    }

    if (trim((string)($user['locked_until'] ?? '')) !== '') {
        reset_account_login_failures($pdo ?? db(), (int)$user['id']);
        $user['failed_login_attempts'] = 0;
        $user['locked_until'] = null;
    }

    return $user;
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch();

    return $user ?: null;
}

function require_user(): array
{
    $user = current_user();

    if (!$user) {
        error_response('Authentication required.', 401);
    }

    return $user;
}

function has_permission(array $user, string $permission): bool
{
    $role = $user['role'] ?? '';
    $matrix = [
        'add_student' => ['admin' => true, 'teacher' => false, 'student' => false],
        'view_all_students' => ['admin' => true, 'teacher' => true, 'student' => false],
        'view_own_profile' => ['admin' => true, 'teacher' => true, 'student' => true],
        'edit_students' => ['admin' => true, 'teacher' => true, 'student' => true],
        'soft_delete' => ['admin' => true, 'teacher' => true, 'student' => false],
        'hard_delete' => ['admin' => true, 'teacher' => false, 'student' => false],
        'manage_users' => ['admin' => true, 'teacher' => false, 'student' => false],
        'view_audit_logs' => ['admin' => true, 'teacher' => false, 'student' => false],
    ];

    return (bool)($matrix[$permission][$role] ?? false);
}

function can_edit_student_field(string $role, string $field): bool
{
    if ($role === 'admin') {
        return true;
    }

    if ($role === 'teacher') {
        return in_array($field, ['phone', 'address', 'course', 'year_level', 'status'], true);
    }

    if ($role === 'student') {
        return in_array($field, ['email', 'phone', 'address'], true);
    }

    return false;
}

function require_permission(array $user, string $permission, string $message): void
{
    if (!has_permission($user, $permission)) {
        error_response($message, 403);
    }
}

function fetch_student(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM students WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $student = $stmt->fetch();

    return $student ?: null;
}

function require_student_access(array $user, array $student): void
{
    if (($user['role'] ?? '') !== 'student') {
        return;
    }

    $linkedUserId = $student['user_id'] === null ? null : (int)$student['user_id'];
    $sameLinkedUser = $linkedUserId !== null && $linkedUserId === (int)$user['id'];
    $sameEmail = isset($student['email'], $user['email']) && $student['email'] === $user['email'];

    if (!$sameLinkedUser && !$sameEmail) {
        error_response('You can only access your own student record.', 403);
    }
}

function audit_metadata_json(array $metadata): ?string
{
    if ($metadata === []) {
        return null;
    }

    $json = json_encode($metadata, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Audit metadata could not be encoded.');
    }

    return $json;
}

function write_audit_log(
    array $actor,
    string $action,
    string $targetType,
    ?int $targetId,
    string $targetLabel,
    array $metadata = [],
    ?PDO $pdo = null
): void {
    $connection = $pdo ?? db();
    $stmt = $connection->prepare(
        'INSERT INTO audit_logs (actor_user_id, actor_name, actor_email, actor_role, action, target_type, target_id, target_label, metadata)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$actor['id'],
        (string)$actor['name'],
        (string)$actor['email'],
        (string)$actor['role'],
        $action,
        $targetType,
        $targetId,
        $targetLabel,
        audit_metadata_json($metadata),
    ]);
}

if (is_state_changing_request()) {
    require_csrf_token();
}
