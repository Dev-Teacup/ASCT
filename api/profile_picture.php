<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const PROFILE_PICTURE_MAX_BYTES = 2097152;
const PROFILE_PICTURE_MAX_DIMENSION = 4000;
const PROFILE_PICTURE_FIELD = 'profile_picture';
const PROFILE_PICTURE_MIME_EXTENSIONS = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
];

function profile_picture_dir(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'profile_pictures';
}

function profile_picture_paths(int $userId): array
{
    $dir = profile_picture_dir();
    $paths = [];

    foreach (array_unique(array_values(PROFILE_PICTURE_MIME_EXTENSIONS)) as $extension) {
        $paths[] = $dir . DIRECTORY_SEPARATOR . 'user_' . $userId . '.' . $extension;
    }

    return $paths;
}

function current_profile_picture_path(int $userId): ?string
{
    foreach (profile_picture_paths($userId) as $path) {
        if (is_file($path)) {
            return $path;
        }
    }

    return null;
}

function profile_picture_path_for_mime(int $userId, string $mime): string
{
    return profile_picture_dir()
        . DIRECTORY_SEPARATOR
        . 'user_' . $userId . '.'
        . PROFILE_PICTURE_MIME_EXTENSIONS[$mime];
}

function profile_picture_mime_for_path(string $path): string
{
    $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));

    return match ($extension) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        default => 'application/octet-stream',
    };
}

function ensure_profile_picture_dir(): void
{
    $dir = profile_picture_dir();

    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        error_response('Profile picture storage is not available.', 500);
    }

    if (!is_writable($dir)) {
        error_response('Profile picture storage is not writable.', 500);
    }
}

function uploaded_profile_picture(): array
{
    $file = $_FILES[PROFILE_PICTURE_FIELD] ?? null;

    if (!is_array($file)) {
        error_response('Please choose a profile picture to upload.');
    }

    $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Profile picture must be 2 MB or smaller.',
            UPLOAD_ERR_NO_FILE => 'Please choose a profile picture to upload.',
            default => 'Profile picture upload failed.',
        };
        error_response($message);
    }

    return $file;
}

function validate_profile_picture_upload(array $file): array
{
    $size = (int)($file['size'] ?? 0);
    $tmpPath = (string)($file['tmp_name'] ?? '');

    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        error_response('Profile picture upload was not received.');
    }

    if ($size <= 0 || $size > PROFILE_PICTURE_MAX_BYTES) {
        error_response('Profile picture must be 2 MB or smaller.');
    }

    $imageInfo = @getimagesize($tmpPath);
    if (!is_array($imageInfo)) {
        error_response('Profile picture must be a valid image.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detectedMime = (string)$finfo->file($tmpPath);
    $imageMime = (string)($imageInfo['mime'] ?? '');
    $mime = array_key_exists($detectedMime, PROFILE_PICTURE_MIME_EXTENSIONS) ? $detectedMime : $imageMime;

    if (!array_key_exists($mime, PROFILE_PICTURE_MIME_EXTENSIONS)) {
        error_response('Profile picture must be a JPG, PNG, or WEBP image.');
    }

    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > PROFILE_PICTURE_MAX_DIMENSION || $height > PROFILE_PICTURE_MAX_DIMENSION) {
        error_response('Profile picture dimensions are invalid.');
    }

    return [
        'tmp_path' => $tmpPath,
        'mime' => $mime,
    ];
}

function replace_profile_picture(int $userId, array $upload): string
{
    ensure_profile_picture_dir();

    $target = profile_picture_path_for_mime($userId, (string)$upload['mime']);
    $temporaryTarget = $target . '.tmp-' . bin2hex(random_bytes(8));

    if (!move_uploaded_file((string)$upload['tmp_path'], $temporaryTarget)) {
        error_response('Profile picture could not be saved.', 500);
    }

    foreach (profile_picture_paths($userId) as $path) {
        if ($path !== $target && is_file($path)) {
            @unlink($path);
        }
    }

    if (is_file($target)) {
        @unlink($target);
    }

    if (!rename($temporaryTarget, $target)) {
        @unlink($temporaryTarget);
        error_response('Profile picture could not be saved.', 500);
    }

    @chmod($target, 0640);

    return $target;
}

try {
    $user = require_user();
    require_active_account($user);

    $action = request_action();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $userId = (int)$user['id'];

    if ($method === 'GET' && $action === 'view') {
        $path = current_profile_picture_path($userId);

        if (!$path) {
            error_response('Profile picture not found.', 404);
        }

        header('Content-Type: ' . profile_picture_mime_for_path($path));
        header('Content-Length: ' . (string)filesize($path));
        header('Cache-Control: private, max-age=300');
        readfile($path);
        exit;
    }

    if ($method === 'POST' && $action === 'upload') {
        $file = uploaded_profile_picture();
        $upload = validate_profile_picture_upload($file);
        $path = replace_profile_picture($userId, $upload);
        $version = (string)(filemtime($path) ?: time());

        json_response([
            'success' => true,
            'message' => 'Profile picture updated.',
            'data' => [
                'version' => $version,
                'profile_picture_url' => 'api/profile_picture.php?action=view&v=' . rawurlencode($version),
            ],
        ]);
    }

    error_response('Unsupported profile picture action.', 404);
} catch (Throwable $e) {
    log_server_exception($e, 'Profile picture request failed');
    error_response('Profile picture request failed.', 500);
}
