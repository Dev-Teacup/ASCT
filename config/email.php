<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

if (!defined('RESEND_API_KEY')) {
    define('RESEND_API_KEY', (string)(getenv('RESEND_API_KEY') ?: ''));
}

if (!defined('RESEND_FROM_EMAIL')) {
    define('RESEND_FROM_EMAIL', (string)(getenv('RESEND_FROM_EMAIL') ?: ''));
}

if (!defined('RESEND_FROM_NAME')) {
    define('RESEND_FROM_NAME', (string)(getenv('RESEND_FROM_NAME') ?: 'ASCT Portal'));
}

if (!defined('ASCT_APP_NAME')) {
    define('ASCT_APP_NAME', (string)(getenv('ASCT_APP_NAME') ?: 'ASCT Portal'));
}

if (!defined('ASCT_APP_BASE_URL')) {
    define('ASCT_APP_BASE_URL', (string)(getenv('ASCT_APP_BASE_URL') ?: 'http://localhost/ASCT'));
}
