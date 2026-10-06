<?php
declare(strict_types=1);

function create_report(int $reporterId, string $targetType, int $targetId, string $reason): void
{
    if (!in_array($targetType, ['user', 'private_message', 'group_message', 'forum_post'], true)) {
        throw new AppException('Choose what you want to report.');
    }
    $reason = trim($reason);
    $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($length < 10 || $length > 500) {
        throw new AppException('Please describe the issue in 10-500 characters.');
    }
    rate_limit('report', 'user:' . $reporterId, 20, 3600);

    if (!report_target_visible($reporterId, $targetType, $targetId)) {
        throw new AppException('Unable to submit that report.', 403);
    }

    $existing = db()->prepare(
        "SELECT id FROM reports
         WHERE reporter_id = ? AND target_type = ? AND target_id = ? AND status = 'open'
         LIMIT 1"
    );
    $existing->execute([$reporterId, $targetType, $targetId]);
    if ($existing->fetch()) {
        throw new AppException('You already have an open report for this.');
    }

    $insert = db()->prepare(
        'INSERT INTO reports (reporter_id, target_type, target_id, reason, status) VALUES (?, ?, ?, ?, \'open\')'
    );
    $insert->execute([$reporterId, $targetType, $targetId, $reason]);
}

function report_target_visible(int $reporterId, string $targetType, int $targetId): bool
{
    if ($targetType === 'user') {
        if ($targetId === $reporterId) {
            return false;
        }
        $user = find_user($targetId);
        return (bool) $user;
    }
    if ($targetType === 'private_message') {
        $statement = db()->prepare('SELECT sender_id, receiver_id FROM messages WHERE id = ? LIMIT 1');
        $statement->execute([$targetId]);
        $message = $statement->fetch();
        if (!$message || (int) $message['sender_id'] === $reporterId) {
            return false;
        }
        return (int) $message['sender_id'] === $reporterId || (int) $message['receiver_id'] === $reporterId;
    }
    if ($targetType === 'group_message') {
        $statement = db()->prepare('SELECT group_id, sender_id FROM group_messages WHERE id = ? LIMIT 1');
        $statement->execute([$targetId]);
        $message = $statement->fetch();
        if (!$message || (int) $message['sender_id'] === $reporterId) {
            return false;
        }
        return (bool) group_member((int) $message['group_id'], $reporterId);
    }
    if ($targetType === 'forum_post') {
        $statement = db()->prepare('SELECT id, user_id, is_hidden FROM forum_posts WHERE id = ? LIMIT 1');
        $statement->execute([$targetId]);
        $post = $statement->fetch();
        if (!$post || (int) $post['is_hidden'] === 1) {
            return false;
        }
        return (int) ($post['user_id'] ?? 0) !== $reporterId;
    }
    return false;
}

function list_reports(?string $status = null): array
{
    $sql = 'SELECT r.*, u.username AS reporter_username
            FROM reports r
            LEFT JOIN users u ON u.id = r.reporter_id';
    $params = [];
    if ($status !== null && in_array($status, ['open', 'reviewed', 'resolved'], true)) {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT 200';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = [];
    foreach ($statement->fetchAll() as $report) {
        $report['target_label'] = report_target_label($report);
        $rows[] = $report;
    }
    return $rows;
}

function report_target_label(array $report): string
{
    $id = (int) $report['target_id'];
    return match ($report['target_type']) {
        'user' => user_label($id),
        'private_message' => message_label('messages', $id),
        'group_message' => message_label('group_messages', $id),
        'forum_post' => post_label($id),
        default => 'Content #' . $id,
    };
}

function user_label(int $id): string
{
    $user = find_user($id);
    return $user ? 'User ' . $user['username'] : 'Deleted user #' . $id;
}

function message_label(string $table, int $id): string
{
    if (!in_array($table, ['messages', 'group_messages'], true)) {
        return 'Message #' . $id;
    }
    $statement = db()->prepare("SELECT body, message_type, is_deleted, file_name FROM {$table} WHERE id = ? LIMIT 1");
    $statement->execute([$id]);
    $message = $statement->fetch();
    if (!$message) {
        return 'Removed message #' . $id;
    }
    return message_excerpt($message['body'] ?? null, $message['message_type'] ?? 'text', $message['file_name'] ?? null, (int) $message['is_deleted'] === 1);
}

function post_label(int $id): string
{
    $statement = db()->prepare('SELECT title, is_hidden FROM forum_posts WHERE id = ? LIMIT 1');
    $statement->execute([$id]);
    $post = $statement->fetch();
    if (!$post) {
        return 'Removed post #' . $id;
    }
    $title = (string) $post['title'];
    return (int) $post['is_hidden'] === 1 ? $title . ' (hidden)' : $title;
}

function review_report(int $adminId, int $reportId, string $status, string $note): void
{
    if (!in_array($status, ['open', 'reviewed', 'resolved'], true)) {
        throw new AppException('Choose a valid report status.');
    }
    $note = trim($note);
    if ((function_exists('mb_strlen') ? mb_strlen($note) : strlen($note)) > 500) {
        throw new AppException('Admin note must be 500 characters or fewer.');
    }
    $statement = db()->prepare(
        'UPDATE reports SET status = ?, admin_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?'
    );
    $statement->execute([$status, $note !== '' ? $note : null, $adminId, $reportId]);
    if ($statement->rowCount() === 0) {
        $exists = db()->prepare('SELECT id FROM reports WHERE id = ?');
        $exists->execute([$reportId]);
        if (!$exists->fetch()) {
            throw new AppException('Report not found.', 404);
        }
    }
}
