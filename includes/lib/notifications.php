<?php
declare(strict_types=1);

function create_notification(
    int $userId,
    string $type,
    string $title,
    string $body,
    ?string $link = null,
    ?int $relatedUserId = null,
    bool $coalesce = true
): void {
    if ($userId <= 0) {
        return;
    }
    if ($coalesce) {
        $statement = db()->prepare(
            'SELECT id FROM notifications
             WHERE user_id = ? AND type = ? AND is_read = 0
               AND ((? IS NULL AND related_user_id IS NULL) OR related_user_id = ?)
               AND created_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             LIMIT 1'
        );
        $statement->execute([$userId, $type, $relatedUserId, $relatedUserId]);
        if ($statement->fetch()) {
            return;
        }
    }

    $insert = db()->prepare(
        'INSERT INTO notifications (user_id, type, title, body, link_url, related_user_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $userId,
        str_limit($type, 40),
        str_limit($title, 140),
        str_limit($body, 255),
        $link !== null ? str_limit($link, 255) : null,
        $relatedUserId,
    ]);
}

function notification_unread_count(int $userId): int
{
    $statement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
    return (int) $statement->fetchColumn();
}

function list_notifications(int $userId, int $limit = 40): array
{
    $limit = clamp_int($limit, 1, 100);
    $statement = db()->prepare(
        "SELECT id, type, title, body, link_url, is_read, related_user_id, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $statement->execute([$userId]);
    return $statement->fetchAll();
}

function mark_notification_read(int $userId, int $notificationId): void
{
    $statement = db()->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    $statement->execute([$notificationId, $userId]);
}

function mark_all_notifications_read(int $userId): void
{
    $statement = db()->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
    $statement->execute([$userId]);
}
