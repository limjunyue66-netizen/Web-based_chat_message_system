<?php
declare(strict_types=1);

/**
 * Central configuration.
 * Copy includes/config.local.php.example to includes/config.local.php
 * to override these defaults without editing this file.
 * Never commit real production passwords.
 */
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_NAME') || define('DB_NAME', 'webconnect');
defined('DB_USER') || define('DB_USER', 'root');
defined('DB_PASS') || define('DB_PASS', '');
defined('APP_URL') || define('APP_URL', 'http://localhost/Web-based_chat_message_system');
defined('APP_NAME') || define('APP_NAME', 'WebConnect');
defined('APP_TAGLINE') || define('APP_TAGLINE', 'Web-Based Chat, Voice & Video Communication System');
defined('APP_TIMEZONE') || define('APP_TIMEZONE', 'Asia/Singapore');

defined('UPLOAD_MAX_IMAGE') || define('UPLOAD_MAX_IMAGE', 5 * 1024 * 1024);
defined('UPLOAD_MAX_FILE') || define('UPLOAD_MAX_FILE', 15 * 1024 * 1024);
defined('UPLOAD_MAX_VOICE') || define('UPLOAD_MAX_VOICE', 10 * 1024 * 1024);
defined('UPLOAD_MAX_AVATAR') || define('UPLOAD_MAX_AVATAR', 2 * 1024 * 1024);

defined('RTC_ICE_SERVERS') || define('RTC_ICE_SERVERS', '[{"urls":"stun:stun.l.google.com:19302"}]');

define('APP_ROOT', dirname(__DIR__));
define('UPLOAD_DIR', APP_ROOT . DIRECTORY_SEPARATOR . 'uploads');
