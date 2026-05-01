<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/webauthn.php';

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

function send_login_code_email(array $user, string $code, string $idempotencyKey): array
{
    $appName = ASCT_APP_NAME;
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = $appName . ' login verification code';
    $html = asct_branded_email_html(
        'Your login verification code',
        'Use this 6-digit code to finish signing in to ' . $appName . '.',
        asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Hello <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">' . $safeName . '</strong>,</p>')
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Use this verification code to finish signing in to ' . $safeAppName . '.</p>')
        . '<div class="asct-email-code-box" style="margin:26px 0;padding:24px 18px;background:#0b0c10;background-color:#0b0c10;background-image:linear-gradient(#0b0c10,#0b0c10);border:1px solid #303744;border-radius:14px;text-align:center;color-scheme:light;">'
        . '<div class="asct-email-code-label" style="margin:0 0 10px;color:#ff8a5c;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #ff8a5c;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Verification code</div>'
        . asct_gmail_blend_html('<div class="asct-email-code" style="color:#ffffff;-webkit-text-fill-color:#ffffff;font-family:\'Courier New\',Courier,monospace;font-size:36px;line-height:42px;font-weight:800;letter-spacing:8px;">' . $safeCode . '</div>')
        . '</div>'
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">This code expires in <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">10 minutes</strong>.</p>')
        . '<div class="asct-email-warning" style="margin:0;padding:14px 16px;background:#2a2119;background-color:#2a2119;background-image:linear-gradient(#2a2119,#2a2119);border-left:4px solid #ff5a1f;border-radius:8px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:14px;line-height:22px;color-scheme:light;">'
        . asct_gmail_blend_html('<span style="color:#ffffff;-webkit-text-fill-color:#ffffff;">If you did not try to sign in, you can safely ignore this email.</span>')
        . '</div>',
        'For your security, ASCT will never ask you to share this code.'
    );
    $text = "Hello {$user['name']},\n\n"
        . "Use this verification code to finish signing in to {$appName}: {$code}\n\n"
        . "This code expires in 10 minutes. If you did not try to sign in, you can ignore this email.";

    return send_resend_email((string)$user['email'], $subject, $html, $text, $idempotencyKey);
}

function send_signup_confirmation_email(array $signup, string $code, string $idempotencyKey): array
{
    $appName = ASCT_APP_NAME;
    $safeAppName = htmlspecialchars($appName, ENT_QUOTES, 'UTF-8');
    $safeName = htmlspecialchars((string)$signup['name'], ENT_QUOTES, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
    $subject = $appName . ' registration confirmation code';
    $html = asct_branded_email_html(
        'Confirm your registration email',
        'Use this 6-digit code to confirm your email address for ' . $appName . '.',
        asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Hello <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">' . $safeName . '</strong>,</p>')
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">Use this confirmation code to verify your email address and submit your student account request to ' . $safeAppName . '.</p>')
        . '<div class="asct-email-code-box" style="margin:26px 0;padding:24px 18px;background:#0b0c10;background-color:#0b0c10;background-image:linear-gradient(#0b0c10,#0b0c10);border:1px solid #303744;border-radius:14px;text-align:center;color-scheme:light;">'
        . '<div class="asct-email-code-label" style="margin:0 0 10px;color:#ff8a5c;-webkit-text-fill-color:transparent;text-shadow:0 0 0 #ff8a5c;font-size:12px;font-weight:700;letter-spacing:2px;text-transform:uppercase;">Confirmation code</div>'
        . asct_gmail_blend_html('<div class="asct-email-code" style="color:#ffffff;-webkit-text-fill-color:#ffffff;font-family:\'Courier New\',Courier,monospace;font-size:36px;line-height:42px;font-weight:800;letter-spacing:8px;">' . $safeCode . '</div>')
        . '</div>'
        . asct_gmail_blend_html('<p class="asct-email-copy" style="margin:0 0 16px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:16px;line-height:26px;">This code expires in <strong class="asct-email-title" style="color:#ffffff;-webkit-text-fill-color:#ffffff;">10 minutes</strong>.</p>')
        . '<div class="asct-email-warning" style="margin:0;padding:14px 16px;background:#2a2119;background-color:#2a2119;background-image:linear-gradient(#2a2119,#2a2119);border-left:4px solid #ff5a1f;border-radius:8px;color:#ffffff;-webkit-text-fill-color:#ffffff;font-size:14px;line-height:22px;color-scheme:light;">'
        . asct_gmail_blend_html('<span style="color:#ffffff;-webkit-text-fill-color:#ffffff;">If you did not request this account, you can safely ignore this email.</span>')
        . '</div>',
        'Only confirmed emails are sent to the admin approval queue.'
    );
    $text = "Hello {$signup['name']},\n\n"
        . "Use this confirmation code to verify your email address and submit your student account request to {$appName}: {$code}\n\n"
        . "This code expires in 10 minutes. If you did not request this account, you can ignore this email.";

    return send_resend_email((string)$signup['email'], $subject, $html, $text, $idempotencyKey);
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

try {
    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET' && $action === 'session') {
        $user = current_user();
        json_response([
            'success' => true,
            'data' => [
                'user' => $user ? sanitize_user($user) : null,
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'passkey_list') {
        $user = require_user();
        json_response([
            'success' => true,
            'data' => webauthn_sanitize_passkeys(webauthn_fetch_user_passkeys((int)$user['id'])),
        ]);
    }

    if ($method === 'POST' && $action === 'passkey_register_options') {
        $user = require_user();
        $label = (string)(request_data()['label'] ?? '');

        json_response([
            'success' => true,
            'data' => webauthn_registration_options($user, $label),
        ]);
    }

    if ($method === 'POST' && $action === 'passkey_register_verify') {
        $user = require_user();
        $passkeys = webauthn_verify_registration(request_data(), $user);

        json_response([
            'success' => true,
            'message' => 'Passkey added.',
            'data' => [
                'passkeys' => $passkeys,
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'passkey_login_options') {
        $email = trim((string)(request_data()['email'] ?? ''));

        if ($email === '') {
            error_response('Please enter your email address.');
        }

        json_response([
            'success' => true,
            'data' => webauthn_login_options($email),
        ]);
    }

    if ($method === 'POST' && $action === 'passkey_login_verify') {
        $user = webauthn_verify_login(request_data());

        json_response([
            'success' => true,
            'data' => [
                'user' => sanitize_user($user),
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'signup') {
        $payload = validate_student_signup_payload(request_data());
        ensure_student_signup_available($payload['email']);
        require_signup_confirmation_request_available($payload['email']);

        $challenge = create_signup_challenge($payload);
        if (!$challenge['success']) {
            error_response($challenge['message'], 503);
        }

        json_response([
            'success' => true,
            'message' => 'A confirmation code was sent to your email.',
            'data' => [
                'requires_email_confirmation' => true,
                'challenge_token' => $challenge['challenge_token'],
                'email' => $payload['email'],
                'resend_available_in' => $challenge['resend_available_in'],
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'verify_signup') {
        $data = request_data();
        $token = trim((string)($data['challenge_token'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($token === '' || !preg_match('/^\d{6}$/', $code)) {
            error_response('Please enter the 6-digit confirmation code.');
        }

        $challenge = require_open_signup_challenge($token);

        if (!password_verify($code, $challenge['code_hash'])) {
            $attempts = (int)$challenge['failed_attempts'] + 1;
            $stmt = db()->prepare('UPDATE student_signup_email_challenges SET failed_attempts = ?, consumed_at = CASE WHEN ? >= ? THEN NOW() ELSE consumed_at END WHERE id = ?');
            $stmt->execute([$attempts, $attempts, SIGNUP_EMAIL_MAX_ATTEMPTS, (int)$challenge['id']]);

            if ($attempts >= SIGNUP_EMAIL_MAX_ATTEMPTS) {
                error_response('Too many incorrect codes. Please restart signup.', 429);
            }

            error_response('Confirmation code is incorrect.', 401);
        }

        ensure_student_signup_available((string)$challenge['email']);

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password_hash, role, status, requested_student_id)
                 VALUES (?, ?, ?, 'student', 'pending', NULL)"
            );
            $stmt->execute([
                $challenge['name'],
                $challenge['email'],
                $challenge['password_hash'],
            ]);

            $stmt = $pdo->prepare('UPDATE student_signup_email_challenges SET consumed_at = NOW() WHERE id = ?');
            $stmt->execute([(int)$challenge['id']]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        json_response([
            'success' => true,
            'message' => 'Email confirmed. Your account request is waiting for admin approval.',
        ]);
    }

    if ($method === 'POST' && $action === 'resend_signup_code') {
        $token = trim((string)(request_data()['challenge_token'] ?? ''));
        $challenge = require_open_signup_challenge($token);
        $availableAt = strtotime((string)$challenge['resend_available_at']);

        if ($availableAt > time()) {
            $wait = max(1, $availableAt - time());
            error_response('Please wait ' . $wait . ' seconds before requesting another code.', 429);
        }

        $code = issue_login_code();
        $times = signup_challenge_times();
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE student_signup_email_challenges
                 SET code_hash = ?, failed_attempts = 0, expires_at = ?, resend_available_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                password_hash($code, PASSWORD_DEFAULT),
                $times['expires_at'],
                $times['resend_available_at'],
                (int)$challenge['id'],
            ]);

            $sendResult = send_signup_confirmation_email($challenge, $code, 'signup-resend-' . challenge_token_hash($token) . '-' . time());
            if (!$sendResult['success']) {
                $pdo->rollBack();
                error_response($sendResult['message'], 503);
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
            'message' => 'A new confirmation code was sent.',
            'data' => [
                'resend_available_in' => SIGNUP_EMAIL_RESEND_SECONDS,
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'passkey_delete') {
        $user = require_user();
        $id = (int)(request_data()['id'] ?? 0);

        if ($id <= 0) {
            error_response('Passkey was not found.');
        }

        $stmt = db()->prepare('DELETE FROM user_passkeys WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, (int)$user['id']]);

        json_response([
            'success' => true,
            'message' => 'Passkey deleted.',
            'data' => [
                'passkeys' => webauthn_sanitize_passkeys(webauthn_fetch_user_passkeys((int)$user['id'])),
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'login') {
        $data = request_data();
        $email = trim((string)($data['email'] ?? ''));
        $password = (string)($data['password'] ?? '');

        if ($email === '' || $password === '') {
            error_response('Please enter both email and password.');
        }

        enforce_password_login_rate_limits($email);

        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user) {
                $pdo->commit();
                error_response('No account found with this email address.', 401);
            }

            $remainingSeconds = account_lock_remaining_seconds($user);
            if ($remainingSeconds > 0) {
                $pdo->commit();
                error_response(account_lock_message($remainingSeconds), 423);
            }

            if (trim((string)($user['locked_until'] ?? '')) !== '') {
                reset_account_login_failures($pdo, (int)$user['id']);
                $user['failed_login_attempts'] = 0;
                $user['locked_until'] = null;
            }

            if (!password_verify($password, $user['password_hash'])) {
                $failure = record_failed_password_attempt($pdo, $user);
                $pdo->commit();

                if ($failure['locked']) {
                    error_response(account_lock_message((int)$failure['remaining_seconds']), 423);
                }

                error_response('Incorrect password. Access denied.', 401);
            }

            reset_account_login_failures($pdo, (int)$user['id']);
            $user['failed_login_attempts'] = 0;
            $user['locked_until'] = null;
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }

        require_active_account($user);

        $challenge = create_login_challenge($user);
        if (!$challenge['success']) {
            error_response($challenge['message'], 503);
        }

        json_response([
            'success' => true,
            'data' => [
                'requires_otp' => true,
                'challenge_token' => $challenge['challenge_token'],
                'email' => $user['email'],
                'resend_available_in' => $challenge['resend_available_in'],
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'verify_otp') {
        $data = request_data();
        $token = trim((string)($data['challenge_token'] ?? ''));
        $code = trim((string)($data['code'] ?? ''));

        if ($token === '' || !preg_match('/^\d{6}$/', $code)) {
            error_response('Please enter the 6-digit verification code.');
        }

        $challenge = require_open_challenge($token);

        if (!password_verify($code, $challenge['code_hash'])) {
            $attempts = (int)$challenge['failed_attempts'] + 1;
            $stmt = db()->prepare('UPDATE login_email_challenges SET failed_attempts = ?, consumed_at = CASE WHEN ? >= ? THEN NOW() ELSE consumed_at END WHERE id = ?');
            $stmt->execute([$attempts, $attempts, LOGIN_OTP_MAX_ATTEMPTS, (int)$challenge['id']]);

            if ($attempts >= LOGIN_OTP_MAX_ATTEMPTS) {
                error_response('Too many incorrect codes. Please sign in again.', 429);
            }

            error_response('Verification code is incorrect.', 401);
        }

        $user = challenge_user($challenge);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];

        $stmt = db()->prepare('UPDATE login_email_challenges SET consumed_at = NOW() WHERE id = ?');
        $stmt->execute([(int)$challenge['id']]);

        json_response([
            'success' => true,
            'data' => [
                'user' => sanitize_user($user),
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'resend_otp') {
        $token = trim((string)(request_data()['challenge_token'] ?? ''));
        $challenge = require_open_challenge($token);
        $availableAt = strtotime((string)$challenge['resend_available_at']);

        if ($availableAt > time()) {
            $wait = max(1, $availableAt - time());
            error_response('Please wait ' . $wait . ' seconds before requesting another code.', 429);
        }

        $code = issue_login_code();
        $times = login_challenge_times();
        $pdo = db();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE login_email_challenges
                 SET code_hash = ?, failed_attempts = 0, expires_at = ?, resend_available_at = ?
                 WHERE id = ?'
            );
            $stmt->execute([
                password_hash($code, PASSWORD_DEFAULT),
                $times['expires_at'],
                $times['resend_available_at'],
                (int)$challenge['id'],
            ]);

            $sendResult = send_login_code_email(challenge_user($challenge), $code, 'login-resend-' . challenge_token_hash($token) . '-' . time());
            if (!$sendResult['success']) {
                $pdo->rollBack();
                error_response($sendResult['message'], 503);
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
            'message' => 'A new verification code was sent.',
            'data' => [
                'resend_available_in' => LOGIN_OTP_RESEND_SECONDS,
            ],
        ]);
    }

    if ($method === 'POST' && $action === 'logout') {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
        }

        session_destroy();
        json_response(['success' => true]);
    }

    error_response('Unsupported authentication action.', 404);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        error_response('An account with this email already exists.');
    }

    log_server_exception($e, 'Authentication database request failed');
    error_response('Authentication request failed.', 500);
} catch (Throwable $e) {
    log_server_exception($e, 'Authentication request failed');
    error_response('Authentication request failed.', 500);
}
