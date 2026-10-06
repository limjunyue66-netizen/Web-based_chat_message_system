<?php
declare(strict_types=1);

const DUMMY_PASSWORD_HASH = '$2y$10$87DBsNuxTKF953y3gefo4OZ2j7XEm5WwDRnv7PTOeMyo6npkrNRCO';

function current_user(): ?array
{
    if (empty($_SESSION['wc_user_id'])) {
        return null;
    }
    $user = find_user((int) $_SESSION['wc_user_id']);
    if (!$user || (int) $user['is_active'] !== 1) {
        unset($_SESSION['wc_user_id']);
        return null;
    }
    return $user;
}

function current_admin(): ?array
{
    if (empty($_SESSION['wc_admin_id'])) {
        return null;
    }
    $statement = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    $statement->execute([(int) $_SESSION['wc_admin_id']]);
    $admin = $statement->fetch();
    if (!$admin) {
        unset($_SESSION['wc_admin_id']);
        return null;
    }
    return $admin;
}

function touch_presence(int $userId): void
{
    $now = time();
    if (isset($_SESSION['presence_touch']) && ($now - (int) $_SESSION['presence_touch']) < 20) {
        return;
    }
    $statement = db()->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
    $statement->execute([$userId]);
    $_SESSION['presence_touch'] = $now;
}

function require_page_user(): array
{
    $user = current_user();
    if (!$user) {
        flash_set('warning', 'Please log in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_api_user(): array
{
    $user = current_user();
    if (!$user) {
        throw new AppException('Unauthorized', 401);
    }
    return $user;
}

function require_admin_page(): array
{
    $admin = current_admin();
    if (!$admin) {
        redirect('admin/login.php');
    }
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ((int) $admin['must_change_password'] === 1 && !in_array($script, ['index.php', 'logout.php'], true)) {
        redirect('admin/index.php');
    }
    return $admin;
}

function register_user(string $username, string $email, string $password, string $confirm): array
{
    $username = trim($username);
    $email = strtolower(trim($email));

    if (!valid_username($username)) {
        throw new AppException('Username must be 3-32 characters and use only letters, numbers, and underscores.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new AppException('Enter a valid email address.');
    }
    if (!valid_password($password)) {
        throw new AppException('Password must be 8-72 characters and include at least one letter and one number.');
    }
    if (!hash_equals($password, $confirm)) {
        throw new AppException('Password confirmation does not match.');
    }

    rate_limit('register', client_ip(), 5, 3600);

    $exists = db()->prepare('SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1');
    $exists->execute([$username, $email]);
    $row = $exists->fetch();
    if ($row) {
        if (strcasecmp((string) $row['username'], $username) === 0) {
            throw new AppException('That username is already taken.');
        }
        throw new AppException('That email is already registered.');
    }

    try {
        $insert = db()->prepare('INSERT INTO users (username, email, password_hash, status_message, last_seen) VALUES (?, ?, ?, ?, NOW())');
        $insert->execute([
            $username,
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            '',
        ]);
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'Duplicate')) {
            throw new AppException('Username or email is already in use.');
        }
        throw $exception;
    }

    $user = find_user((int) db()->lastInsertId());
    if (!$user) {
        throw new AppException('Unable to create the account. Please try again.');
    }
    login_session($user);
    return $user;
}

function login_user(string $identifier, string $password): array
{
    rate_limit('login', client_ip(), 8, 600);
    $user = find_user_by_login($identifier);
    $hash = $user['password_hash'] ?? DUMMY_PASSWORD_HASH;
    $valid = password_verify($password, $hash);
    if (!$user || !$valid) {
        throw new AppException('Incorrect username or password.', 401);
    }
    if ((int) $user['is_active'] !== 1) {
        throw new AppException('This account has been disabled.', 403);
    }
    login_session($user);
    $touch = db()->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
    $touch->execute([(int) $user['id']]);
    return find_user((int) $user['id']) ?? $user;
}

function login_session(array $user): void
{
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['wc_user_id'] = (int) $user['id'];
    unset($_SESSION['csrf_token'], $_SESSION['presence_touch']);
}

function logout_user(): void
{
    $userId = (int) ($_SESSION['wc_user_id'] ?? 0);
    if ($userId > 0) {
        $statement = db()->prepare("UPDATE users SET presence_mode = 'online' WHERE id = ?");
        $statement->execute([$userId]);
        $seen = db()->prepare('UPDATE users SET last_seen = DATE_SUB(NOW(), INTERVAL 2 MINUTE) WHERE id = ?');
        $seen->execute([$userId]);
    }
    unset($_SESSION['wc_user_id'], $_SESSION['csrf_token'], $_SESSION['presence_touch']);
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
}

function login_admin(string $identifier, string $password): array
{
    rate_limit('admin_login', client_ip(), 8, 600);
    $identifier = trim($identifier);
    if (str_contains($identifier, '@')) {
        $statement = db()->prepare('SELECT * FROM admins WHERE email = ? LIMIT 1');
        $statement->execute([strtolower($identifier)]);
    } else {
        $statement = db()->prepare('SELECT * FROM admins WHERE username = ? LIMIT 1');
        $statement->execute([$identifier]);
    }
    $admin = $statement->fetch();
    $hash = $admin['password_hash'] ?? DUMMY_PASSWORD_HASH;
    if (!$admin || !password_verify($password, $hash)) {
        throw new AppException('Incorrect administrator username or password.', 401);
    }
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
    $_SESSION['wc_admin_id'] = (int) $admin['id'];
    unset($_SESSION['csrf_token']);
    $update = db()->prepare('UPDATE admins SET last_login = NOW() WHERE id = ?');
    $update->execute([(int) $admin['id']]);
    return current_admin() ?? $admin;
}

function logout_admin(): void
{
    unset($_SESSION['wc_admin_id'], $_SESSION['csrf_token']);
    if (!headers_sent()) {
        session_regenerate_id(true);
    }
}

function change_user_password(int $userId, string $current, string $next, string $confirm): void
{
    $user = find_user($userId);
    if (!$user || !password_verify($current, (string) $user['password_hash'])) {
        throw new AppException('Current password is incorrect.');
    }
    if (!valid_password($next)) {
        throw new AppException('New password must be 8-72 characters and include at least one letter and one number.');
    }
    if (!hash_equals($next, $confirm)) {
        throw new AppException('Password confirmation does not match.');
    }
    $statement = db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $statement->execute([password_hash($next, PASSWORD_DEFAULT), $userId]);
}

function change_admin_password(int $adminId, string $current, string $next, string $confirm): void
{
    $statement = db()->prepare('SELECT * FROM admins WHERE id = ? LIMIT 1');
    $statement->execute([$adminId]);
    $admin = $statement->fetch();
    if (!$admin || !password_verify($current, (string) $admin['password_hash'])) {
        throw new AppException('Current password is incorrect.');
    }
    if (!valid_password($next)) {
        throw new AppException('New password must be 8-72 characters and include at least one letter and one number.');
    }
    if (!hash_equals($next, $confirm)) {
        throw new AppException('Password confirmation does not match.');
    }
    $update = db()->prepare('UPDATE admins SET password_hash = ?, must_change_password = 0 WHERE id = ?');
    $update->execute([password_hash($next, PASSWORD_DEFAULT), $adminId]);
}

function update_profile(int $userId, string $username, string $email, string $status): void
{
    $username = trim($username);
    $email = strtolower(trim($email));
    $status = trim($status);
    if (!valid_username($username)) {
        throw new AppException('Username must be 3-32 characters and use only letters, numbers, and underscores.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        throw new AppException('Enter a valid email address.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($status) > 160 : strlen($status) > 160) {
        throw new AppException('Status message must be 160 characters or fewer.');
    }

    $exists = db()->prepare('SELECT id, username, email FROM users WHERE (username = ? OR email = ?) AND id <> ? LIMIT 1');
    $exists->execute([$username, $email, $userId]);
    $row = $exists->fetch();
    if ($row) {
        if (strcasecmp((string) $row['username'], $username) === 0) {
            throw new AppException('That username is already taken.');
        }
        throw new AppException('That email is already registered.');
    }

    $statement = db()->prepare('UPDATE users SET username = ?, email = ?, status_message = ? WHERE id = ?');
    $statement->execute([$username, $email, $status, $userId]);
}

function update_avatar(int $userId, array $file): void
{
    $user = find_user($userId);
    if (!$user) {
        throw new AppException('Account not found.', 404);
    }
    $stored = store_uploaded_file($file, 'avatars', $userId, image_mime_map(), UPLOAD_MAX_AVATAR);
    $statement = db()->prepare('UPDATE users SET avatar_path = ? WHERE id = ?');
    $statement->execute([$stored['relative'], $userId]);
    if (!empty($user['avatar_path']) && $user['avatar_path'] !== $stored['relative']) {
        delete_upload((string) $user['avatar_path']);
    }
}

function update_presence_mode(int $userId, string $mode): void
{
    if (!in_array($mode, ['online', 'away', 'busy'], true)) {
        throw new AppException('Choose online, away, or busy.');
    }
    $statement = db()->prepare('UPDATE users SET presence_mode = ?, last_seen = NOW() WHERE id = ?');
    $statement->execute([$mode, $userId]);
    $_SESSION['presence_touch'] = time();
}
