<?php
declare(strict_types=1);

$root = dirname(__DIR__);

$thresholds = [
    'lines' => 500,
    'bytes' => 50 * 1024,
    'functions' => 40,
    'branches' => 20,
];

$sourceExtensions = [
    'php' => true,
    'js' => true,
    'css' => true,
];

$excludedDirectories = [
    '.git' => true,
    '.playwright-mcp' => true,
    'node_modules' => true,
    'storage' => true,
    'vendor' => true,
];

if (in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
    echo "God File Detector\n";
    echo "Usage: php tools/detect-god-files.php\n\n";
    echo "Scans PHP, JS, and CSS source files for size and responsibility warnings.\n";
    echo "SQL files are intentionally skipped. Warnings are advisory and exit code is always 0.\n";
    exit(0);
}

$files = collect_source_files($root, $sourceExtensions, $excludedDirectories);
$findings = [];

foreach ($files as $file) {
    $content = file_get_contents($file);

    if ($content === false) {
        continue;
    }

    $extension = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
    $metrics = [
        'lines' => count_lines($content),
        'bytes' => strlen($content),
        'functions' => count_functions($content, $extension),
        'branches' => count_action_branches($content, $extension),
    ];

    $warnings = build_warnings($metrics, $thresholds, $extension);

    if ($warnings !== []) {
        $findings[] = [
            'path' => relative_path($root, $file),
            'metrics' => $metrics,
            'warnings' => $warnings,
            'score' => warning_score($metrics, $thresholds),
        ];
    }
}

usort(
    $findings,
    static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: strcmp($a['path'], $b['path'])
);

print_report($findings, count($files), $thresholds);
exit(0);

/**
 * @param array<string, bool> $sourceExtensions
 * @param array<string, bool> $excludedDirectories
 * @return list<string>
 */
function collect_source_files(string $root, array $sourceExtensions, array $excludedDirectories): array
{
    $directory = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator(
        $directory,
        static function (SplFileInfo $current) use ($sourceExtensions, $excludedDirectories): bool {
            if ($current->isDir()) {
                return !isset($excludedDirectories[$current->getFilename()]);
            }

            if (!$current->isFile()) {
                return false;
            }

            $extension = strtolower((string)pathinfo($current->getFilename(), PATHINFO_EXTENSION));

            return isset($sourceExtensions[$extension]);
        }
    );

    $files = [];

    foreach (new RecursiveIteratorIterator($filter) as $file) {
        if ($file instanceof SplFileInfo) {
            $files[] = $file->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

function count_lines(string $content): int
{
    if ($content === '') {
        return 0;
    }

    $lines = substr_count($content, "\n");

    return substr($content, -1) === "\n" ? $lines : $lines + 1;
}

function count_functions(string $content, string $extension): int
{
    if ($extension === 'php') {
        return count_php_functions($content);
    }

    if ($extension === 'js') {
        return count_js_functions($content);
    }

    return 0;
}

function count_php_functions(string $content): int
{
    $count = 0;

    foreach (token_get_all($content) as $token) {
        if (is_array($token) && $token[0] === T_FUNCTION) {
            $count++;
        }
    }

    return $count;
}

function count_js_functions(string $content): int
{
    $patterns = [
        '/(?:^|[^\w$])(?:async\s+)?function\s+[\w$]*\s*\(/m',
        '/\b(?:const|let|var)\s+[\w$]+\s*=\s*(?:async\s*)?function\b/m',
        '/\b(?:const|let|var)\s+[\w$]+\s*=\s*(?:async\s*)?\([^)]*\)\s*=>/m',
        '/\b(?:const|let|var)\s+[\w$]+\s*=\s*(?:async\s*)?[\w$]+\s*=>/m',
    ];

    $count = 0;

    foreach ($patterns as $pattern) {
        $count += preg_match_all($pattern, $content);
    }

    return $count;
}

function count_action_branches(string $content, string $extension): int
{
    if (!in_array($extension, ['php', 'js'], true)) {
        return 0;
    }

    $actionComparisons = preg_match_all('/(?:\$action|action)\s*(?:={2,3}|!==?)\s*[\'"][^\'"]+[\'"]/i', $content);

    if ($extension === 'php') {
        return $actionComparisons + count_php_cases($content);
    }

    return $actionComparisons + preg_match_all('/\bcase\s+[^:]+:/i', $content);
}

function count_php_cases(string $content): int
{
    $count = 0;

    foreach (token_get_all($content) as $token) {
        if (is_array($token) && $token[0] === T_CASE) {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array{lines:int, bytes:int, functions:int, branches:int} $metrics
 * @param array{lines:int, bytes:int, functions:int, branches:int} $thresholds
 * @return list<string>
 */
function build_warnings(array $metrics, array $thresholds, string $extension): array
{
    $warnings = [];

    if ($metrics['lines'] >= $thresholds['lines']) {
        $warnings[] = sprintf('lines: %d (limit %d)', $metrics['lines'], $thresholds['lines']);
    }

    if ($metrics['bytes'] >= $thresholds['bytes']) {
        $warnings[] = sprintf('size: %s (limit %s)', format_bytes($metrics['bytes']), format_bytes($thresholds['bytes']));
    }

    if ($extension !== 'css' && $metrics['functions'] >= $thresholds['functions']) {
        $warnings[] = sprintf('functions: %d (limit %d)', $metrics['functions'], $thresholds['functions']);
    }

    if ($extension !== 'css' && $metrics['branches'] >= $thresholds['branches']) {
        $warnings[] = sprintf('action branches / switch cases: %d (limit %d)', $metrics['branches'], $thresholds['branches']);
    }

    return $warnings;
}

/**
 * @param array{lines:int, bytes:int, functions:int, branches:int} $metrics
 * @param array{lines:int, bytes:int, functions:int, branches:int} $thresholds
 */
function warning_score(array $metrics, array $thresholds): float
{
    return ($metrics['lines'] / $thresholds['lines'])
        + ($metrics['bytes'] / $thresholds['bytes'])
        + ($metrics['functions'] / $thresholds['functions'])
        + ($metrics['branches'] / $thresholds['branches']);
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }

    return number_format($bytes / 1024, 1) . ' KB';
}

function relative_path(string $root, string $path): string
{
    $root = normalize_path((string)(realpath($root) ?: $root));
    $path = normalize_path($path);
    $prefix = rtrim($root, '/') . '/';

    if (substr($path, 0, strlen($prefix)) === $prefix) {
        return substr($path, strlen($prefix));
    }

    return $path;
}

function normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

/**
 * @param list<array{path:string, metrics:array<string, int>, warnings:list<string>, score:float}> $findings
 * @param array{lines:int, bytes:int, functions:int, branches:int} $thresholds
 */
function print_report(array $findings, int $scannedFiles, array $thresholds): void
{
    echo "God File Detector\n";
    echo "Scanned {$scannedFiles} PHP/JS/CSS source files. SQL files are skipped.\n";
    echo "Thresholds: {$thresholds['lines']} lines, " . format_bytes($thresholds['bytes']) . ", {$thresholds['functions']} functions, {$thresholds['branches']} action branches/switch cases.\n\n";

    if ($findings === []) {
        echo "No god-file warnings found.\n";
        return;
    }

    echo "Warnings are advisory. These files may be doing too much:\n\n";

    foreach ($findings as $finding) {
        echo "WARNING {$finding['path']}\n";

        foreach ($finding['warnings'] as $warning) {
            echo "  - {$warning}\n";
        }

        echo "\n";
    }

    echo "Suggested next steps:\n";
    echo "  - Split large UI files into layout, state, API-client, and view modules.\n";
    echo "  - Split API files into thin action handlers plus shared validation, authorization, and persistence helpers.\n";
    echo "  - Keep business rules out of transport and presentation code when extracting files.\n";
}
