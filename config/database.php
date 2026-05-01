<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

function asct_database_env(string $key, string $default): string
{
    $value = getenv($key);

    return $value === false ? $default : (string)$value;
}

function asct_database_port(): int
{
    $port = trim(asct_database_env('DB_PORT', '3306'));
    $isValidPort = filter_var(
        $port,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 65535]]
    );

    if ($isValidPort === false) {
        throw new RuntimeException('DB_PORT must be an integer between 1 and 65535.');
    }

    return (int)$port;
}

function asct_database_bool(string $key, bool $default): bool
{
    $value = getenv($key);

    if ($value === false || trim((string)$value) === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
}

if (!defined('DB_HOST')) {
    define('DB_HOST', asct_database_env('DB_HOST', 'localhost'));
}

if (!defined('DB_PORT')) {
    define('DB_PORT', asct_database_port());
}

if (!defined('DB_NAME')) {
    define('DB_NAME', asct_database_env('DB_NAME', 'asct'));
}

if (!defined('DB_USER')) {
    define('DB_USER', asct_database_env('DB_USER', 'root'));
}

if (!defined('DB_PASS')) {
    define('DB_PASS', asct_database_env('DB_PASS', ''));
}

if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', asct_database_env('DB_CHARSET', 'utf8mb4'));
}

if (!defined('DB_SSL_MODE')) {
    define('DB_SSL_MODE', strtolower(trim(asct_database_env('DB_SSL_MODE', ''))));
}

if (!defined('DB_SSL_CA')) {
    define('DB_SSL_CA', trim(asct_database_env('DB_SSL_CA', '')));
}

if (!defined('DB_SSL_VERIFY_SERVER_CERT')) {
    define('DB_SSL_VERIFY_SERVER_CERT', asct_database_bool('DB_SSL_VERIFY_SERVER_CERT', true));
}

function asct_database_options(): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if (DB_SSL_MODE === '' || DB_SSL_MODE === 'disabled') {
        return $options;
    }

    if (DB_SSL_CA === '') {
        throw new RuntimeException('DB_SSL_CA is required when DB_SSL_MODE is enabled.');
    }

    if (!is_readable(DB_SSL_CA)) {
        throw new RuntimeException('DB_SSL_CA must point to a readable CA certificate bundle.');
    }

    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = DB_SSL_VERIFY_SERVER_CERT;

    return $options;
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, asct_database_options());

    return $pdo;
}
