<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

date_default_timezone_set(APP_TIMEZONE);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/lib/notifications.php';
require_once __DIR__ . '/lib/friends.php';
require_once __DIR__ . '/lib/messages.php';
require_once __DIR__ . '/lib/groups.php';
require_once __DIR__ . '/lib/calls.php';
require_once __DIR__ . '/lib/forums.php';
require_once __DIR__ . '/lib/reports.php';
require_once __DIR__ . '/lib/admin.php';

init_session();
send_security_headers();
init_error_handling();
ensure_upload_dirs();
ensure_chat_delete_tables();
ensure_call_controls_schema();

$bootUser = current_user();
if ($bootUser) {
    touch_presence((int) $bootUser['id']);
}

// Release the session lock for API requests so chat polling is not blocked.
// Keep the session open for auth.php because login/logout must write session data.
$scriptPath = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$scriptName = basename($scriptPath);
if (str_contains($scriptPath, '/api/') && $scriptName !== 'auth.php' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
