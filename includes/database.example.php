<?php
declare(strict_types=1);

/**
 * Example database settings for WebConnect.
 *
 * How to use:
 * 1. Copy includes/config.local.php.example to includes/config.local.php
 * 2. Set the values below to match your MySQL/MariaDB account
 * 3. Import database/database.sql
 * 4. Keep config.local.php out of git (already listed in .gitignore)
 *
 * XAMPP defaults usually work with an empty password.
 * cPanel / shared hosting: use the database name, user, and password
 * shown in your hosting panel. Prefer APP_URL with https:// in production.
 */

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'webconnect');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_URL', 'http://localhost/Web-based_chat_message_system');

/*
 * Production / cPanel example (placeholders only):
 *
 * define('DB_HOST', 'localhost');
 * define('DB_NAME', 'your_cpanel_db_name');
 * define('DB_USER', 'your_cpanel_db_user');
 * define('DB_PASS', 'your_strong_password');
 * define('APP_URL', 'https://your-domain.example');
 */
