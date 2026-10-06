<?php
declare(strict_types=1);

function find_group(int $groupId): ?array
{
    $statement = db()->prepare('SELECT * FROM group_chats WHERE id = ? LIMIT 1');
    $statement->execute([$groupId]);
    $group = $statement->fetch();
    return $group ?: null;
}

function group_member(int $groupId, int $userId): ?array
{
    $statement = db()->prepare(
        'SELECT gm.*, u.username, u.avatar_path, u.status_message, u.presence_mode, u.last_seen, u.is_active
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ? AND gm.user_id = ?
         LIMIT 1'
    );
    $statement->execute([$groupId, $userId]);
    $member = $statement->fetch();
    return $member ?: null;
}

function require_group_member(int $groupId, int $userId): array
{
    $member = group_member($groupId, $userId);
    if (!$member) {
        throw new AppException('You are not a member of this group.', 403);
    }
    return $member;
}

function assert_group_manager(array $member): void
{
    if (!in_array($member['role'], ['owner', 'admin'], true)) {
        throw new AppException('You do not have permission to manage this group.', 403);
    }
}

function group_card(array $group, ?array $membership = null): array
{
    return [
        'id' => (int) $group['id'],
        'name' => (string) $group['name'],
        'description' => (string) ($group['description'] ?? ''),
        'avatar_url' => group_avatar_url($group),
        'owner_id' => (int) $group['owner_id'],
        'created_at' => $group['created_at'],
        'my_role' => $membership['role'] ?? null,
    ];
}

function list_groups(int $userId): array
{
    $statement = db()->prepare(
        'SELECT g.*, gm.role,
            (SELECT body FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_body,
            (SELECT message_type FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_type,
            (SELECT file_name FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_file,
            (SELECT is_deleted FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_deleted,
            (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1) AS last_at,
            (SELECT COUNT(*) FROM group_messages gm2
                WHERE gm2.group_id = g.id AND gm2.sender_id <> ? AND gm2.is_deleted = 0
                  AND NOT EXISTS (
                    SELECT 1 FROM group_message_reads r WHERE r.message_id = gm2.id AND r.user_id = ?
                  )
            ) AS unread_count
         FROM group_chats g
         JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = ?
         ORDER BY COALESCE(
            (SELECT created_at FROM group_messages WHERE group_id = g.id ORDER BY id DESC LIMIT 1),
            g.created_at
         ) DESC'
    );
    $statement->execute([$userId, $userId, $userId]);
    $groups = [];
    foreach ($statement->fetchAll() as $row) {
        $card = group_card($row, $row);
        $card['last_message'] = $row['last_at']
            ? message_excerpt($row['last_body'] ?? null, $row['last_type'] ?? 'text', $row['last_file'] ?? null, (int) ($row['last_deleted'] ?? 0) === 1)
            : 'No messages yet';
        $card['last_label'] = $row['last_at'] ? time_ago($row['last_at']) : '';
        $card['unread'] = (int) $row['unread_count'];
        $groups[] = $card;
    }
    return $groups;
}

function create_group(array $owner, string $name, string $description): array
{
    $name = trim($name);
    $description = trim($description);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($length < 2 || $length > 80) {
        throw new AppException('Group name must be 2-80 characters.');
    }
    $descLength = function_exists('mb_strlen') ? mb_strlen($description) : strlen($description);
    if ($descLength > 255) {
        throw new AppException('Description must be 255 characters or fewer.');
    }
    rate_limit('group_create', 'user:' . (int) $owner['id'], 10, 3600);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT INTO group_chats (name, description, owner_id) VALUES (?, ?, ?)');
        $insert->execute([$name, $description, (int) $owner['id']]);
        $groupId = (int) $pdo->lastInsertId();
        $member = $pdo->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'owner')");
        $member->execute([$groupId, (int) $owner['id']]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    $group = find_group($groupId);
    return group_card($group ?? ['id' => $groupId, 'name' => $name, 'description' => $description, 'owner_id' => (int) $owner['id'], 'avatar_path' => null, 'created_at' => date('Y-m-d H:i:s')], ['role' => 'owner']);
}

function update_group(int $userId, int $groupId, string $name, string $description): void
{
    $member = require_group_member($groupId, $userId);
    assert_group_manager($member);
    $name = trim($name);
    $description = trim($description);
    $length = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    if ($length < 2 || $length > 80) {
        throw new AppException('Group name must be 2-80 characters.');
    }
    if ((function_exists('mb_strlen') ? mb_strlen($description) : strlen($description)) > 255) {
        throw new AppException('Description must be 255 characters or fewer.');
    }
    $statement = db()->prepare('UPDATE group_chats SET name = ?, description = ? WHERE id = ?');
    $statement->execute([$name, $description, $groupId]);
}

function update_group_avatar(int $userId, int $groupId, array $file): void
{
    $member = require_group_member($groupId, $userId);
    assert_group_manager($member);
    $group = find_group($groupId);
    if (!$group) {
        throw new AppException('Group not found.', 404);
    }
    $stored = store_uploaded_file($file, 'groups', $userId, image_mime_map(), UPLOAD_MAX_AVATAR);
    $statement = db()->prepare('UPDATE group_chats SET avatar_path = ? WHERE id = ?');
    $statement->execute([$stored['relative'], $groupId]);
    if (!empty($group['avatar_path'])) {
        delete_upload((string) $group['avatar_path']);
    }
}

function delete_group(int $userId, int $groupId): void
{
    $member = require_group_member($groupId, $userId);
    if ($member['role'] !== 'owner') {
        throw new AppException('Only the owner can delete this group.', 403);
    }
    $group = find_group($groupId);
    if ($group && !empty($group['avatar_path'])) {
        delete_upload((string) $group['avatar_path']);
    }
    $paths = db()->prepare('SELECT file_path FROM group_messages WHERE group_id = ? AND file_path IS NOT NULL');
    $paths->execute([$groupId]);
    foreach ($paths->fetchAll() as $row) {
        delete_upload($row['file_path'] ?? null);
    }
    $statement = db()->prepare('DELETE FROM group_chats WHERE id = ?');
    $statement->execute([$groupId]);
}

function leave_group(int $userId, int $groupId): string
{
    $member = require_group_member($groupId, $userId);
    $countStatement = db()->prepare('SELECT COUNT(*) FROM group_members WHERE group_id = ?');
    $countStatement->execute([$groupId]);
    $count = (int) $countStatement->fetchColumn();
    if ($member['role'] === 'owner' && $count > 1) {
        throw new AppException('Transfer ownership before leaving the group.');
    }
    if ($count <= 1) {
        delete_group($userId, $groupId);
        return 'deleted';
    }
    $statement = db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
    $statement->execute([$groupId, $userId]);
    return 'left';
}

function add_group_member(int $actorId, int $groupId, int $newUserId): void
{
    $actor = require_group_member($groupId, $actorId);
    assert_group_manager($actor);
    if ($newUserId === $actorId) {
        throw new AppException('You are already in this group.');
    }
    $user = find_user($newUserId);
    if (!$user || (int) $user['is_active'] !== 1) {
        throw new AppException('That user is not available.', 404);
    }
    if (!are_friends($actorId, $newUserId) || is_blocked_either($actorId, $newUserId)) {
        throw new AppException('You can only add friends to a group.', 403);
    }
    if (group_member($groupId, $newUserId)) {
        throw new AppException('That user is already in the group.');
    }
    $insert = db()->prepare("INSERT INTO group_members (group_id, user_id, role) VALUES (?, ?, 'member')");
    $insert->execute([$groupId, $newUserId]);
    $group = find_group($groupId);
    create_notification(
        $newUserId,
        'group_invite',
        'Added to a group',
        ($actor['username'] ?? 'Someone') . ' added you to ' . ($group['name'] ?? 'a group') . '.',
        'group_chat.php?id=' . $groupId,
        $actorId
    );
}

function remove_group_member(int $actorId, int $groupId, int $targetId): void
{
    $actor = require_group_member($groupId, $actorId);
    $target = group_member($groupId, $targetId);
    if (!$target) {
        throw new AppException('That member is not in the group.', 404);
    }
    if ($targetId === $actorId) {
        throw new AppException('Use leave group to remove yourself.');
    }
    if ($target['role'] === 'owner') {
        throw new AppException('The owner cannot be removed.', 403);
    }
    if ($actor['role'] === 'admin' && $target['role'] !== 'member') {
        throw new AppException('Admins can only remove regular members.', 403);
    }
    if (!in_array($actor['role'], ['owner', 'admin'], true)) {
        throw new AppException('You do not have permission to remove members.', 403);
    }
    $statement = db()->prepare('DELETE FROM group_members WHERE group_id = ? AND user_id = ?');
    $statement->execute([$groupId, $targetId]);
}

function set_group_role(int $actorId, int $groupId, int $targetId, string $role): void
{
    if (!in_array($role, ['owner', 'admin', 'member'], true)) {
        throw new AppException('Choose a valid role.');
    }
    $actor = require_group_member($groupId, $actorId);
    if ($actor['role'] !== 'owner') {
        throw new AppException('Only the owner can change roles.', 403);
    }
    $target = group_member($groupId, $targetId);
    if (!$target) {
        throw new AppException('That member is not in the group.', 404);
    }
    if ($targetId === $actorId) {
        throw new AppException('Transfer ownership to another member instead of changing your own role.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($role === 'owner') {
            $demote = $pdo->prepare("UPDATE group_members SET role = 'admin' WHERE group_id = ? AND user_id = ?");
            $demote->execute([$groupId, $actorId]);
            $promote = $pdo->prepare("UPDATE group_members SET role = 'owner' WHERE group_id = ? AND user_id = ?");
            $promote->execute([$groupId, $targetId]);
            $owner = $pdo->prepare('UPDATE group_chats SET owner_id = ? WHERE id = ?');
            $owner->execute([$targetId, $groupId]);
        } else {
            $update = $pdo->prepare('UPDATE group_members SET role = ? WHERE group_id = ? AND user_id = ?');
            $update->execute([$role, $groupId, $targetId]);
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function list_group_members(int $groupId): array
{
    $statement = db()->prepare(
        'SELECT gm.role, gm.joined_at, u.*
         FROM group_members gm
         JOIN users u ON u.id = gm.user_id
         WHERE gm.group_id = ?
         ORDER BY FIELD(gm.role, \'owner\', \'admin\', \'member\'), u.username ASC'
    );
    $statement->execute([$groupId]);
    $members = [];
    foreach ($statement->fetchAll() as $row) {
        $card = user_public($row);
        $card['role'] = $row['role'];
        $card['joined_at'] = $row['joined_at'];
        $members[] = $card;
    }
    return $members;
}

function group_invite_candidates(int $groupId, int $userId): array
{
    $friends = list_friends($userId);
    $memberIds = [];
    foreach (list_group_members($groupId) as $member) {
        $memberIds[(int) $member['id']] = true;
    }
    return array_values(array_filter($friends, static function (array $friend) use ($memberIds, $userId): bool {
        return !isset($memberIds[(int) $friend['id']]) && !is_blocked_either($userId, (int) $friend['id']);
    }));
}

function group_message_select(): string
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
        FROM group_messages m
        JOIN users u ON u.id = m.sender_id
        LEFT JOIN group_messages r ON r.id = m.reply_to_id
        LEFT JOIN users ru ON ru.id = r.sender_id';
}

function group_seen_counts(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $statement = db()->prepare("SELECT message_id, COUNT(*) AS seen_count FROM group_message_reads WHERE message_id IN ({$placeholders}) GROUP BY message_id");
    $statement->execute($ids);
    $counts = [];
    foreach ($statement->fetchAll() as $row) {
        $counts[(int) $row['message_id']] = (int) $row['seen_count'];
    }
    return $counts;
}

function hydrate_group_messages(array $rows, int $viewerId): array
{
    $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
    $reactions = load_reaction_map('group_message_reactions', $ids, $viewerId);
    $seen = group_seen_counts($ids);
    $messages = [];
    foreach ($rows as $row) {
        $messages[] = format_chat_message($row, $viewerId, 'group_message', $reactions[(int) $row['id']] ?? [], $seen[(int) $row['id']] ?? 0);
    }
    return $messages;
}

function mark_group_read(int $userId, int $groupId, int $upToId): void
{
    if ($upToId <= 0) {
        return;
    }
    $statement = db()->prepare(
        'INSERT INTO group_message_reads (message_id, user_id, read_at)
         SELECT id, ?, NOW() FROM group_messages
         WHERE group_id = ? AND sender_id <> ? AND is_deleted = 0 AND id <= ?
         ON DUPLICATE KEY UPDATE read_at = read_at'
    );
    $statement->execute([$userId, $groupId, $userId, $upToId]);
}

function group_visibility_sql(string $alias = 'm'): string
{
    return " {$alias}.id > COALESCE((
                SELECT c.cleared_before_id FROM group_chat_clears c
                WHERE c.user_id = ? AND c.group_id = {$alias}.group_id
            ), 0)
            AND NOT EXISTS (
                SELECT 1 FROM group_message_hides h
                WHERE h.user_id = ? AND h.message_id = {$alias}.id
            )";
}

function group_history(int $userId, int $groupId, int $beforeId = 0): array
{
    require_group_member($groupId, $userId);
    $params = [$groupId, $userId, $userId];
    $sql = group_message_select() . ' WHERE m.group_id = ? AND ' . group_visibility_sql('m');
    if ($beforeId > 0) {
        $sql .= ' AND m.id < ?';
        $params[] = $beforeId;
    }
    $sql .= ' ORDER BY m.id DESC LIMIT 50';
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = array_reverse($statement->fetchAll());
    if ($rows) {
        mark_group_read($userId, $groupId, (int) $rows[count($rows) - 1]['id']);
    }
    return hydrate_group_messages($rows, $userId);
}

function group_poll(int $userId, int $groupId, int $sinceId): array
{
    require_group_member($groupId, $userId);
    clear_expired_typing();
    $params = [$groupId, $userId, $userId];
    $sql = group_message_select() . ' WHERE m.group_id = ? AND ' . group_visibility_sql('m');
    if ($sinceId > 0) {
        $sql .= ' AND m.id > ? ORDER BY m.id ASC LIMIT 100';
        $params[] = $sinceId;
    } else {
        $sql .= ' ORDER BY m.id DESC LIMIT 50';
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $rows = $statement->fetchAll();
    if ($sinceId === 0) {
        $rows = array_reverse($rows);
    }
    if ($rows) {
        $last = (int) $rows[count($rows) - 1]['id'];
        mark_group_read($userId, $groupId, $last);
        $rows = hydrate_group_messages($rows, $userId);
    } else {
        $rows = [];
    }

    $typing = db()->prepare(
        "SELECT u.username FROM typing_indicators t
         JOIN users u ON u.id = t.user_id
         WHERE t.context_type = 'group' AND t.target_id = ? AND t.user_id <> ?
           AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 4 SECOND)"
    );
    $typing->execute([$groupId, $userId]);
    $deleted = db()->prepare('SELECT id FROM group_messages WHERE group_id = ? AND is_deleted = 1 ORDER BY id DESC LIMIT 80');
    $deleted->execute([$groupId]);
    $group = find_group($groupId);

    return [
        'messages' => $rows,
        'deleted_ids' => array_map(static fn (array $row): int => (int) $row['id'], $deleted->fetchAll()),
        'typing' => array_map(static fn (array $row): string => (string) $row['username'], $typing->fetchAll()),
        'group' => $group ? group_card($group, group_member($groupId, $userId)) : null,
    ];
}

function send_group_message(array $sender, int $groupId, string $type, string $body, ?int $replyTo, ?array $file, ?int $voiceDuration): array
{
    $senderId = (int) $sender['id'];
    require_group_member($groupId, $senderId);
    rate_limit('message', 'user:' . $senderId, 30, 60);
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
        $reply = db()->prepare('SELECT id FROM group_messages WHERE id = ? AND group_id = ? LIMIT 1');
        $reply->execute([$replyTo, $groupId]);
        if (!$reply->fetch()) {
            throw new AppException('The message you are replying to is not in this group.', 404);
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

    try {
        $insert = db()->prepare(
            'INSERT INTO group_messages
            (group_id, sender_id, message_type, body, file_path, file_name, mime_type, file_size, voice_duration, reply_to_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $groupId,
            $senderId,
            $type,
            $body !== '' ? $body : null,
            $stored['relative'] ?? null,
            $stored['original'] ?? null,
            $stored['mime'] ?? null,
            $stored['size'] ?? null,
            $type === 'voice' ? clamp_int($voiceDuration ?? 0, 0, 600) : null,
            $replyId,
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
    $preview = message_excerpt($body, $type, $stored['original'] ?? null, false);
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

function delete_group_message(int $userId, int $messageId): void
{
    $statement = db()->prepare('SELECT * FROM group_messages WHERE id = ? AND sender_id = ? LIMIT 1');
    $statement->execute([$messageId, $userId]);
    $message = $statement->fetch();
    if (!$message) {
        throw new AppException('You can only delete your own messages.', 403);
    }
    require_group_member((int) $message['group_id'], $userId);
    if ((int) $message['is_deleted'] === 1) {
        return;
    }
    delete_upload($message['file_path'] ?? null);
    $update = db()->prepare(
        'UPDATE group_messages
         SET is_deleted = 1, deleted_at = NOW(), body = NULL, file_path = NULL, file_name = NULL, mime_type = NULL, file_size = NULL, voice_duration = NULL
         WHERE id = ?'
    );
    $update->execute([$messageId]);
}
