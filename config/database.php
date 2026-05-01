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

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
