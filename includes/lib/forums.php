<?php
declare(strict_types=1);

function list_forums(): array
{
    $statement = db()->query(
        'SELECT f.*,
            (SELECT COUNT(*) FROM forum_posts p WHERE p.forum_id = f.id AND p.is_hidden = 0) AS post_count
         FROM forums f
         ORDER BY f.sort_order ASC, f.name ASC'
    );
    return $statement->fetchAll();
}

function find_forum_by_slug(string $slug): ?array
{
    $statement = db()->prepare('SELECT * FROM forums WHERE slug = ? LIMIT 1');
    $statement->execute([$slug]);
    $forum = $statement->fetch();
    return $forum ?: null;
}

function find_forum(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM forums WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $forum = $statement->fetch();
    return $forum ?: null;
}

function list_forum_posts(int $forumId): array
{
    $statement = db()->prepare(
        'SELECT p.*, u.username, u.avatar_path, u.status_message, u.presence_mode, u.last_seen, u.is_active,
            (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM post_shares s WHERE s.post_id = p.id) AS share_count
         FROM forum_posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.forum_id = ? AND p.is_hidden = 0
         ORDER BY p.id DESC
         LIMIT 50'
    );
    $statement->execute([$forumId]);
    return array_map('format_post_row', $statement->fetchAll());
}

function format_post_row(array $row): array
{
    $author = 'WebConnect Team';
    $avatar = app_url('api/media.php?type=initials&name=' . rawurlencode('WebConnect'));
    if (!empty($row['user_id']) && !empty($row['username'])) {
        $author = (string) $row['username'];
        $avatar = avatar_url([
            'id' => (int) $row['user_id'],
            'username' => $row['username'],
            'avatar_path' => $row['avatar_path'] ?? null,
        ]);
    } elseif (!empty($row['user_id']) && empty($row['username'])) {
        $author = 'Former member';
    }
    return [
        'id' => (int) $row['id'],
        'forum_id' => (int) $row['forum_id'],
        'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
        'title' => (string) $row['title'],
        'content' => (string) $row['content'],
        'author' => $author,
        'avatar_url' => $avatar,
        'is_hidden' => (int) ($row['is_hidden'] ?? 0),
        'created_at' => $row['created_at'],
        'created_label' => time_ago($row['created_at'] ?? null),
        'comment_count' => (int) ($row['comment_count'] ?? 0),
        'like_count' => (int) ($row['like_count'] ?? 0),
        'share_count' => (int) ($row['share_count'] ?? 0),
        'liked_by_me' => (int) ($row['liked_by_me'] ?? 0) === 1,
    ];
}

function get_forum_post(int $postId, int $viewerId): array
{
    $statement = db()->prepare(
        'SELECT p.*, u.username, u.avatar_path, u.status_message, u.presence_mode, u.last_seen, u.is_active,
            (SELECT COUNT(*) FROM post_comments c WHERE c.post_id = p.id) AS comment_count,
            (SELECT COUNT(*) FROM post_likes l WHERE l.post_id = p.id) AS like_count,
            (SELECT COUNT(*) FROM post_shares s WHERE s.post_id = p.id) AS share_count,
            EXISTS(SELECT 1 FROM post_likes ml WHERE ml.post_id = p.id AND ml.user_id = ?) AS liked_by_me
         FROM forum_posts p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE p.id = ? AND p.is_hidden = 0
         LIMIT 1'
    );
    $statement->execute([$viewerId, $postId]);
    $post = $statement->fetch();
    if (!$post) {
        throw new AppException('That discussion could not be found.', 404);
    }
    $comments = db()->prepare(
        'SELECT c.*, u.username, u.avatar_path
         FROM post_comments c
         LEFT JOIN users u ON u.id = c.user_id
         WHERE c.post_id = ?
         ORDER BY c.id ASC
         LIMIT 200'
    );
    $comments->execute([$postId]);
    $commentRows = [];
    foreach ($comments->fetchAll() as $comment) {
        $name = 'Former member';
        $avatar = app_url('api/media.php?type=initials&name=' . rawurlencode('Member'));
        if (!empty($comment['username'])) {
            $name = (string) $comment['username'];
            $avatar = avatar_url([
                'id' => (int) $comment['user_id'],
                'username' => $comment['username'],
                'avatar_path' => $comment['avatar_path'] ?? null,
            ]);
        }
        $commentRows[] = [
            'id' => (int) $comment['id'],
            'user_id' => $comment['user_id'] !== null ? (int) $comment['user_id'] : null,
            'author' => $name,
            'avatar_url' => $avatar,
            'content' => (string) $comment['content'],
            'created_at' => $comment['created_at'],
            'created_label' => time_ago($comment['created_at']),
            'mine' => (int) $comment['user_id'] === $viewerId,
        ];
    }
    $formatted = format_post_row($post);
    $formatted['comments'] = $commentRows;
    $formatted['share_url'] = app_url('forums.php?post=' . $postId);
    $formatted['mine'] = $formatted['user_id'] !== null && $formatted['user_id'] === $viewerId;
    return $formatted;
}

function create_forum_post(array $user, int $forumId, string $title, string $content): int
{
    $forum = find_forum($forumId);
    if (!$forum) {
        throw new AppException('Choose a forum category.', 404);
    }
    if ((int) $forum['member_can_post'] !== 1) {
        throw new AppException('Only administrators can post in this category.', 403);
    }
    $title = trim($title);
    $content = trim($content);
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    $contentLength = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
    if ($titleLength < 4 || $titleLength > 160) {
        throw new AppException('Title must be 4-160 characters.');
    }
    if ($contentLength < 4 || $contentLength > 5000) {
        throw new AppException('Post content must be 4-5000 characters.');
    }
    rate_limit('forum_post', 'user:' . (int) $user['id'], 10, 3600);
    $insert = db()->prepare('INSERT INTO forum_posts (forum_id, user_id, title, content) VALUES (?, ?, ?, ?)');
    $insert->execute([$forumId, (int) $user['id'], $title, $content]);
    return (int) db()->lastInsertId();
}

function create_comment(array $user, int $postId, string $content): void
{
    get_forum_post($postId, (int) $user['id']);
    $content = trim($content);
    $length = function_exists('mb_strlen') ? mb_strlen($content) : strlen($content);
    if ($length < 1 || $length > 2000) {
        throw new AppException('Comment must be 1-2000 characters.');
    }
    rate_limit('forum_comment', 'user:' . (int) $user['id'], 30, 3600);
    $insert = db()->prepare('INSERT INTO post_comments (post_id, user_id, content) VALUES (?, ?, ?)');
    $insert->execute([$postId, (int) $user['id'], $content]);

    $post = db()->prepare('SELECT user_id, title FROM forum_posts WHERE id = ?');
    $post->execute([$postId]);
    $row = $post->fetch();
    if ($row && $row['user_id'] && (int) $row['user_id'] !== (int) $user['id']) {
        create_notification(
            (int) $row['user_id'],
            'forum_comment',
            'New comment',
            $user['username'] . ' commented on "' . str_limit((string) $row['title'], 80) . '".',
            'forums.php?post=' . $postId,
            (int) $user['id']
        );
    }
}

function delete_comment(int $userId, int $commentId): void
{
    $statement = db()->prepare('DELETE FROM post_comments WHERE id = ? AND user_id = ?');
    $statement->execute([$commentId, $userId]);
    if ($statement->rowCount() === 0) {
        throw new AppException('You can only delete your own comments.', 403);
    }
}

function delete_forum_post(int $userId, int $postId): array
{
    $statement = db()->prepare('SELECT id, forum_id, user_id FROM forum_posts WHERE id = ? LIMIT 1');
    $statement->execute([$postId]);
    $post = $statement->fetch();
    if (!$post) {
        throw new AppException('That discussion could not be found.', 404);
    }
    $delete = db()->prepare('DELETE FROM forum_posts WHERE id = ?');
    $delete->execute([$postId]);
    if ($delete->rowCount() === 0) {
        throw new AppException('Unable to delete this discussion.', 403);
    }
    $forum = find_forum((int) $post['forum_id']);
    return [
        'forum_id' => (int) $post['forum_id'],
        'forum_slug' => $forum ? (string) $forum['slug'] : '',
    ];
}

function toggle_post_like(int $userId, int $postId): array
{
    get_forum_post($postId, $userId);
    $existing = db()->prepare('SELECT id FROM post_likes WHERE post_id = ? AND user_id = ? LIMIT 1');
    $existing->execute([$postId, $userId]);
    if ($existing->fetch()) {
        $delete = db()->prepare('DELETE FROM post_likes WHERE post_id = ? AND user_id = ?');
        $delete->execute([$postId, $userId]);
    } else {
        $insert = db()->prepare('INSERT INTO post_likes (post_id, user_id) VALUES (?, ?)');
        $insert->execute([$postId, $userId]);
    }
    $count = db()->prepare('SELECT COUNT(*) FROM post_likes WHERE post_id = ?');
    $count->execute([$postId]);
    $mine = db()->prepare('SELECT id FROM post_likes WHERE post_id = ? AND user_id = ? LIMIT 1');
    $mine->execute([$postId, $userId]);
    return ['like_count' => (int) $count->fetchColumn(), 'liked_by_me' => (bool) $mine->fetch()];
}

function share_post(int $userId, int $postId): array
{
    get_forum_post($postId, $userId);
    rate_limit('forum_share', 'user:' . $userId, 30, 3600);
    $insert = db()->prepare('INSERT INTO post_shares (post_id, user_id) VALUES (?, ?)');
    $insert->execute([$postId, $userId]);
    $count = db()->prepare('SELECT COUNT(*) FROM post_shares WHERE post_id = ?');
    $count->execute([$postId]);
    return [
        'share_count' => (int) $count->fetchColumn(),
        'share_url' => app_url('forums.php?post=' . $postId),
    ];
}
