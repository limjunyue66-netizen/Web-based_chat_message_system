<?php
declare(strict_types=1);

class AppException extends RuntimeException
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}

function is_api_request(): bool
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($script, '/api/');
}

function normalize_status(int $code): int
{
    return ($code >= 400 && $code <= 599) ? $code : 400;
}

function log_error(Throwable $exception): void
{
    $directory = APP_ROOT . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $line = date('c')
        . ' ' . $exception->getMessage()
        . ' in ' . $exception->getFile()
        . ':' . $exception->getLine()
        . PHP_EOL;
    file_put_contents($directory . DIRECTORY_SEPARATOR . 'app.log', $line, FILE_APPEND);
}

function fail_database(): void
{
    $message = 'The database is not available. Create the webconnect database, import database/database.sql, and check includes/config.php.';
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (is_api_request()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Database unavailable</title>';
    echo '<body style="font-family:Segoe UI,sans-serif;padding:2rem;max-width:40rem">';
    echo '<h1>Database unavailable</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}

function init_error_handling(): void
{
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    set_exception_handler(static function (Throwable $exception): void {
        if ($exception instanceof AppException) {
            if (PHP_SAPI === 'cli') {
                fwrite(STDERR, $exception->getMessage() . PHP_EOL);
                exit(1);
            }
            $status = normalize_status($exception->getCode());
            if (is_api_request()) {
                json_err($exception->getMessage(), $status);
            }
            if (!headers_sent()) {
                http_response_code($status);
            }
            echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Request failed</title>';
            echo '<body style="font-family:Segoe UI,sans-serif;padding:2rem"><p>'
                . e($exception->getMessage())
                . '</p><p><a href="' . e(rtrim(APP_URL, '/')) . '/index.php">Back to WebConnect</a></p></body></html>';
            exit;
        }

        log_error($exception);
        if (!headers_sent()) {
            http_response_code(500);
        }
        if (is_api_request()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'message' => 'Something went wrong. Please try again.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Something went wrong</title>';
        echo '<body style="font-family:Segoe UI,sans-serif;padding:2rem"><p>Something went wrong. Please try again.</p></body></html>';
        exit;
    });
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function str_limit(string $value, int $max): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $max);
    }
    return substr($value, 0, $max);
}

function json_ok(mixed $data = [], string $message = 'OK', int $code = 200): never
{
    if (!headers_sent()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'success' => true,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function json_err(string $message, int $code = 400): never
{
    if (!headers_sent()) {
        http_response_code(normalize_status($code));
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo json_encode([
        'success' => false,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function api_begin(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function request_data(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
    $json = [];
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $json = $decoded;
            }
        }
    }
    return array_merge($_GET, $_POST, $json);
}

function request_method(): string
{
    return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
}

function redirect(string $path): never
{
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)) {
        $path = 'index.php';
    }
    header('Location: ' . app_url(ltrim($path, '/')));
    exit;
}

function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_take(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

function clamp_int(mixed $value, int $min, int $max): int
{
    $number = (int) $value;
    return max($min, min($max, $number));
}

function time_ago(?string $datetime): string
{
    if ($datetime === null || $datetime === '') {
        return 'a while ago';
    }
    $then = strtotime($datetime);
    if ($then === false) {
        return 'a while ago';
    }
    $diff = max(0, time() - $then);
    if ($diff < 45) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = (int) floor($diff / 60);
        return $mins . ' minute' . ($mins === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 86400) {
        $hours = (int) floor($diff / 3600);
        return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
    }
    $days = (int) floor($diff / 86400);
    if ($days < 7) {
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    return date('M j, Y g:i A', $then);
}

function format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

function like_escape(string $value): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    $value = trim($value, '-');
    return $value !== '' ? str_limit($value, 80) : 'forum';
}

function ensure_upload_dirs(): void
{
    foreach (['avatars', 'images', 'files', 'voices', 'groups'] as $directory) {
        $path = UPLOAD_DIR . DIRECTORY_SEPARATOR . $directory;
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
    }
}

function ensure_chat_delete_tables(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS private_message_hides (
            user_id INT UNSIGNED NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, message_id),
            KEY idx_private_hide_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS private_chat_clears (
            user_id INT UNSIGNED NOT NULL,
            peer_id INT UNSIGNED NOT NULL,
            cleared_before_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, peer_id),
            KEY idx_private_clear_peer (peer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_message_hides (
            user_id INT UNSIGNED NOT NULL,
            message_id INT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, message_id),
            KEY idx_group_hide_message (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS group_chat_clears (
            user_id INT UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            cleared_before_id INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, group_id),
            KEY idx_group_clear_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function ensure_call_controls_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = db();
    try {
        $pdo->exec(
            "ALTER TABLE voice_call_signals
             MODIFY signal_type ENUM('offer', 'answer', 'ice', 'hangup', 'control') NOT NULL"
        );
    } catch (Throwable $exception) {
        /* Table may already be updated or unavailable during first install. */
    }
    try {
        $column = $pdo->query("SHOW COLUMNS FROM voice_call_sessions LIKE 'logged_message_id'")->fetch();
        if (!$column) {
            $pdo->exec('ALTER TABLE voice_call_sessions ADD COLUMN logged_message_id INT UNSIGNED NULL AFTER ended_at');
        }
    } catch (Throwable $exception) {
        /* Live databases created before call logs skip this until the table exists. */
    }
    try {
        $pdo->exec(
            "ALTER TABLE messages
             MODIFY message_type ENUM('text', 'image', 'file', 'voice', 'call') NOT NULL DEFAULT 'text'"
        );
    } catch (Throwable $exception) {
        /* Existing installs already include the call message type. */
    }
}

function presence_of(array $user): array
{
    $last = isset($user['last_seen']) ? strtotime((string) $user['last_seen']) : false;
    $recent = $last !== false && (time() - $last) <= 50 && (int) ($user['is_active'] ?? 1) === 1;
    if (!$recent) {
        return [
            'state' => 'offline',
            'label' => 'Offline',
            'last_seen' => $user['last_seen'] ?? null,
            'last_seen_label' => 'Last seen ' . time_ago($user['last_seen'] ?? null),
        ];
    }
    $mode = (string) ($user['presence_mode'] ?? 'online');
    if ($mode === 'busy') {
        return ['state' => 'busy', 'label' => 'Busy', 'last_seen' => $user['last_seen'], 'last_seen_label' => 'Busy'];
    }
    if ($mode === 'away') {
        return ['state' => 'away', 'label' => 'Away', 'last_seen' => $user['last_seen'], 'last_seen_label' => 'Away'];
    }
    return ['state' => 'online', 'label' => 'Online', 'last_seen' => $user['last_seen'], 'last_seen_label' => 'Online'];
}

function avatar_url(array $user): string
{
    if (!empty($user['avatar_path'])) {
        return app_url('api/media.php?type=avatar&user=' . (int) $user['id']);
    }
    return app_url('api/media.php?type=initials&name=' . rawurlencode((string) $user['username']));
}

function group_avatar_url(array $group): string
{
    if (!empty($group['avatar_path'])) {
        return app_url('api/media.php?type=group_avatar&group=' . (int) $group['id']);
    }
    return app_url('api/media.php?type=initials&name=' . rawurlencode((string) $group['name']));
}

function user_public(array $user, bool $includeEmail = false): array
{
    $presence = presence_of($user);
    $payload = [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'avatar_url' => avatar_url($user),
        'status_message' => (string) ($user['status_message'] ?? ''),
        'presence' => $presence['state'],
        'presence_label' => $presence['label'],
        'last_seen' => $user['last_seen'] ?? null,
        'last_seen_label' => $presence['last_seen_label'],
        'presence_mode' => (string) ($user['presence_mode'] ?? 'online'),
        'created_at' => $user['created_at'] ?? null,
        'is_active' => (int) ($user['is_active'] ?? 1),
    ];
    if ($includeEmail) {
        $payload['email'] = (string) $user['email'];
    }
    return $payload;
}

function find_user(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $user = $statement->fetch();
    return $user ?: null;
}

function find_user_by_login(string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    if (str_contains($identifier, '@')) {
        $statement = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
        $statement->execute([strtolower($identifier)]);
    } else {
        $statement = db()->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $statement->execute([$identifier]);
    }
    $user = $statement->fetch();
    return $user ?: null;
}

function valid_username(string $username): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{3,32}$/', $username);
}

function valid_password(string $password): bool
{
    $length = strlen($password);
    return $length >= 8
        && $length <= 72
        && preg_match('/[A-Za-z]/', $password)
        && preg_match('/\d/', $password);
}

function allowed_reaction(string $emoji): bool
{
    return in_array($emoji, ['👍', '❤️', '😂', '😮', '😢', '👏'], true);
}

function app_base_path(): string
{
    $path = parse_url(APP_URL, PHP_URL_PATH);
    return rtrim(is_string($path) ? $path : '', '/');
}

function app_url(string $path = ''): string
{
    $suffix = ltrim($path, '/');
    $basePath = app_base_path();

    // Prefer the browser's current host so localhost and 127.0.0.1 keep the same session.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (is_string($host) && $host !== '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443');
        $scheme = $https ? 'https' : 'http';
        return $scheme . '://' . $host . $basePath . ($suffix !== '' ? '/' . $suffix : '');
    }

    return rtrim(APP_URL, '/') . ($suffix !== '' ? '/' . $suffix : '');
}

function asset_url(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $absolute = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $version = is_file($absolute) ? (string) filemtime($absolute) : (string) time();
    return app_url($relativePath) . '?v=' . rawurlencode($version);
}
