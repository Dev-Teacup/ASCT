<?php
declare(strict_types=1);

const WEBAUTHN_CHALLENGE_TTL_SECONDS = 300;
const WEBAUTHN_USER_PRESENT_FLAG = 0x01;
const WEBAUTHN_USER_VERIFIED_FLAG = 0x04;
const WEBAUTHN_ATTESTED_CREDENTIAL_FLAG = 0x40;

function webauthn_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webauthn_base64url_decode(string $value): ?string
{
    $value = rtrim(trim($value), '=');

    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) {
        return null;
    }

    $padding = strlen($value) % 4;
    if ($padding !== 0) {
        $value .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? null : $decoded;
}

function webauthn_challenge(): string
{
    return webauthn_base64url_encode(random_bytes(32));
}

function webauthn_relying_party(): array
{
    $baseUrl = trim(ASCT_APP_BASE_URL);
    $parts = parse_url($baseUrl);

    if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
        error_response('Passkey configuration is invalid. Check ASCT_APP_BASE_URL.', 500);
    }

    $scheme = strtolower((string)$parts['scheme']);
    $host = strtolower((string)$parts['host']);
    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    $includePort = $port !== null
        && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443));
    $origin = $scheme . '://' . $host . ($includePort ? ':' . $port : '');

    return [
        'id' => $host,
        'name' => ASCT_APP_NAME,
        'origin' => $origin,
    ];
}

function webauthn_user_handle(int $userId): string
{
    return webauthn_base64url_encode('asct-user-' . $userId);
}

function webauthn_session_challenge(string $key): array
{
    $challenge = $_SESSION[$key] ?? null;

    if (!is_array($challenge) || empty($challenge['challenge']) || empty($challenge['expires_at'])) {
        error_response('Passkey challenge is invalid. Please try again.', 401);
    }

    if ((int)$challenge['expires_at'] < time()) {
        unset($_SESSION[$key]);
        error_response('Passkey challenge expired. Please try again.', 401);
    }

    return $challenge;
}

function webauthn_store_session_challenge(string $key, array $data): void
{
    $_SESSION[$key] = array_merge($data, [
        'expires_at' => time() + WEBAUTHN_CHALLENGE_TTL_SECONDS,
    ]);
}

function webauthn_decode_credential_part(array $data, string $field, ?array $parent = null): string
{
    $source = $parent ?? $data;
    $encoded = (string)($source[$field] ?? '');
    $decoded = webauthn_base64url_decode($encoded);

    if ($decoded === null) {
        error_response('Passkey response is invalid.');
    }

    return $decoded;
}

function webauthn_validate_client_data(
    string $clientDataJson,
    string $expectedType,
    string $expectedChallenge,
    string $expectedOrigin
): array {
    $clientData = json_decode($clientDataJson, true);

    if (!is_array($clientData)) {
        error_response('Passkey client data is invalid.');
    }

    if (($clientData['type'] ?? '') !== $expectedType) {
        error_response('Passkey response type is invalid.');
    }

    if (!hash_equals($expectedChallenge, (string)($clientData['challenge'] ?? ''))) {
        error_response('Passkey challenge does not match.', 401);
    }

    if (!hash_equals($expectedOrigin, (string)($clientData['origin'] ?? ''))) {
        error_response('Passkey origin does not match.', 401);
    }

    if (($clientData['crossOrigin'] ?? false) === true) {
        error_response('Cross-origin passkey responses are not accepted.', 401);
    }

    return $clientData;
}

function webauthn_read_uint(string $data): int
{
    $value = 0;
    $length = strlen($data);

    for ($i = 0; $i < $length; $i += 1) {
        $value = ($value << 8) | ord($data[$i]);
    }

    return $value;
}

function webauthn_parse_authenticator_data(string $authData, bool $requireAttestedCredential): array
{
    if (strlen($authData) < 37) {
        error_response('Passkey authenticator data is invalid.');
    }

    $flags = ord($authData[32]);
    $signCount = webauthn_read_uint(substr($authData, 33, 4));
    $parsed = [
        'rp_id_hash' => substr($authData, 0, 32),
        'flags' => $flags,
        'sign_count' => $signCount,
    ];

    if (!$requireAttestedCredential) {
        return $parsed;
    }

    if (($flags & WEBAUTHN_ATTESTED_CREDENTIAL_FLAG) === 0) {
        error_response('Passkey registration did not include credential data.');
    }

    if (strlen($authData) < 55) {
        error_response('Passkey credential data is invalid.');
    }

    $offset = 37;
    $aaguid = substr($authData, $offset, 16);
    $offset += 16;
    $credentialIdLength = webauthn_read_uint(substr($authData, $offset, 2));
    $offset += 2;

    if ($credentialIdLength <= 0 || strlen($authData) < $offset + $credentialIdLength) {
        error_response('Passkey credential id is invalid.');
    }

    $credentialId = substr($authData, $offset, $credentialIdLength);
    $offset += $credentialIdLength;
    $credentialPublicKey = substr($authData, $offset);

    if ($credentialPublicKey === '') {
        error_response('Passkey public key is missing.');
    }

    return array_merge($parsed, [
        'aaguid' => $aaguid,
        'credential_id' => $credentialId,
        'credential_public_key' => $credentialPublicKey,
    ]);
}

function webauthn_validate_authenticator_flags(array $authenticatorData): void
{
    $flags = (int)$authenticatorData['flags'];

    if (($flags & WEBAUTHN_USER_PRESENT_FLAG) === 0) {
        error_response('Passkey user presence is required.', 401);
    }

    if (($flags & WEBAUTHN_USER_VERIFIED_FLAG) === 0) {
        error_response('Passkey user verification is required.', 401);
    }
}

function webauthn_validate_rp_id_hash(array $authenticatorData, string $rpId): void
{
    $expectedHash = hash('sha256', $rpId, true);

    if (!hash_equals($expectedHash, (string)$authenticatorData['rp_id_hash'])) {
        error_response('Passkey relying party does not match.', 401);
    }
}

function webauthn_cbor_length(string $data, int &$offset, int $additional): int
{
    if ($additional <= 23) {
        return $additional;
    }

    $bytes = match ($additional) {
        24 => 1,
        25 => 2,
        26 => 4,
        27 => 8,
        default => 0,
    };

    if ($bytes === 0 || strlen($data) < $offset + $bytes) {
        throw new RuntimeException('Unsupported CBOR length.');
    }

    $value = webauthn_read_uint(substr($data, $offset, $bytes));
    $offset += $bytes;

    return $value;
}

function webauthn_cbor_decode_item(string $data, int &$offset): mixed
{
    if ($offset >= strlen($data)) {
        throw new RuntimeException('Unexpected end of CBOR data.');
    }

    $initial = ord($data[$offset]);
    $offset += 1;
    $major = $initial >> 5;
    $additional = $initial & 0x1f;

    return match ($major) {
        0 => webauthn_cbor_length($data, $offset, $additional),
        1 => -1 - webauthn_cbor_length($data, $offset, $additional),
        2 => webauthn_cbor_read_bytes($data, $offset, $additional),
        3 => webauthn_cbor_read_text($data, $offset, $additional),
        4 => webauthn_cbor_read_array($data, $offset, $additional),
        5 => webauthn_cbor_read_map($data, $offset, $additional),
        6 => webauthn_cbor_decode_tagged($data, $offset, $additional),
        7 => webauthn_cbor_decode_simple($data, $offset, $additional),
        default => throw new RuntimeException('Unsupported CBOR data.'),
    };
}

function webauthn_cbor_read_bytes(string $data, int &$offset, int $additional): string
{
    $length = webauthn_cbor_length($data, $offset, $additional);

    if (strlen($data) < $offset + $length) {
        throw new RuntimeException('Unexpected end of CBOR byte string.');
    }

    $value = substr($data, $offset, $length);
    $offset += $length;

    return $value;
}

function webauthn_cbor_read_text(string $data, int &$offset, int $additional): string
{
    return webauthn_cbor_read_bytes($data, $offset, $additional);
}

function webauthn_cbor_read_array(string $data, int &$offset, int $additional): array
{
    $length = webauthn_cbor_length($data, $offset, $additional);
    $items = [];

    for ($i = 0; $i < $length; $i += 1) {
        $items[] = webauthn_cbor_decode_item($data, $offset);
    }

    return $items;
}

function webauthn_cbor_read_map(string $data, int &$offset, int $additional): array
{
    $length = webauthn_cbor_length($data, $offset, $additional);
    $map = [];

    for ($i = 0; $i < $length; $i += 1) {
        $key = webauthn_cbor_decode_item($data, $offset);
        $value = webauthn_cbor_decode_item($data, $offset);

        if (is_int($key) || is_string($key)) {
            $map[$key] = $value;
        }
    }

    return $map;
}

function webauthn_cbor_decode_tagged(string $data, int &$offset, int $additional): mixed
{
    webauthn_cbor_length($data, $offset, $additional);
    return webauthn_cbor_decode_item($data, $offset);
}

function webauthn_cbor_decode_simple(string $data, int &$offset, int $additional): mixed
{
    return match ($additional) {
        20 => false,
        21 => true,
        22, 23 => null,
        default => throw new RuntimeException('Unsupported CBOR simple value.'),
    };
}

function webauthn_cbor_decode(string $data): mixed
{
    $offset = 0;
    $value = webauthn_cbor_decode_item($data, $offset);

    if ($offset !== strlen($data)) {
        throw new RuntimeException('Trailing CBOR data.');
    }

    return $value;
}

function webauthn_cose_ec2_key(string $coseKey): array
{
    try {
        $key = webauthn_cbor_decode($coseKey);
    } catch (Throwable $e) {
        error_response('Passkey public key is invalid.');
    }

    if (!is_array($key)) {
        error_response('Passkey public key is invalid.');
    }

    $kty = $key[1] ?? null;
    $alg = $key[3] ?? null;
    $crv = $key[-1] ?? null;
    $x = $key[-2] ?? null;
    $y = $key[-3] ?? null;

    if ($kty !== 2 || $alg !== -7 || $crv !== 1 || !is_string($x) || !is_string($y)) {
        error_response('Only ES256 passkeys are supported.');
    }

    if (strlen($x) !== 32 || strlen($y) !== 32) {
        error_response('Passkey public key coordinates are invalid.');
    }

    return ['x' => $x, 'y' => $y];
}

function webauthn_der_length(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }

    $bytes = '';
    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}

function webauthn_der_sequence(string $contents): string
{
    return "\x30" . webauthn_der_length(strlen($contents)) . $contents;
}

function webauthn_der_bit_string(string $contents): string
{
    return "\x03" . webauthn_der_length(strlen($contents) + 1) . "\x00" . $contents;
}

function webauthn_ec2_public_key_pem(string $coseKey): string
{
    $key = webauthn_cose_ec2_key($coseKey);
    $idEcPublicKey = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";
    $prime256v1 = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";
    $algorithm = webauthn_der_sequence($idEcPublicKey . $prime256v1);
    $publicKey = "\x04" . $key['x'] . $key['y'];
    $spki = webauthn_der_sequence($algorithm . webauthn_der_bit_string($publicKey));

    return "-----BEGIN PUBLIC KEY-----\n"
        . chunk_split(base64_encode($spki), 64, "\n")
        . "-----END PUBLIC KEY-----\n";
}

function webauthn_verify_signature(string $coseKey, string $signedData, string $signature): bool
{
    if (!function_exists('openssl_verify')) {
        error_response('Passkey verification requires the PHP OpenSSL extension.', 500);
    }

    $pem = webauthn_ec2_public_key_pem($coseKey);
    return openssl_verify($signedData, $signature, $pem, OPENSSL_ALGO_SHA256) === 1;
}

function webauthn_passkey_label(string $label): string
{
    $label = trim($label);
    if ($label === '') {
        return 'Passkey';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($label, 0, 120);
    }

    return substr($label, 0, 120);
}

function webauthn_fetch_user_passkeys(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, label, created_at, last_used_at
         FROM user_passkeys
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function webauthn_fetch_user_passkey_credentials(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT id, credential_id, label, created_at, last_used_at
         FROM user_passkeys
         WHERE user_id = ?
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([$userId]);

    return $stmt->fetchAll();
}

function webauthn_sanitize_passkeys(array $passkeys): array
{
    return array_map(static function (array $passkey): array {
        return [
            'id' => (int)$passkey['id'],
            'label' => $passkey['label'],
            'created_at' => $passkey['created_at'],
            'last_used_at' => $passkey['last_used_at'],
        ];
    }, $passkeys);
}

function webauthn_registration_options(array $user, string $label): array
{
    $rp = webauthn_relying_party();
    $challenge = webauthn_challenge();
    $credentials = webauthn_fetch_user_passkey_credentials((int)$user['id']);

    webauthn_store_session_challenge('passkey_register_challenge', [
        'challenge' => $challenge,
        'user_id' => (int)$user['id'],
        'label' => webauthn_passkey_label($label),
    ]);

    return [
        'publicKey' => [
            'challenge' => $challenge,
            'rp' => [
                'name' => $rp['name'],
                'id' => $rp['id'],
            ],
            'user' => [
                'id' => webauthn_user_handle((int)$user['id']),
                'name' => $user['email'],
                'displayName' => $user['name'],
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
            ],
            'timeout' => 60000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'preferred',
                'requireResidentKey' => false,
                'userVerification' => 'required',
            ],
            'excludeCredentials' => array_map(static function (array $credential): array {
                return [
                    'type' => 'public-key',
                    'id' => webauthn_base64url_encode((string)$credential['credential_id']),
                ];
            }, $credentials),
        ],
    ];
}

function webauthn_verify_registration(array $data, array $user): array
{
    $sessionChallenge = webauthn_session_challenge('passkey_register_challenge');
    if ((int)$sessionChallenge['user_id'] !== (int)$user['id']) {
        error_response('Passkey registration challenge does not match this session.', 401);
    }

    $response = is_array($data['response'] ?? null) ? $data['response'] : [];
    $rawId = webauthn_decode_credential_part($data, 'rawId');
    $clientDataJson = webauthn_decode_credential_part($data, 'clientDataJSON', $response);
    $attestationObject = webauthn_decode_credential_part($data, 'attestationObject', $response);
    $rp = webauthn_relying_party();

    webauthn_validate_client_data($clientDataJson, 'webauthn.create', (string)$sessionChallenge['challenge'], $rp['origin']);

    try {
        $attestation = webauthn_cbor_decode($attestationObject);
    } catch (Throwable $e) {
        error_response('Passkey attestation is invalid.');
    }

    if (!is_array($attestation) || !isset($attestation['authData']) || !is_string($attestation['authData'])) {
        error_response('Passkey attestation is invalid.');
    }

    $authenticatorData = webauthn_parse_authenticator_data($attestation['authData'], true);
    webauthn_validate_rp_id_hash($authenticatorData, $rp['id']);
    webauthn_validate_authenticator_flags($authenticatorData);

    if (!hash_equals($rawId, (string)$authenticatorData['credential_id'])) {
        error_response('Passkey credential id does not match.');
    }

    webauthn_cose_ec2_key((string)$authenticatorData['credential_public_key']);

    try {
        $stmt = db()->prepare(
            'INSERT INTO user_passkeys (user_id, credential_id, public_key_cose, sign_count, label)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (int)$user['id'],
            (string)$authenticatorData['credential_id'],
            (string)$authenticatorData['credential_public_key'],
            (int)$authenticatorData['sign_count'],
            (string)$sessionChallenge['label'],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            error_response('This passkey is already registered.');
        }

        throw $e;
    }

    unset($_SESSION['passkey_register_challenge']);

    return webauthn_sanitize_passkeys(webauthn_fetch_user_passkeys((int)$user['id']));
}

function webauthn_login_options(string $email): array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        error_response('No account found with this email address.', 401);
    }

    require_active_account($user);
    $user = require_unlocked_account($user);

    $credentials = webauthn_fetch_user_passkey_credentials((int)$user['id']);
    if ($credentials === []) {
        error_response('No passkey is registered for this account. Use password login first.', 401);
    }

    $rp = webauthn_relying_party();
    $challenge = webauthn_challenge();
    webauthn_store_session_challenge('passkey_login_challenge', [
        'challenge' => $challenge,
        'user_id' => (int)$user['id'],
    ]);

    return [
        'publicKey' => [
            'challenge' => $challenge,
            'rpId' => $rp['id'],
            'allowCredentials' => array_map(static function (array $credential): array {
                return [
                    'type' => 'public-key',
                    'id' => webauthn_base64url_encode((string)$credential['credential_id']),
                ];
            }, $credentials),
            'timeout' => 60000,
            'userVerification' => 'required',
        ],
    ];
}

function webauthn_verify_login(array $data): array
{
    $sessionChallenge = webauthn_session_challenge('passkey_login_challenge');
    $response = is_array($data['response'] ?? null) ? $data['response'] : [];
    $rawId = webauthn_decode_credential_part($data, 'rawId');
    $clientDataJson = webauthn_decode_credential_part($data, 'clientDataJSON', $response);
    $authenticatorDataRaw = webauthn_decode_credential_part($data, 'authenticatorData', $response);
    $signature = webauthn_decode_credential_part($data, 'signature', $response);
    $rp = webauthn_relying_party();

    webauthn_validate_client_data($clientDataJson, 'webauthn.get', (string)$sessionChallenge['challenge'], $rp['origin']);

    $stmt = db()->prepare(
        'SELECT p.*, u.name, u.email, u.password_hash, u.role, u.status, u.requested_student_id, u.failed_login_attempts, u.locked_until, u.created_at AS user_created_at, u.updated_at AS user_updated_at
         FROM user_passkeys p
         INNER JOIN users u ON u.id = p.user_id
         WHERE p.credential_id = ? AND p.user_id = ?
         LIMIT 1'
    );
    $stmt->execute([$rawId, (int)$sessionChallenge['user_id']]);
    $passkey = $stmt->fetch();

    if (!$passkey) {
        error_response('Passkey is not registered for this account.', 401);
    }

    require_active_account($passkey);
    require_unlocked_account([
        'id' => $passkey['user_id'],
        'failed_login_attempts' => $passkey['failed_login_attempts'] ?? 0,
        'locked_until' => $passkey['locked_until'] ?? null,
    ]);

    $authenticatorData = webauthn_parse_authenticator_data($authenticatorDataRaw, false);
    webauthn_validate_rp_id_hash($authenticatorData, $rp['id']);
    webauthn_validate_authenticator_flags($authenticatorData);

    $signedData = $authenticatorDataRaw . hash('sha256', $clientDataJson, true);
    if (!webauthn_verify_signature((string)$passkey['public_key_cose'], $signedData, $signature)) {
        error_response('Passkey signature could not be verified.', 401);
    }

    $storedSignCount = (int)$passkey['sign_count'];
    $newSignCount = (int)$authenticatorData['sign_count'];
    if ($storedSignCount > 0 && $newSignCount > 0 && $newSignCount <= $storedSignCount) {
        error_response('Passkey sign counter is invalid.', 401);
    }

    $signCount = $newSignCount > $storedSignCount ? $newSignCount : $storedSignCount;
    $stmt = db()->prepare('UPDATE user_passkeys SET sign_count = ?, last_used_at = NOW() WHERE id = ?');
    $stmt->execute([$signCount, (int)$passkey['id']]);
    reset_account_login_failures(db(), (int)$passkey['user_id']);

    $user = [
        'id' => $passkey['user_id'],
        'name' => $passkey['name'],
        'email' => $passkey['email'],
        'password_hash' => $passkey['password_hash'],
        'role' => $passkey['role'],
        'status' => $passkey['status'],
        'requested_student_id' => $passkey['requested_student_id'],
        'failed_login_attempts' => 0,
        'locked_until' => null,
        'created_at' => $passkey['user_created_at'],
        'updated_at' => $passkey['user_updated_at'],
    ];

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    unset($_SESSION['passkey_login_challenge']);

    return $user;
}
