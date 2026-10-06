<?php
declare(strict_types=1);

function init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    $path = parse_url((string) APP_URL, PHP_URL_PATH);
    $cookiePath = is_string($path) && $path !== '' ? rtrim($path, '/') . '/' : '/';

    session_name('WCSESSID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(self)');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_token_is_valid(): bool
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || $token === '') {
        $posted = $_POST['csrf_token'] ?? '';
        $token = is_string($posted) ? $posted : '';
    }
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return $token !== ''
        && is_string($sessionToken)
        && $sessionToken !== ''
        && hash_equals($sessionToken, $token);
}

function require_csrf(): void
{
    if (!csrf_token_is_valid()) {
        throw new AppException('Invalid security token. Refresh the page and try again.', 419);
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return substr((string) $ip, 0, 45);
}

function rate_limit(string $action, string $actor, int $max, int $windowSeconds): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT id, window_start, hit_count FROM rate_limits WHERE action_key = ? AND actor_key = ? FOR UPDATE');
        $select->execute([$action, $actor]);
        $row = $select->fetch();
        $now = time();

        if (!$row) {
            $insert = $pdo->prepare('INSERT INTO rate_limits (action_key, actor_key, window_start, hit_count) VALUES (?, ?, NOW(), 1)');
            $insert->execute([$action, $actor]);
            $pdo->commit();
            return;
        }

        $started = strtotime((string) $row['window_start']) ?: 0;
        if (($now - $started) >= $windowSeconds) {
            $reset = $pdo->prepare('UPDATE rate_limits SET window_start = NOW(), hit_count = 1 WHERE id = ?');
            $reset->execute([(int) $row['id']]);
            $pdo->commit();
            return;
        }

        if ((int) $row['hit_count'] >= $max) {
            $pdo->commit();
            throw new AppException('Too many requests. Please wait a moment and try again.', 429);
        }

        $update = $pdo->prepare('UPDATE rate_limits SET hit_count = hit_count + 1 WHERE id = ?');
        $update->execute([(int) $row['id']]);
        $pdo->commit();
    } catch (AppException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function image_mime_map(): array
{
    return [
        'jpg' => ['image/jpeg', 'application/octet-stream'],
        'jpeg' => ['image/jpeg', 'application/octet-stream'],
        'png' => ['image/png', 'application/octet-stream'],
        'gif' => ['image/gif', 'application/octet-stream'],
        'webp' => ['image/webp', 'application/octet-stream'],
    ];
}

function document_mime_map(): array
{
    return [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/octet-stream'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
        'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'],
        'txt' => ['text/plain'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
    ];
}

function voice_mime_map(): array
{
    return [
        'webm' => ['audio/webm', 'video/webm', 'application/octet-stream'],
        'ogg' => ['audio/ogg', 'video/ogg', 'application/ogg', 'application/octet-stream'],
        'mp3' => ['audio/mpeg', 'audio/mp3', 'application/octet-stream'],
        'wav' => ['audio/wav', 'audio/x-wav', 'application/octet-stream'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'video/mp4', 'application/octet-stream'],
    ];
}

function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is too large.',
        UPLOAD_ERR_PARTIAL => 'The file upload was interrupted.',
        UPLOAD_ERR_NO_FILE => 'Please choose a file.',
        default => 'The file could not be uploaded.',
    };
}

function sanitize_original_filename(string $name): string
{
    $base = basename(str_replace('\\', '/', $name));
    $base = preg_replace('/[\x00-\x1F\x7F]/', '', $base) ?? 'file';
    $base = preg_replace('/[^A-Za-z0-9._ -]/', '_', $base) ?? 'file';
    $base = trim($base);
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'file';
    }
    return str_limit($base, 120);
}

function file_signature_ok(string $path, string $ext): bool
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return false;
    }
    $bytes = fread($handle, 64) ?: '';
    fclose($handle);
    if ($bytes === '') {
        return false;
    }

    return match ($ext) {
        'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
        'png' => str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A"),
        'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
        'webp' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP',
        'pdf' => str_starts_with($bytes, '%PDF'),
        'doc', 'xls' => str_starts_with($bytes, "\xD0\xCF\x11\xE0"),
        'docx', 'xlsx', 'zip' => str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06"),
        'txt' => !str_contains(substr($bytes, 0, 32), "\0"),
        'webm' => str_starts_with($bytes, "\x1A\x45\xDF\xA3"),
        'ogg' => str_starts_with($bytes, 'OggS'),
        'mp3' => str_starts_with($bytes, 'ID3') || (isset($bytes[0], $bytes[1]) && ord($bytes[0]) === 0xFF && (ord($bytes[1]) & 0xE0) === 0xE0),
        'wav' => str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE',
        'm4a' => substr($bytes, 4, 4) === 'ftyp',
        default => false,
    };
}

function store_uploaded_file(array $file, string $bucket, int $ownerId, array $allowed, int $maxBytes): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new AppException(upload_error_message($error));
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new AppException('The file exceeds the allowed size.');
    }

    $original = sanitize_original_filename((string) ($file['name'] ?? 'file'));
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if ($ext === '' || !isset($allowed[$ext])) {
        throw new AppException('This file type is not allowed.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($tmp) ?: '');
    if (!in_array($mime, $allowed[$ext], true)) {
        throw new AppException('The file content does not match its extension.');
    }
    if (!file_signature_ok($tmp, $ext)) {
        throw new AppException('The file content could not be verified.');
    }
    if (isset(image_mime_map()[$ext]) && @getimagesize($tmp) === false && $ext !== 'webp') {
        throw new AppException('The image could not be verified.');
    }

    $directory = UPLOAD_DIR . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $ownerId;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new AppException('Unable to store the file. Please try again.');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $directory . DIRECTORY_SEPARATOR . $stored;
    if (!move_uploaded_file($tmp, $destination)) {
        throw new AppException('Unable to store the file. Please try again.');
    }

    return [
        'relative' => $bucket . '/' . $ownerId . '/' . $stored,
        'mime' => $mime,
        'size' => $size,
        'original' => $original,
        'ext' => $ext,
    ];
}

function absolute_upload_path(string $relative): string
{
    $relative = str_replace('\\', '/', $relative);
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '..')) {
        throw new AppException('File not found.', 404);
    }

    $base = realpath(UPLOAD_DIR);
    if ($base === false) {
        throw new AppException('File not found.', 404);
    }

    $candidate = UPLOAD_DIR . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $full = realpath($candidate);
    if ($full === false || !is_file($full)) {
        throw new AppException('File not found.', 404);
    }

    $baseNorm = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $fullNorm = str_replace('\\', '/', $full);
    if (!str_starts_with($fullNorm, $baseNorm)) {
        throw new AppException('File not found.', 404);
    }

    return $full;
}

function delete_upload(?string $relative): void
{
    if ($relative === null || $relative === '') {
        return;
    }
    try {
        $full = absolute_upload_path($relative);
        if (is_file($full)) {
            unlink($full);
        }
    } catch (Throwable) {
        // Missing files should not block message or profile updates.
    }
}

function clone_upload_file(string $relative, int $ownerId, ?string $originalName = null, ?string $mimeType = null): array
{
    $full = absolute_upload_path($relative);
    $normalized = str_replace('\\', '/', $relative);
    $parts = explode('/', $normalized);
    $bucket = $parts[0] ?? 'files';
    if (!in_array($bucket, ['images', 'files', 'voices'], true)) {
        $bucket = 'files';
    }

    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    if ($ext === '') {
        throw new AppException('Unable to forward this attachment.');
    }

    $directory = UPLOAD_DIR . DIRECTORY_SEPARATOR . $bucket . DIRECTORY_SEPARATOR . $ownerId;
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new AppException('Unable to store the file. Please try again.');
    }

    $stored = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = $directory . DIRECTORY_SEPARATOR . $stored;
    if (!copy($full, $destination)) {
        throw new AppException('Unable to forward this attachment.');
    }

    $size = filesize($destination);
    if ($size === false) {
        @unlink($destination);
        throw new AppException('Unable to forward this attachment.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = (string) ($finfo->file($destination) ?: '');

    return [
        'relative' => $bucket . '/' . $ownerId . '/' . $stored,
        'mime' => $mimeType ?: ($detected !== '' ? $detected : 'application/octet-stream'),
        'size' => (int) $size,
        'original' => sanitize_original_filename($originalName ?: ('file.' . $ext)),
        'ext' => $ext,
    ];
}

function content_disposition(string $filename, bool $inline): string
{
    $safe = sanitize_original_filename($filename);
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $safe) ?: 'download';
    $type = $inline ? 'inline' : 'attachment';
    return $type . '; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($safe);
}

function stream_upload(string $relative, string $downloadName, bool $inline): void
{
    $full = absolute_upload_path($relative);
    $size = filesize($full);
    if ($size === false) {
        throw new AppException('File not found.', 404);
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) ($finfo->file($full) ?: 'application/octet-stream');
    $start = 0;
    $end = max(0, $size - 1);
    $status = 200;

    if ($size > 0 && isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $matches)) {
        if ($matches[1] === '' && $matches[2] !== '') {
            $suffix = (int) $matches[2];
            $start = max(0, $size - $suffix);
        } else {
            $start = (int) $matches[1];
            if ($matches[2] !== '') {
                $end = (int) $matches[2];
            }
        }
        $end = min($end, $size - 1);
        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }
        $status = 206;
    }

    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: ' . content_disposition($downloadName, $inline));
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: default-src \'none\'');
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        if ($status === 206) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            header('Content-Length: ' . (($end - $start) + 1));
        } else {
            header('Content-Length: ' . $size);
        }
    }

    $handle = fopen($full, 'rb');
    if ($handle === false) {
        throw new AppException('File not found.', 404);
    }
    if ($start > 0) {
        fseek($handle, $start);
    }
    $remaining = ($end - $start) + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, (int) min(8192, $remaining));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        if (connection_status() !== CONNECTION_NORMAL) {
            break;
        }
    }
    fclose($handle);
    exit;
}
