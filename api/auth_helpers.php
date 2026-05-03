<?php
declare(strict_types=1);

const LOGIN_OTP_TTL_SECONDS = 600;
const LOGIN_OTP_MAX_ATTEMPTS = 5;
const LOGIN_OTP_RESEND_SECONDS = 60;
const SIGNUP_EMAIL_TTL_SECONDS = 600;
const SIGNUP_EMAIL_MAX_ATTEMPTS = 5;
const SIGNUP_EMAIL_RESEND_SECONDS = 60;
const STUDENT_SIGNUP_MIN_PASSWORD_LENGTH = 8;
const PASSWORD_LOGIN_RATE_LIMIT_WINDOW_SECONDS = 600;
const PASSWORD_LOGIN_RATE_LIMIT_EMAIL_MAX_ATTEMPTS = 10;
const PASSWORD_LOGIN_RATE_LIMIT_IP_MAX_ATTEMPTS = 40;
const PASSWORD_LOGIN_RATE_LIMIT_BLOCK_SECONDS = 600;

function challenge_token_hash(string $token): string
{
    return hash('sha256', $token);
}

function issue_login_code(): string
{
    return (string)random_int(100000, 999999);
}

function login_rate_limit_timestamp(int $timestamp): string
{
    return date('Y-m-d H:i:s', $timestamp);
}

function login_client_ip(): string
{
    $ip = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip === '' ? 'unknown' : $ip;
}

function login_rate_limit_key(string $scope, string $subject): string
{
    return hash('sha256', $scope . "\n" . $subject);
}

function login_wait_message(string $prefix, int $seconds): string
{
    if ($seconds <= 60) {
        return $prefix . ' Please wait less than 1 minute before trying again.';
    }

    $minutes = (int)ceil($seconds / 60);
    return $prefix . ' Please wait ' . $minutes . ' minutes before trying again.';
}

function prune_login_rate_limits(PDO $pdo): void
{
    if (random_int(1, 100) !== 1) {
        return;
    }

    $pdo->exec(
        "DELETE FROM login_rate_limits
         WHERE updated_at < DATE_SUB(NOW(), INTERVAL 1 DAY)
           AND (blocked_until IS NULL OR blocked_until < NOW())"
    );
}

function hit_login_rate_limit(string $scope, string $subject, int $maxAttempts, int $windowSeconds, int $blockSeconds): void
{
    $pdo = db();
    prune_login_rate_limits($pdo);

    $now = time();
    $nowDate = login_rate_limit_timestamp($now);
    $key = login_rate_limit_key($scope, $subject);
    $shouldBlock = false;
    $waitSeconds = 0;

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO login_rate_limits (scope, rate_limit_key, attempts, window_started_at)
             VALUES (?, ?, 0, ?)'
        );
        $stmt->execute([$scope, $key, $nowDate]);

        $stmt = $pdo->prepare(
            'SELECT attempts, window_started_at, blocked_until
             FROM login_rate_limits
             WHERE scope = ? AND rate_limit_key = ?
             LIMIT 1
             FOR UPDATE'
        );
        $stmt->execute([$scope, $key]);
        $limit = $stmt->fetch();

        if (!$limit) {
            throw new RuntimeException('Login rate limit row was not created.');
        }

        $blockedUntil = trim((string)($limit['blocked_until'] ?? ''));
        $blockedUntilTs = $blockedUntil === '' ? false : strtotime($blockedUntil);

        if ($blockedUntilTs !== false && $blockedUntilTs > $now) {
            $shouldBlock = true;
            $waitSeconds = max(1, $blockedUntilTs - $now);
        } else {
            $windowStartedAt = strtotime((string)$limit['window_started_at']);
            $windowExpired = $windowStartedAt === false || ($windowStartedAt + $windowSeconds) <= $now || $blockedUntil !== '';

            if ($windowExpired) {
                $stmt = $pdo->prepare(
                    'UPDATE login_rate_limits
                     SET attempts = 1, window_started_at = ?, blocked_until = NULL
                     WHERE scope = ? AND rate_limit_key = ?'
                );
                $stmt->execute([$nowDate, $scope, $key]);
            } else {
                $attempts = (int)$limit['attempts'] + 1;
                if ($attempts > $maxAttempts) {
                    $shouldBlock = true;
                    $waitSeconds = $blockSeconds;
                    $stmt = $pdo->prepare(
                        'UPDATE login_rate_limits
                         SET attempts = ?, blocked_until = ?
                         WHERE scope = ? AND rate_limit_key = ?'
                    );
                    $stmt->execute([$attempts, login_rate_limit_timestamp($now + $blockSeconds), $scope, $key]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE login_rate_limits
                         SET attempts = ?
                         WHERE scope = ? AND rate_limit_key = ?'
                    );
                    $stmt->execute([$attempts, $scope, $key]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }

    if ($shouldBlock) {
        error_response(login_wait_message('Too many login attempts.', $waitSeconds), 429);
    }
}

function enforce_password_login_rate_limits(string $email): void
{
    $ip = login_client_ip();
    $normalizedEmail = strtolower($email);

    hit_login_rate_limit(
        'password_login_ip',
        $ip,
        PASSWORD_LOGIN_RATE_LIMIT_IP_MAX_ATTEMPTS,
        PASSWORD_LOGIN_RATE_LIMIT_WINDOW_SECONDS,
        PASSWORD_LOGIN_RATE_LIMIT_BLOCK_SECONDS
    );

    hit_login_rate_limit(
        'password_login_email_ip',
        $normalizedEmail . '|' . $ip,
        PASSWORD_LOGIN_RATE_LIMIT_EMAIL_MAX_ATTEMPTS,
        PASSWORD_LOGIN_RATE_LIMIT_WINDOW_SECONDS,
        PASSWORD_LOGIN_RATE_LIMIT_BLOCK_SECONDS
    );
}

function consume_open_login_challenges(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('UPDATE login_email_challenges SET consumed_at = NOW() WHERE user_id = ? AND consumed_at IS NULL');
    $stmt->execute([$userId]);
}

function record_failed_password_attempt(PDO $pdo, array $user): array
{
    $attempts = min(255, (int)($user['failed_login_attempts'] ?? 0) + 1);

    if ($attempts >= PASSWORD_LOGIN_LOCK_THRESHOLD) {
        $lockedUntil = login_rate_limit_timestamp(time() + PASSWORD_LOGIN_LOCK_SECONDS);
        $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE id = ?');
        $stmt->execute([$attempts, $lockedUntil, (int)$user['id']]);
        consume_open_login_challenges($pdo, (int)$user['id']);

        return [
            'locked' => true,
            'remaining_seconds' => PASSWORD_LOGIN_LOCK_SECONDS,
        ];
    }

    $stmt = $pdo->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = NULL WHERE id = ?');
    $stmt->execute([$attempts, (int)$user['id']]);

    return [
        'locked' => false,
        'remaining_seconds' => 0,
    ];
}

function login_challenge_times(): array
{
    $now = time();

    return [
        'expires_at' => date('Y-m-d H:i:s', $now + LOGIN_OTP_TTL_SECONDS),
        'resend_available_at' => date('Y-m-d H:i:s', $now + LOGIN_OTP_RESEND_SECONDS),
    ];
}

function signup_challenge_times(): array
{
    $now = time();

    return [
        'expires_at' => date('Y-m-d H:i:s', $now + SIGNUP_EMAIL_TTL_SECONDS),
        'resend_available_at' => date('Y-m-d H:i:s', $now + SIGNUP_EMAIL_RESEND_SECONDS),
    ];
}

function validate_student_signup_payload(array $data): array
{
    $payload = [
        'name' => trim((string)($data['name'] ?? '')),
        'email' => trim((string)($data['email'] ?? '')),
        'password' => (string)($data['password'] ?? ''),
        'password_confirm' => (string)($data['password_confirm'] ?? ''),
    ];

    if ($payload['name'] === '' || $payload['email'] === '' || $payload['password'] === '' || $payload['password_confirm'] === '') {
        error_response('Please fill in all required signup fields.');
    }

    if (!filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
        error_response('Please enter a valid email address.');
    }

    if (strlen($payload['password']) < STUDENT_SIGNUP_MIN_PASSWORD_LENGTH) {
        error_response('Password must be at least 8 characters.');
    }

    if ($payload['password_confirm'] !== '' && $payload['password'] !== $payload['password_confirm']) {
        error_response('Passwords do not match.');
    }

    return $payload;
}

function ensure_student_signup_available(string $email): void
{
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    if ($stmt->fetchColumn()) {
        error_response('An account with this email already exists.');
    }

    $stmt = db()->prepare('SELECT user_id FROM students WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $linkedUserId = $stmt->fetchColumn();
    if ($linkedUserId !== false && $linkedUserId !== null) {
        error_response('This student email is already linked to an account.');
    }
}

function require_signup_confirmation_request_available(string $email): void
{
    $stmt = db()->prepare(
        'SELECT resend_available_at
         FROM student_signup_email_challenges
         WHERE email = ? AND consumed_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $availableAt = $stmt->fetchColumn();

    if ($availableAt !== false && strtotime((string)$availableAt) > time()) {
        $wait = max(1, strtotime((string)$availableAt) - time());
        error_response('Please wait ' . $wait . ' seconds before requesting another confirmation code.', 429);
    }
}

function create_login_challenge(array $user): array
{
    $token = bin2hex(random_bytes(32));
    $code = issue_login_code();
    $times = login_challenge_times();
    $pdo = db();

    $pdo->beginTransaction();

    try {
        consume_open_login_challenges($pdo, (int)$user['id']);

        $stmt = $pdo->prepare(
            'INSERT INTO login_email_challenges (user_id, challenge_token_hash, code_hash, expires_at, resend_available_at)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$user['id'],
            challenge_token_hash($token),
            password_hash($code, PASSWORD_DEFAULT),
            $times['expires_at'],
            $times['resend_available_at'],
        ]);

        $sendResult = send_login_code_email($user, $code, 'login-' . challenge_token_hash($token));
        if (!$sendResult['success']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => $sendResult['message']];
        }

        $pdo->commit();
        return [
            'success' => true,
            'challenge_token' => $token,
            'resend_available_in' => LOGIN_OTP_RESEND_SECONDS,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function create_signup_challenge(array $payload): array
{
    $token = bin2hex(random_bytes(32));
    $code = issue_login_code();
    $times = signup_challenge_times();
    $pdo = db();

    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('UPDATE student_signup_email_challenges SET consumed_at = NOW() WHERE email = ? AND consumed_at IS NULL');
        $stmt->execute([$payload['email']]);

        $stmt = $pdo->prepare(
            'INSERT INTO student_signup_email_challenges (challenge_token_hash, name, email, password_hash, code_hash, expires_at, resend_available_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            challenge_token_hash($token),
            $payload['name'],
            $payload['email'],
            password_hash($payload['password'], PASSWORD_DEFAULT),
            password_hash($code, PASSWORD_DEFAULT),
            $times['expires_at'],
            $times['resend_available_at'],
        ]);

        $sendResult = send_signup_confirmation_email($payload, $code, 'signup-' . challenge_token_hash($token));
        if (!$sendResult['success']) {
            $pdo->rollBack();
            return ['success' => false, 'message' => $sendResult['message']];
        }

        $pdo->commit();
        return [
            'success' => true,
            'challenge_token' => $token,
            'resend_available_in' => SIGNUP_EMAIL_RESEND_SECONDS,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function fetch_signup_challenge(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT *
         FROM student_signup_email_challenges
         WHERE challenge_token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([challenge_token_hash($token)]);
    $challenge = $stmt->fetch();

    return $challenge ?: null;
}

function require_open_signup_challenge(string $token): array
{
    $challenge = fetch_signup_challenge($token);

    if (!$challenge || $challenge['consumed_at'] !== null) {
        error_response('Confirmation code is invalid. Please restart signup.', 401);
    }

    if (strtotime((string)$challenge['expires_at']) < time()) {
        error_response('Confirmation code expired. Please restart signup.', 401);
    }

    if ((int)$challenge['failed_attempts'] >= SIGNUP_EMAIL_MAX_ATTEMPTS) {
        error_response('Too many incorrect codes. Please restart signup.', 429);
    }

    return $challenge;
}

function fetch_login_challenge(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT c.*, u.name, u.email, u.password_hash, u.role, u.status, u.requested_student_id, u.failed_login_attempts, u.locked_until, u.created_at AS user_created_at, u.updated_at AS user_updated_at
         FROM login_email_challenges c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.challenge_token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([challenge_token_hash($token)]);
    $challenge = $stmt->fetch();

    return $challenge ?: null;
}

function challenge_user(array $challenge): array
{
    return [
        'id' => $challenge['user_id'],
        'name' => $challenge['name'],
        'email' => $challenge['email'],
        'password_hash' => $challenge['password_hash'],
        'role' => $challenge['role'],
        'status' => $challenge['status'],
        'requested_student_id' => $challenge['requested_student_id'],
        'failed_login_attempts' => $challenge['failed_login_attempts'] ?? 0,
        'locked_until' => $challenge['locked_until'] ?? null,
        'created_at' => $challenge['user_created_at'],
        'updated_at' => $challenge['user_updated_at'],
    ];
}

function require_open_challenge(string $token): array
{
    $challenge = fetch_login_challenge($token);

    if (!$challenge || $challenge['consumed_at'] !== null) {
        error_response('Verification code is invalid. Please sign in again.', 401);
    }

    if (strtotime((string)$challenge['expires_at']) < time()) {
        error_response('Verification code expired. Please sign in again.', 401);
    }

    if ((int)$challenge['failed_attempts'] >= LOGIN_OTP_MAX_ATTEMPTS) {
        error_response('Too many incorrect codes. Please sign in again.', 429);
    }

    $user = challenge_user($challenge);
    require_active_account($user);
    require_unlocked_account($user);

    return $challenge;
}
