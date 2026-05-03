<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function assert_refactor_true(bool $condition, string $message): void
{
    global $failures;

    if (!$condition) {
        $failures[] = $message;
    }
}

/**
 * @param list<string> $expected
 * @param list<string> $actual
 */
function assert_refactor_same_list(array $expected, array $actual, string $message): void
{
    if ($expected === $actual) {
        return;
    }

    assert_refactor_true(
        false,
        $message . "\nExpected:\n  - " . implode("\n  - ", $expected) . "\nActual:\n  - " . implode("\n  - ", $actual)
    );
}

function read_refactor_file(string $path): string
{
    $content = file_get_contents($path);

    if ($content === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }

    return $content;
}

$index = read_refactor_file($root . '/index.php');
$css = read_refactor_file($root . '/assets/css/index.css');
$auth = read_refactor_file($root . '/api/auth.php');

$expectedStylesheets = [
    'assets/css/index.css',
    'assets/css/responsive.css',
];

preg_match_all('/<link\s+rel="stylesheet"\s+href="([^"]+)"/i', $index, $stylesheetMatches);
$actualLocalStylesheets = array_values(array_filter(
    $stylesheetMatches[1],
    static fn (string $href): bool => str_starts_with($href, 'assets/css/')
));

assert_refactor_same_list(
    $expectedStylesheets,
    $actualLocalStylesheets,
    'index.php must load the extracted stylesheets in dependency order.'
);
assert_refactor_true(
    str_contains($index, '<link rel="stylesheet" href="assets/css/index.css">'),
    'index.php must load the extracted stylesheet.'
);
foreach ($expectedStylesheets as $stylesheet) {
    assert_refactor_true(is_file($root . '/' . $stylesheet), $stylesheet . ' must exist.');
}
assert_refactor_true(
    preg_match('/<style\b/i', $index) !== 1,
    'index.php must not keep the refactored CSS in an inline <style> block.'
);
assert_refactor_true(
    !str_contains($css, "url('assets/img/") && !str_contains($css, 'url("assets/img/'),
    'Extracted CSS URLs must be relative to assets/css, for example ../img/name.png.'
);

preg_match_all('/<script\s+src="([^"]+)"\s*>\s*<\/script>/i', $index, $scriptMatches);
$actualScripts = array_values(array_filter(
    $scriptMatches[1],
    static fn (string $src): bool => str_starts_with($src, 'assets/js/')
));
$expectedScripts = [
    'assets/js/state.js',
    'assets/js/auth.js',
    'assets/js/permissions.js',
    'assets/js/navigation.js',
    'assets/js/ui.js',
    'assets/js/delete-actions.js',
    'assets/js/views-dashboard.js',
    'assets/js/views-students.js',
    'assets/js/views-admin.js',
    'assets/js/views-profile.js',
    'assets/js/events.js',
    'assets/js/init.js',
];

assert_refactor_same_list(
    $expectedScripts,
    $actualScripts,
    'index.php must load the extracted JavaScript files in dependency order.'
);

foreach ($expectedScripts as $script) {
    assert_refactor_true(is_file($root . '/' . $script), $script . ' must exist.');
}

assert_refactor_true(
    !in_array('assets/js/app.js', $actualScripts, true),
    'index.php must not load the old god-file JavaScript bundle.'
);

foreach ([
    "require_once __DIR__ . '/auth_email.php';",
    "require_once __DIR__ . '/auth_helpers.php';",
] as $requiredInclude) {
    assert_refactor_true(
        str_contains($auth, $requiredInclude),
        'api/auth.php must include ' . $requiredInclude
    );
}

foreach ([
    'send_login_code_email',
    'send_signup_confirmation_email',
    'validate_student_signup_payload',
    'create_login_challenge',
    'require_open_challenge',
] as $extractedFunction) {
    assert_refactor_true(
        preg_match('/function\s+' . preg_quote($extractedFunction, '/') . '\s*\(/', $auth) !== 1,
        'api/auth.php must not redefine extracted helper ' . $extractedFunction . '().'
    );
}

if ($failures !== []) {
    echo "God-file refactor test failed:\n\n";
    foreach ($failures as $failure) {
        echo "- {$failure}\n";
    }
    exit(1);
}

echo "God-file refactor test passed.\n";
