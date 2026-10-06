<?php
declare(strict_types=1);

function admin_stats(): array
{
    $pdo = db();
    $totalUsers = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $disabledUsers = (int) $pdo->query('SELECT COUNT(*) FROM users WHERE is_active = 0')->fetchColumn();
    $activeUsers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE is_active = 1 AND last_seen >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
    $messages = (int) $pdo->query('SELECT COUNT(*) FROM messages WHERE is_deleted = 0')->fetchColumn()
        + (int) $pdo->query('SELECT COUNT(*) FROM group_messages WHERE is_deleted = 0')->fetchColumn();
    $groups = (int) $pdo->query('SELECT COUNT(*) FROM group_chats')->fetchColumn();
    $posts = (int) $pdo->query('SELECT COUNT(*) FROM forum_posts WHERE is_hidden = 0')->fetchColumn();
    $openReports = (int) $pdo->query("SELECT COUNT(*) FROM reports WHERE status = 'open'")->fetchColumn();
    $weekMessages = (int) $pdo->query("SELECT COUNT(*) FROM messages WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    return [
        'total_users' => $totalUsers,
        'active_users' => $activeUsers,
        'disabled_users' => $disabledUsers,
        'total_messages' => $messages,
        'total_groups' => $groups,
        'total_posts' => $posts,
        'open_reports' => $openReports,
        'messages_7_days' => $weekMessages,
    ];
}

function admin_list_users(string $query = ''): array
{
    $query = trim($query);
    if ($query === '') {
        $statement = db()->query('SELECT id, username, email, status_message, is_active, last_seen, created_at FROM users ORDER BY id DESC LIMIT 200');
        return $statement->fetchAll();
    }
    $like = '%' . like_escape($query) . '%';
    $statement = db()->prepare(
        'SELECT id, username, email, status_message, is_active, last_seen, created_at
         FROM users
         WHERE username LIKE ? ESCAPE \'\\\\\' OR email LIKE ? ESCAPE \'\\\\\'
         ORDER BY username ASC
         LIMIT 200'
    );
    $statement->execute([$like, $like]);
    return $statement->fetchAll();
}

function admin_set_user_active(int $userId, bool $active): void
{
    $user = find_user($userId);
    if (!$user) {
        throw new AppException('User not found.', 404);
    }
    $statement = db()->prepare('UPDATE users SET is_active = ? WHERE id = ?');
    $statement->execute([$active ? 1 : 0, $userId]);
}

function admin_delete_user(int $userId, string $confirmUsername): void
{
    $user = find_user($userId);
    if (!$user) {
        throw new AppException('User not found.', 404);
    }
    if (!hash_equals((string) $user['username'], trim($confirmUsername))) {
        throw new AppException('Type the username exactly to confirm deletion.');
    }
    $avatar = $user['avatar_path'] ?? null;
    $files = db()->prepare('SELECT file_path FROM messages WHERE (sender_id = ? OR receiver_id = ?) AND file_path IS NOT NULL');
    $files->execute([$userId, $userId]);
    $groupFiles = db()->prepare('SELECT file_path FROM group_messages WHERE sender_id = ? AND file_path IS NOT NULL');
    $groupFiles->execute([$userId]);
    $owned = db()->prepare('SELECT avatar_path FROM group_chats WHERE owner_id = ? AND avatar_path IS NOT NULL');
    $owned->execute([$userId]);
    $statement = db()->prepare('DELETE FROM users WHERE id = ?');
    $statement->execute([$userId]);
    delete_upload($avatar);
    foreach (array_merge($files->fetchAll(), $groupFiles->fetchAll(), $owned->fetchAll()) as $row) {
        delete_upload($row['file_path'] ?? $row['avatar_path'] ?? null);
    }
}

function admin_save_forum(?int $forumId, string $name, string $description, int $sortOrder, bool $memberCanPost): void
{
    $name = trim($name);
    $description = trim($description);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($length < 2 || $length > 80) {
        throw new AppException('Category name must be 2-80 characters.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
        throw new AppException('Description must be 255 characters or fewer.');
    }
    $slug = slugify($name);
    $sortOrder = clamp_int($sortOrder, 0, 999);
    if ($forumId) {
        $current = find_forum($forumId);
        if (!$current) {
            throw new AppException('Category not found.', 404);
        }
        $slug = unique_forum_slug($slug, $forumId);
        $statement = db()->prepare('UPDATE forums SET name = ?, slug = ?, description = ?, sort_order = ?, member_can_post = ? WHERE id = ?');
        $statement->execute([$name, $slug, $description, $sortOrder, $memberCanPost ? 1 : 0, $forumId]);
        return;
    }
    $slug = unique_forum_slug($slug, null);
    $statement = db()->prepare('INSERT INTO forums (name, slug, description, sort_order, member_can_post) VALUES (?, ?, ?, ?, ?)');
    $statement->execute([$name, $slug, $description, $sortOrder, $memberCanPost ? 1 : 0]);
}

function unique_forum_slug(string $slug, ?int $ignoreId): string
{
    $base = $slug;
    $suffix = 1;
    while (true) {
        $statement = db()->prepare('SELECT id FROM forums WHERE slug = ? AND (? IS NULL OR id <> ?) LIMIT 1');
        $statement->execute([$slug, $ignoreId, $ignoreId ?? 0]);
        if (!$statement->fetch()) {
            return $slug;
        }
        $suffix++;
        $slug = str_limit($base, 70) . '-' . $suffix;
    }
}

function admin_delete_forum(int $forumId): void
{
    $statement = db()->prepare('DELETE FROM forums WHERE id = ?');
    $statement->execute([$forumId]);
    if ($statement->rowCount() === 0) {
        throw new AppException('Category not found.', 404);
    }
}

function admin_create_announcement(int $forumId, string $title, string $content): void
{
    $forum = find_forum($forumId);
    if (!$forum) {
        throw new AppException('Category not found.', 404);
    }
    $title = trim($title);
    $content = trim($content);
    if ((function_exists('mb_strlen') ? mb_strlen($title) : strlen($title)) < 4) {
        throw new AppException('Title must be at least 4 characters.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($content) : strlen($content)) < 4) {
        throw new AppException('Content must be at least 4 characters.');
    }
    $insert = db()->prepare('INSERT INTO forum_posts (forum_id, user_id, title, content) VALUES (?, NULL, ?, ?)');
    $insert->execute([$forumId, str_limit($title, 160), str_limit($content, 5000)]);
}

function admin_set_post_hidden(int $postId, bool $hidden): void
{
    $statement = db()->prepare('UPDATE forum_posts SET is_hidden = ? WHERE id = ?');
    $statement->execute([$hidden ? 1 : 0, $postId]);
    if ($statement->rowCount() === 0) {
        $exists = db()->prepare('SELECT id FROM forum_posts WHERE id = ?');
        $exists->execute([$postId]);
        if (!$exists->fetch()) {
            throw new AppException('Post not found.', 404);
        }
    }
}

function admin_delete_post(int $postId): void
{
    $statement = db()->prepare('DELETE FROM forum_posts WHERE id = ?');
    $statement->execute([$postId]);
    if ($statement->rowCount() === 0) {
        throw new AppException('Post not found.', 404);
    }
}

function admin_list_posts(): array
{
    $statement = db()->query(
        'SELECT p.id, p.title, p.is_hidden, p.created_at, f.name AS forum_name, u.username
         FROM forum_posts p
         JOIN forums f ON f.id = p.forum_id
         LEFT JOIN users u ON u.id = p.user_id
         ORDER BY p.id DESC
         LIMIT 100'
    );
    return $statement->fetchAll();
}
