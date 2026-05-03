<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/email.php';
require_once __DIR__ . '/auth_email.php';
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/webauthn.php';

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
