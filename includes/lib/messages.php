<?php
declare(strict_types=1);

function call_log_meta(?string $body): array
{
    $decoded = json_decode((string) $body, true);
    $type = is_array($decoded) && ($decoded['call_type'] ?? '') === 'video' ? 'video' : 'voice';
    $status = is_array($decoded) ? (string) ($decoded['status'] ?? 'ended') : 'ended';
    if (!in_array($status, ['ended', 'missed', 'rejected', 'cancelled'], true)) {
        $status = 'ended';
    }
    $seconds = is_array($decoded) ? max(0, (int) ($decoded['seconds'] ?? 0)) : 0;
    return [
        'call_type' => $type,
        'status' => $status,
        'seconds' => $seconds,
    ];
}

function format_call_clock(int $seconds): string
{
    $seconds = max(0, $seconds);
    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);
    $rest = $seconds % 60;
    if ($hours > 0) {
        return $hours . ':' . str_pad((string) $minutes, 2, '0', STR_PAD_LEFT) . ':' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
    }
    return $minutes . ':' . str_pad((string) $rest, 2, '0', STR_PAD_LEFT);
}

function call_log_summary(?string $body): string
{
    $meta = call_log_meta($body);
    $kind = $meta['call_type'] === 'video' ? 'Video call' : 'Voice call';
    return match ($meta['status']) {
        'missed' => 'Missed ' . strtolower($kind),
        'rejected' => $kind . ' declined',
        'cancelled' => $kind . ' cancelled',
        default => $kind . ' · ' . format_call_clock((int) $meta['seconds']),
    };
}

function call_log_card(string $body, bool $outgoing): array
{
    $meta = call_log_meta($body);
    $kind = $meta['call_type'] === 'video' ? 'Video call' : 'Voice call';
    $title = $kind;
    $detail = format_call_clock((int) $meta['seconds']);
    $missed = false;
    if ($meta['status'] === 'missed') {
        if ($outgoing) {
            $detail = 'No answer';
        } else {
            $title = 'Missed ' . strtolower($kind);
            $detail = '';
            $missed = true;
        }
    } elseif ($meta['status'] === 'rejected') {
        $detail = 'Declined';
    } elseif ($meta['status'] === 'cancelled') {
        if ($outgoing) {
            $detail = 'Cancelled';
        } else {
            $title = 'Missed ' . strtolower($kind);
            $detail = '';
            $missed = true;
        }
    }
    return [
        'kind' => $meta['call_type'],
        'title' => $title,
        'detail' => $detail,
        'missed' => $missed,
    ];
}

function message_excerpt(?string $body, ?string $type, ?string $fileName, bool $deleted): string
{
    if ($deleted) {
        return 'Deleted message';
    }
    if ($type === 'call') {
        return call_log_summary($body);
    }
    $body = trim((string) $body);
    if ($body !== '') {
        return str_limit($body, 80);
    }
    return match ($type) {
        'image' => 'Photo',
        'file' => $fileName ? str_limit($fileName, 80) : 'File',
        'voice' => 'Voice message',
        default => 'Message',
    };
}

function load_reaction_map(string $table, array $messageIds, int $viewerId): array
{
    if (!in_array($table, ['message_reactions', 'group_message_reactions'], true) || $messageIds === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $statement = db()->prepare("SELECT message_id, emoji, user_id FROM {$table} WHERE message_id IN ({$placeholders})");
    $statement->execute(array_map('intval', $messageIds));
    $map = [];
    foreach ($statement->fetchAll() as $row) {
        $messageId = (int) $row['message_id'];
        $emoji = (string) $row['emoji'];
        if (!isset($map[$messageId][$emoji])) {
            $map[$messageId][$emoji] = ['emoji' => $emoji, 'count' => 0, 'mine' => false];
        }
        $map[$messageId][$emoji]['count']++;
        if ((int) $row['user_id'] === $viewerId) {
            $map[$messageId][$emoji]['mine'] = true;
        }
    }
    return $map;
}

function format_chat_message(array $row, int $viewerId, string $mediaType, array $reactions, int $seenCount = 0): array
{
    $deleted = (int) ($row['is_deleted'] ?? 0) === 1;
    $id = (int) $row['id'];
    $sender = [
        'id' => (int) $row['sender_id'],
        'username' => (string) ($row['sender_username'] ?? ''),
        'avatar_path' => $row['sender_avatar'] ?? null,
        'status_message' => (string) ($row['sender_status'] ?? ''),
        'presence_mode' => (string) ($row['presence_mode'] ?? 'online'),
        'last_seen' => $row['last_seen'] ?? null,
        'is_active' => (int) ($row['sender_active'] ?? 1),
    ];
    $replyDeleted = (int) ($row['reply_deleted'] ?? 0) === 1 || ($row['reply_to_id'] && !isset($row['reply_type']) && empty($row['reply_body']));
    $hasReply = !empty($row['reply_to_id']);
    if ($hasReply && !array_key_exists('reply_type', $row) && !array_key_exists('reply_deleted', $row)) {
        $replyDeleted = true;
    }
    $replyGone = $hasReply && ($replyDeleted || (int) ($row['reply_deleted'] ?? 0) === 1);
    $replyType = $hasReply && !$replyGone ? (string) ($row['reply_type'] ?? 'text') : null;
    $replyId = $hasReply ? (int) $row['reply_to_id'] : null;

    return [
        'id' => $id,
        'sender_id' => (int) $row['sender_id'],
        'sender_username' => $sender['username'],
        'sender_avatar' => avatar_url($sender),
        'message_type' => $deleted ? 'text' : (string) $row['message_type'],
        'body' => $deleted || (string) ($row['message_type'] ?? '') === 'call' ? null : $row['body'],
        'call_log' => $deleted || (string) ($row['message_type'] ?? '') !== 'call'
            ? null
            : call_log_card((string) ($row['body'] ?? ''), (int) $row['sender_id'] === $viewerId),
        'file_url' => (!$deleted && !empty($row['file_path'])) ? app_url('api/media.php?type=' . $mediaType . '&id=' . $id) : null,
        'file_name' => $deleted ? null : $row['file_name'],
        'mime_type' => $deleted ? null : $row['mime_type'],
        'file_size' => $deleted ? null : ($row['file_size'] !== null ? (int) $row['file_size'] : null),
        'file_size_label' => (!$deleted && $row['file_size'] !== null) ? format_bytes((int) $row['file_size']) : null,
        'voice_duration' => $deleted ? null : ($row['voice_duration'] !== null ? (int) $row['voice_duration'] : null),
        'reply_to_id' => $replyId,
        'reply_type' => $replyType,
        'reply_sender' => $hasReply ? (string) ($row['reply_sender_username'] ?? '') : null,
        'reply_excerpt' => $hasReply ? message_excerpt($row['reply_body'] ?? null, $row['reply_type'] ?? null, $row['reply_file_name'] ?? null, $replyGone) : null,
        'reply_image_url' => ($replyId && $replyType === 'image' && !empty($row['reply_file_path']))
            ? app_url('api/media.php?type=' . $mediaType . '&id=' . $replyId)
            : null,
        'reply_deleted' => $hasReply ? $replyGone : false,
        'delivery_status' => $row['delivery_status'] ?? null,
        'seen_count' => $seenCount,
        'is_deleted' => $deleted,
        'mine' => (int) $row['sender_id'] === $viewerId,
        'created_at' => $row['created_at'],
        'created_label' => time_ago($row['created_at'] ?? null),
        'reactions' => array_values($reactions),
    ];
}

function private_message_select(): string
{
    return 'SELECT m.*,
            u.username AS sender_username,
            u.avatar_path AS sender_avatar,
            u.status_message AS sender_status,
            u.presence_mode,
            u.last_seen,
            u.is_active AS sender_active,
            r.body AS reply_body,
            r.message_type AS reply_type,
            r.is_deleted AS reply_deleted,
            r.file_name AS reply_file_name,
            r.file_path AS reply_file_path,
            ru.username AS reply_sender_username
        FROM messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN messages r ON r.id = m.reply_to_id
        LEFT JOIN users ru ON ru.id = r.sender_id';
}

function hydrate_private_messages(array $rows, int $viewerId): array
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $reactions = load_reaction_map('message_reactions', $ids, $viewerId);
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = format_chat_message($row, $viewerId, 'message', $reactions[(int) $row['id']] ?? []);
    }
    return $messages;
}

function mark_incoming_delivered(int $userId): void
{
    $statement = db()->prepare(
        "UPDATE messages SET delivery_status = 'delivered'
         WHERE receiver_id = ? AND delivery_status = 'sent' AND is_deleted = 0"
    );
    $statement->execute([$userId]);
}

function mark_conversation_read(int $userId, int $otherId): void
{
    $pdo = db();
    $select = $pdo->prepare(
        "SELECT id FROM messages
         WHERE receiver_id = ? AND sender_id = ? AND is_deleted = 0 AND delivery_status <> 'read'"
    );
    $select->execute([$userId, $otherId]);
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $select->fetchAll());
    if ($ids === []) {
        return;
    }
    $update = $pdo->prepare(
        "UPDATE messages SET delivery_status = 'read'
         WHERE receiver_id = ? AND sender_id = ? AND is_deleted = 0 AND delivery_status <> 'read'"
    );
    $update->execute([$userId, $otherId]);
    $insert = $pdo->prepare(
        'INSERT INTO message_read_receipts (message_id, user_id, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = read_at'
    );
    foreach ($ids as $id) {
        $insert->execute([$id, $userId]);
    }
}

function private_visibility_sql(string $alias = 'm'): string
{
    return " {$alias}.id > COALESCE((
                SELECT c.cleared_before_id
                FROM private_chat_clears c
                WHERE c.user_id = ?
                  AND c.peer_id = IF({$alias}.sender_id = ?, {$alias}.receiver_id, {$alias}.sender_id)
            ), 0)
            AND NOT EXISTS (
                SELECT 1 FROM private_message_hides h
                WHERE h.user_id = ? AND h.message_id = {$alias}.id
            )";
}

function private_visibility_params(int $userId): array
{
    return [$userId, $userId, $userId];
}

function list_conversations(int $userId, string $query = ''): array
{
    mark_incoming_delivered($userId);
    $visibility = private_visibility_sql('m');
    $statement = db()->prepare(
        "SELECT m.id, m.sender_id, m.receiver_id, m.message_type, m.body, m.file_name, m.is_deleted, m.created_at
         FROM messages m
         INNER JOIN (
            SELECT MAX(m2.id) AS max_id
            FROM messages m2
            WHERE (m2.sender_id = ? OR m2.receiver_id = ?)
              AND m2.id > COALESCE((
                    SELECT c.cleared_before_id
                    FROM private_chat_clears c
                    WHERE c.user_id = ?
                      AND c.peer_id = IF(m2.sender_id = ?, m2.receiver_id, m2.sender_id)
              ), 0)
              AND NOT EXISTS (
                    SELECT 1 FROM private_message_hides h
                    WHERE h.user_id = ? AND h.message_id = m2.id
              )
            GROUP BY CASE WHEN m2.sender_id = ? THEN m2.receiver_id ELSE m2.sender_id END
         ) latest ON latest.max_id = m.id
         ORDER BY m.id DESC
         LIMIT 100"
    );
    $statement->execute([$userId, $userId, $userId, $userId, $userId, $userId]);
    $latest = $statement->fetchAll();

    $unreadStatement = db()->prepare(
        "SELECT sender_id, COUNT(*) AS unread_count
         FROM messages m
         WHERE receiver_id = ? AND is_deleted = 0 AND delivery_status <> 'read'
           AND {$visibility}
         GROUP BY sender_id"
    );
    $unreadStatement->execute(array_merge([$userId], private_visibility_params($userId)));
    $unread = [];
    foreach ($unreadStatement->fetchAll() as $row) {
        $unread[(int) $row['sender_id']] = (int) $row['unread_count'];
    }

    $peerIds = [];
    foreach ($latest as $row) {
        $peerIds[] = (int) $row['sender_id'] === $userId ? (int) $row['receiver_id'] : (int) $row['sender_id'];
    }
    $peers = users_by_ids($peerIds);
    $query = strtolower(trim($query));
    $conversations = [];
    $seen = [];
    foreach ($latest as $row) {
        $peerId = (int) $row['sender_id'] === $userId ? (int) $row['receiver_id'] : (int) $row['sender_id'];
        if (!isset($peers[$peerId]) || isset($seen[$peerId])) {
            continue;
        }
        if (is_blocked_either($userId, $peerId)) {
            continue;
        }
        $peer = user_public($peers[$peerId]);
        if ($query !== '' && !str_contains(strtolower($peer['username']), $query)) {
            continue;
        }
        $seen[$peerId] = true;
        $conversations[] = [
            'user' => $peer,
            'last_message' => message_excerpt(
                $row['body'] ?? null,
                $row['message_type'] ?? 'text',
                $row['file_name'] ?? null,
                (int) $row['is_deleted'] === 1
            ),
            'last_type' => (string) $row['message_type'],
            'last_at' => $row['created_at'],
            'last_label' => time_ago($row['created_at']),
            'unread' => $unread[$peerId] ?? 0,
            'mine' => (int) $row['sender_id'] === $userId,
        ];
    }

    // Friends with no visible messages still appear so chats are easy to open.
    foreach (list_friends($userId) as $friend) {
        $peerId = (int) $friend['id'];
        if (isset($seen[$peerId])) {
            continue;
        }
        if ($query !== '' && !str_contains(strtolower((string) $friend['username']), $query)) {
            continue;
        }
        $seen[$peerId] = true;
        $conversations[] = [
            'user' => $friend,
            'last_message' => 'No messages yet',
            'last_type' => 'text',
            'last_at' => null,
            'last_label' => '',
            'unread' => 0,
            'mine' => false,
        ];
    }

    return $conversations;
}

function users_by_ids(array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare("SELECT * FROM users WHERE id IN ({$placeholders})");
    $statement->execute($ids);
    $map = [];
    foreach ($statement->fetchAll() as $user) {
        $map[(int) $user['id']] = $user;
    }
    return $map;
}

function private_history(int $userId, int $otherId, int $beforeId = 0, int $limit = 50): array
{
    assert_not_blocked($userId, $otherId);
    $other = find_user($otherId);
    if (!$other) {
        throw new AppException('That user is not available.', 404);
    }
    $limit = clamp_int($limit, 1, 100);
    $sql = private_message_select() . '
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
          AND ' . private_visibility_sql('m');
    $params = array_merge([$userId, $otherId, $otherId, $userId], private_visibility_params($userId));
    if ($beforeId > 0) {
        $sql .= ' AND m.id < ?';
        $params[] = $beforeId;
    }
    $sql .= " ORDER BY m.id DESC LIMIT {$limit}";
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = array_reverse($statement->fetchAll());
    mark_conversation_read($userId, $otherId);
    return hydrate_private_messages($rows, $userId);
}

function private_poll(int $userId, int $otherId, int $sinceId): array
{
    assert_not_blocked($userId, $otherId);
    $other = find_user($otherId);
    if (!$other) {
        throw new AppException('That user is not available.', 404);
    }
    clear_expired_typing();
    mark_conversation_read($userId, $otherId);

    $params = [$userId, $otherId, $otherId, $userId];
    $sql = private_message_select() . '
        WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
          AND ' . private_visibility_sql('m');
    $params = array_merge($params, private_visibility_params($userId));
    if ($sinceId > 0) {
        $sql .= ' AND m.id > ?';
        $params[] = $sinceId;
        $sql .= ' ORDER BY m.id ASC LIMIT 100';
    } else {
        $sql .= ' ORDER BY m.id DESC LIMIT 50';
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    if ($sinceId === 0) {
        $rows = array_reverse($rows);
    }

    $statusStatement = db()->prepare(
        'SELECT id, delivery_status FROM messages
         WHERE sender_id = ? AND receiver_id = ? AND is_deleted = 0
         ORDER BY id DESC LIMIT 60'
    );
    $statusStatement->execute([$userId, $otherId]);

    $typing = db()->prepare(
        "SELECT u.username FROM typing_indicators t
         JOIN users u ON u.id = t.user_id
         WHERE t.context_type = 'private' AND t.user_id = ? AND t.target_id = ?
           AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 4 SECOND)"
    );
    $typing->execute([$otherId, $userId]);
    $deleted = db()->prepare(
        'SELECT id FROM messages
         WHERE is_deleted = 1 AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
         ORDER BY id DESC LIMIT 80'
    );
    $deleted->execute([$userId, $otherId, $otherId, $userId]);

    return [
        'messages' => hydrate_private_messages($rows, $userId),
        'statuses' => $statusStatement->fetchAll(),
        'deleted_ids' => array_map(static fn (array $row): int => (int) $row['id'], $deleted->fetchAll()),
        'typing' => array_map(static fn (array $row): string => (string) $row['username'], $typing->fetchAll()),
        'peer' => user_public($other),
        'can_message' => are_friends($userId, $otherId),
    ];
}

function set_typing(int $userId, string $context, int $targetId, bool $active): void
{
    if (!in_array($context, ['private', 'group'], true) || $targetId <= 0) {
        throw new AppException('Invalid typing target.');
    }
    if ($context === 'private') {
        assert_not_blocked($userId, $targetId);
        if (!are_friends($userId, $targetId)) {
            return;
        }
    } else {
        require_group_member($targetId, $userId);
    }

    if ($active) {
        $statement = db()->prepare(
            'INSERT INTO typing_indicators (user_id, context_type, target_id, updated_at)
             VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE updated_at = NOW()'
        );
        $statement->execute([$userId, $context, $targetId]);
        return;
    }
    $statement = db()->prepare('DELETE FROM typing_indicators WHERE user_id = ? AND context_type = ? AND target_id = ?');
    $statement->execute([$userId, $context, $targetId]);
}

function clear_expired_typing(): void
{
    db()->exec('DELETE FROM typing_indicators WHERE updated_at < DATE_SUB(NOW(), INTERVAL 10 SECOND)');
}

function send_private_message(array $sender, int $receiverId, string $type, string $body, ?int $replyTo, ?array $file, ?int $voiceDuration): array
{
    $senderId = (int) $sender['id'];
    rate_limit('message', 'user:' . $senderId, 30, 60);
    assert_can_contact($senderId, $receiverId);
    if (!in_array($type, ['text', 'image', 'file', 'voice'], true)) {
        throw new AppException('Unsupported message type.');
    }

    $body = trim($body);
    if (strlen($body) > 4000) {
        throw new AppException('Message is too long.');
    }
    if ($type === 'text' && $body === '') {
        throw new AppException('Enter a message before sending.');
    }

    $replyId = null;
    if ($replyTo) {
        $reply = db()->prepare(
            'SELECT id FROM messages
             WHERE id = ? AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
             LIMIT 1'
        );
        $reply->execute([$replyTo, $senderId, $receiverId, $receiverId, $senderId]);
        if (!$reply->fetch()) {
            throw new AppException('The message you are replying to is not in this conversation.', 404);
        }
        $replyId = $replyTo;
    }

    $stored = null;
    if ($type !== 'text') {
        if (!$file || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new AppException('Please choose a file.');
        }
        $stored = match ($type) {
            'image' => store_uploaded_file($file, 'images', $senderId, image_mime_map(), UPLOAD_MAX_IMAGE),
            'voice' => store_uploaded_file($file, 'voices', $senderId, voice_mime_map(), UPLOAD_MAX_VOICE),
            default => store_uploaded_file($file, 'files', $senderId, document_mime_map(), UPLOAD_MAX_FILE),
        };
    }

    $duration = $type === 'voice' ? clamp_int($voiceDuration ?? 0, 0, 600) : null;
    try {
        $insert = db()->prepare(
            'INSERT INTO messages
            (sender_id, receiver_id, message_type, body, file_path, file_name, mime_type, file_size, voice_duration, reply_to_id, delivery_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'sent\')'
        );
        $insert->execute([
            $senderId,
            $receiverId,
            $type,
            $body !== '' ? $body : null,
            $stored['relative'] ?? null,
            $stored['original'] ?? null,
            $stored['mime'] ?? null,
            $stored['size'] ?? null,
            $duration,
            $replyId,
        ]);
    } catch (Throwable $exception) {
        if ($stored) {
            delete_upload($stored['relative']);
        }
        throw $exception;
    }

    $messageId = (int) db()->lastInsertId();
    create_notification(
        $receiverId,
        'message',
        'New message',
        $sender['username'] . ': ' . message_excerpt($body, $type, $stored['original'] ?? null, false),
        'chat.php?with=' . $senderId,
        $senderId
    );

    $messages = private_history_by_ids([$messageId], $senderId);
    return $messages[0] ?? ['id' => $messageId];
}

function private_history_by_ids(array $ids, int $viewerId): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(private_message_select() . " WHERE m.id IN ({$placeholders})");
    $statement->execute($ids);
    return hydrate_private_messages($statement->fetchAll(), $viewerId);
}

function load_forwardable_message(string $scope, int $messageId, int $viewerId): array
{
    if ($scope === 'group') {
        $statement = db()->prepare('SELECT * FROM group_messages WHERE id = ? LIMIT 1');
        $statement->execute([$messageId]);
        $message = $statement->fetch();
        if (!$message || (int) $message['is_deleted'] === 1) {
            throw new AppException('Message not found.', 404);
        }
        require_group_member((int) $message['group_id'], $viewerId);
        return $message;
    }

    $statement = db()->prepare('SELECT * FROM messages WHERE id = ? LIMIT 1');
    $statement->execute([$messageId]);
    $message = $statement->fetch();
    if (!$message || (int) $message['is_deleted'] === 1) {
        throw new AppException('Message not found.', 404);
    }
    $participants = [(int) $message['sender_id'], (int) $message['receiver_id']];
    if (!in_array($viewerId, $participants, true) || is_blocked_either((int) $message['sender_id'], (int) $message['receiver_id'])) {
        throw new AppException('You do not have permission to forward this message.', 403);
    }
    return $message;
}

function prepare_forward_payload(array $source, int $senderId): array
{
    $type = (string) ($source['message_type'] ?? 'text');
    if (!in_array($type, ['text', 'image', 'file', 'voice'], true)) {
        throw new AppException('This message cannot be forwarded.');
    }
    $body = trim((string) ($source['body'] ?? ''));
    $stored = null;
    if ($type !== 'text') {
        if (empty($source['file_path'])) {
            throw new AppException('This attachment is no longer available.');
        }
        $stored = clone_upload_file(
            (string) $source['file_path'],
            $senderId,
            $source['file_name'] ?? null,
            $source['mime_type'] ?? null
        );
    } elseif ($body === '') {
        throw new AppException('This message cannot be forwarded.');
    }

    return [
        'type' => $type,
        'body' => $body,
        'stored' => $stored,
        'voice_duration' => $type === 'voice' ? clamp_int((int) ($source['voice_duration'] ?? 0), 0, 600) : null,
    ];
}

function deliver_forwarded_private(array $sender, int $receiverId, array $payload): array
{
    $senderId = (int) $sender['id'];
    assert_can_contact($senderId, $receiverId);
    $stored = $payload['stored'];
    try {
        $insert = db()->prepare(
            'INSERT INTO messages
            (sender_id, receiver_id, message_type, body, file_path, file_name, mime_type, file_size, voice_duration, reply_to_id, delivery_status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, \'sent\')'
        );
        $insert->execute([
            $senderId,
            $receiverId,
            $payload['type'],
            $payload['body'] !== '' ? $payload['body'] : null,
            $stored['relative'] ?? null,
            $stored['original'] ?? null,
            $stored['mime'] ?? null,
            $stored['size'] ?? null,
            $payload['voice_duration'],
        ]);
    } catch (Throwable $exception) {
        if ($stored) {
            delete_upload($stored['relative']);
        }
        throw $exception;
    }

    $messageId = (int) db()->lastInsertId();
    create_notification(
        $receiverId,
        'message',
        'New message',
        $sender['username'] . ': ' . message_excerpt($payload['body'], $payload['type'], $stored['original'] ?? null, false),
        'chat.php?with=' . $senderId,
        $senderId
    );
    $messages = private_history_by_ids([$messageId], $senderId);
    return $messages[0] ?? ['id' => $messageId];
}

function deliver_forwarded_group(array $sender, int $groupId, array $payload): array
{
    $senderId = (int) $sender['id'];
    require_group_member($groupId, $senderId);
    $stored = $payload['stored'];
    try {
        $insert = db()->prepare(
            'INSERT INTO group_messages
            (group_id, sender_id, message_type, body, file_path, file_name, mime_type, file_size, voice_duration, reply_to_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)'
        );
        $insert->execute([
            $groupId,
            $senderId,
            $payload['type'],
            $payload['body'] !== '' ? $payload['body'] : null,
            $stored['relative'] ?? null,
            $stored['original'] ?? null,
            $stored['mime'] ?? null,
            $stored['size'] ?? null,
            $payload['voice_duration'],
        ]);
    } catch (Throwable $exception) {
        if ($stored) {
            delete_upload($stored['relative']);
        }
        throw $exception;
    }

    $messageId = (int) db()->lastInsertId();
    $members = db()->prepare('SELECT user_id FROM group_members WHERE group_id = ? AND user_id <> ?');
    $members->execute([$groupId, $senderId]);
    $group = find_group($groupId);
    $preview = message_excerpt($payload['body'], $payload['type'], $stored['original'] ?? null, false);
    foreach ($members->fetchAll() as $member) {
        create_notification(
            (int) $member['user_id'],
            'group_message',
            'New group message',
            ($group['name'] ?? 'Group') . ': ' . $sender['username'] . ' — ' . $preview,
            'group_chat.php?id=' . $groupId,
            $senderId
        );
    }

    $statement = db()->prepare(group_message_select() . ' WHERE m.id = ?');
    $statement->execute([$messageId]);
    $row = $statement->fetch();
    $hydrated = $row ? hydrate_group_messages([$row], $senderId) : [];
    return $hydrated[0] ?? ['id' => $messageId];
}

function forward_chat_message(array $sender, string $sourceScope, int $messageId, array $targets): array
{
    $senderId = (int) $sender['id'];
    rate_limit('message', 'user:' . $senderId, 30, 60);
    if ($targets === []) {
        throw new AppException('Choose at least one chat to forward to.');
    }
    if (count($targets) > 10) {
        throw new AppException('You can forward to at most 10 chats at once.');
    }

    $source = load_forwardable_message($sourceScope === 'group' ? 'group' : 'private', $messageId, $senderId);
    $sent = [];
    foreach ($targets as $target) {
        if (!is_array($target)) {
            continue;
        }
        $kind = (string) ($target['type'] ?? $target['kind'] ?? '');
        $targetId = (int) ($target['id'] ?? 0);
        if ($targetId <= 0) {
            continue;
        }
        // Each destination needs its own file copy.
        $payload = prepare_forward_payload($source, $senderId);
        if ($kind === 'group') {
            $sent[] = [
                'type' => 'group',
                'id' => $targetId,
                'message' => deliver_forwarded_group($sender, $targetId, $payload),
            ];
        } elseif ($kind === 'user' || $kind === 'private') {
            $sent[] = [
                'type' => 'user',
                'id' => $targetId,
                'message' => deliver_forwarded_private($sender, $targetId, $payload),
            ];
        } else {
            if (!empty($payload['stored']['relative'])) {
                delete_upload($payload['stored']['relative']);
            }
            throw new AppException('Choose a valid forward destination.');
        }
    }

    if ($sent === []) {
        throw new AppException('Choose at least one chat to forward to.');
    }

    return ['sent' => $sent];
}

function delete_private_message(int $userId, int $messageId): void
{
    $statement = db()->prepare('SELECT * FROM messages WHERE id = ? AND sender_id = ? LIMIT 1');
    $statement->execute([$messageId, $userId]);
    $message = $statement->fetch();
    if (!$message) {
        throw new AppException('You can only delete your own messages.', 403);
    }
    if ((int) $message['is_deleted'] === 1) {
        return;
    }
    delete_upload($message['file_path'] ?? null);
    $update = db()->prepare(
        'UPDATE messages
         SET is_deleted = 1, deleted_at = NOW(), body = NULL, file_path = NULL, file_name = NULL, mime_type = NULL, file_size = NULL, voice_duration = NULL
         WHERE id = ?'
    );
    $update->execute([$messageId]);
}

function hide_private_messages_for_me(int $userId, array $messageIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if ($ids === []) {
        throw new AppException('Select at least one message to delete.');
    }
    if (count($ids) > 50) {
        throw new AppException('You can delete at most 50 messages at once.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(
        "SELECT id, sender_id, receiver_id FROM messages
         WHERE id IN ({$placeholders})
           AND (sender_id = ? OR receiver_id = ?)"
    );
    $statement->execute(array_merge($ids, [$userId, $userId]));
    $rows = $statement->fetchAll();
    if (count($rows) !== count($ids)) {
        throw new AppException('One or more messages could not be deleted.', 403);
    }
    $insert = db()->prepare(
        'INSERT IGNORE INTO private_message_hides (user_id, message_id) VALUES (?, ?)'
    );
    foreach ($rows as $row) {
        $insert->execute([$userId, (int) $row['id']]);
    }
    return ['hidden_ids' => array_map(static fn (array $row): int => (int) $row['id'], $rows)];
}

function clear_private_chat_for_me(int $userId, int $peerId): array
{
    assert_not_blocked($userId, $peerId);
    if ($peerId <= 0 || !find_user($peerId)) {
        throw new AppException('That user is not available.', 404);
    }
    $statement = db()->prepare(
        'SELECT COALESCE(MAX(id), 0) FROM messages
         WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)'
    );
    $statement->execute([$userId, $peerId, $peerId, $userId]);
    $beforeId = (int) $statement->fetchColumn();
    $upsert = db()->prepare(
        'INSERT INTO private_chat_clears (user_id, peer_id, cleared_before_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE cleared_before_id = GREATEST(cleared_before_id, VALUES(cleared_before_id)), created_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([$userId, $peerId, $beforeId]);
    return ['cleared_before_id' => $beforeId];
}

function delete_private_chat_for_me(int $userId, int $peerId): array
{
    return clear_private_chat_for_me($userId, $peerId);
}

function hide_group_messages_for_me(int $userId, int $groupId, array $messageIds): array
{
    require_group_member($groupId, $userId);
    $ids = array_values(array_unique(array_filter(array_map('intval', $messageIds))));
    if ($ids === []) {
        throw new AppException('Select at least one message to delete.');
    }
    if (count($ids) > 50) {
        throw new AppException('You can delete at most 50 messages at once.');
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare(
        "SELECT id FROM group_messages WHERE group_id = ? AND id IN ({$placeholders})"
    );
    $statement->execute(array_merge([$groupId], $ids));
    $rows = $statement->fetchAll();
    if (count($rows) !== count($ids)) {
        throw new AppException('One or more messages could not be deleted.', 403);
    }
    $insert = db()->prepare(
        'INSERT IGNORE INTO group_message_hides (user_id, message_id) VALUES (?, ?)'
    );
    foreach ($rows as $row) {
        $insert->execute([$userId, (int) $row['id']]);
    }
    return ['hidden_ids' => array_map(static fn (array $row): int => (int) $row['id'], $rows)];
}

function clear_group_chat_for_me(int $userId, int $groupId): array
{
    require_group_member($groupId, $userId);
    $statement = db()->prepare('SELECT COALESCE(MAX(id), 0) FROM group_messages WHERE group_id = ?');
    $statement->execute([$groupId]);
    $beforeId = (int) $statement->fetchColumn();
    $upsert = db()->prepare(
        'INSERT INTO group_chat_clears (user_id, group_id, cleared_before_id)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE cleared_before_id = GREATEST(cleared_before_id, VALUES(cleared_before_id)), created_at = CURRENT_TIMESTAMP'
    );
    $upsert->execute([$userId, $groupId, $beforeId]);
    return ['cleared_before_id' => $beforeId];
}

function toggle_reaction(string $table, string $messageTable, int $userId, int $messageId, string $emoji, bool $group): array
{
    if (!allowed_reaction($emoji)) {
        throw new AppException('That reaction is not available.');
    }
    if (!in_array($table, ['message_reactions', 'group_message_reactions'], true)) {
        throw new AppException('Invalid reaction target.');
    }

    if ($group) {
        $statement = db()->prepare('SELECT id, group_id, sender_id, is_deleted FROM group_messages WHERE id = ? LIMIT 1');
        $statement->execute([$messageId]);
        $message = $statement->fetch();
        if (!$message || (int) $message['is_deleted'] === 1) {
            throw new AppException('Message not found.', 404);
        }
        require_group_member((int) $message['group_id'], $userId);
        $ownerId = (int) $message['sender_id'];
        $link = 'group_chat.php?id=' . (int) $message['group_id'];
    } else {
        $statement = db()->prepare('SELECT id, sender_id, receiver_id, is_deleted FROM messages WHERE id = ? LIMIT 1');
        $statement->execute([$messageId]);
        $message = $statement->fetch();
        if (!$message || (int) $message['is_deleted'] === 1) {
            throw new AppException('Message not found.', 404);
        }
        $participants = [(int) $message['sender_id'], (int) $message['receiver_id']];
        if (!in_array($userId, $participants, true)) {
            throw new AppException('You cannot react to this message.', 403);
        }
        assert_not_blocked((int) $message['sender_id'], (int) $message['receiver_id']);
        $ownerId = (int) $message['sender_id'];
        $other = (int) $message['sender_id'] === $userId ? (int) $message['receiver_id'] : (int) $message['sender_id'];
        $link = 'chat.php?with=' . $other;
    }

    $existing = db()->prepare("SELECT emoji FROM {$table} WHERE message_id = ? AND user_id = ? LIMIT 1");
    $existing->execute([$messageId, $userId]);
    $current = $existing->fetch();
    if ($current && (string) $current['emoji'] === $emoji) {
        $delete = db()->prepare("DELETE FROM {$table} WHERE message_id = ? AND user_id = ?");
        $delete->execute([$messageId, $userId]);
    } else {
        $save = db()->prepare(
            "INSERT INTO {$table} (message_id, user_id, emoji) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)"
        );
        $save->execute([$messageId, $userId, $emoji]);
        if ($ownerId !== $userId) {
            $actor = find_user($userId);
            create_notification(
                $ownerId,
                'reaction',
                'Message reaction',
                ($actor['username'] ?? 'Someone') . ' reacted ' . $emoji . ' to your message.',
                $link,
                $userId
            );
        }
    }

    $map = load_reaction_map($table, [$messageId], $userId);
    return array_values($map[$messageId] ?? []);
}
