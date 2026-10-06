<?php
declare(strict_types=1);

function ordered_pair(int $a, int $b): array
{
    if ($a === $b) {
        throw new AppException('You cannot do that with your own account.');
    }
    return $a < $b ? [$a, $b] : [$b, $a];
}

function are_friends(int $a, int $b): bool
{
    if ($a === $b) {
        return false;
    }
    [$one, $two] = ordered_pair($a, $b);
    $statement = db()->prepare('SELECT id FROM friendships WHERE user_one = ? AND user_two = ? LIMIT 1');
    $statement->execute([$one, $two]);
    return (bool) $statement->fetch();
}

function is_blocked_either(int $a, int $b): bool
{
    $statement = db()->prepare(
        'SELECT id FROM user_blocks
         WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)
         LIMIT 1'
    );
    $statement->execute([$a, $b, $b, $a]);
    return (bool) $statement->fetch();
}

function blocked_by_me(int $me, int $other): bool
{
    $statement = db()->prepare('SELECT id FROM user_blocks WHERE blocker_id = ? AND blocked_id = ? LIMIT 1');
    $statement->execute([$me, $other]);
    return (bool) $statement->fetch();
}

function pending_request_between(int $a, int $b): ?array
{
    $statement = db()->prepare(
        "SELECT * FROM friend_requests
         WHERE status = 'pending' AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
         ORDER BY id DESC LIMIT 1"
    );
    $statement->execute([$a, $b, $b, $a]);
    $row = $statement->fetch();
    return $row ?: null;
}

function relationship_between(int $me, int $other): string
{
    if ($me === $other) {
        return 'self';
    }
    if (blocked_by_me($me, $other)) {
        return 'blocked';
    }
    if (are_friends($me, $other)) {
        return 'friend';
    }
    $pending = pending_request_between($me, $other);
    if (!$pending) {
        return 'none';
    }
    return (int) $pending['sender_id'] === $me ? 'outgoing' : 'incoming';
}

function assert_not_blocked(int $me, int $other): void
{
    if (is_blocked_either($me, $other)) {
        throw new AppException('You cannot contact this user.', 403);
    }
}

function assert_can_contact(int $me, int $other): void
{
    if ($me === $other) {
        throw new AppException('You cannot contact yourself.');
    }
    $otherUser = find_user($other);
    if (!$otherUser || (int) $otherUser['is_active'] !== 1) {
        throw new AppException('That user is not available.', 404);
    }
    assert_not_blocked($me, $other);
    if (!are_friends($me, $other)) {
        throw new AppException('You can only message and call friends.', 403);
    }
}

function users_share_group(int $a, int $b): bool
{
    if ($a <= 0 || $b <= 0 || $a === $b) {
        return false;
    }
    $statement = db()->prepare(
        'SELECT 1
         FROM group_members gm1
         INNER JOIN group_members gm2 ON gm1.group_id = gm2.group_id
         WHERE gm1.user_id = ? AND gm2.user_id = ?
         LIMIT 1'
    );
    $statement->execute([$a, $b]);
    return (bool) $statement->fetchColumn();
}

function assert_can_call(int $me, int $other): void
{
    if ($me === $other) {
        throw new AppException('You cannot call yourself.');
    }
    $otherUser = find_user($other);
    if (!$otherUser || (int) $otherUser['is_active'] !== 1) {
        throw new AppException('That user is not available.', 404);
    }
    assert_not_blocked($me, $other);
    if (!are_friends($me, $other) && !users_share_group($me, $other)) {
        throw new AppException('You can only call friends or people in your groups.', 403);
    }
}

function search_users(int $me, string $query): array
{
    $query = trim($query);
    if (strlen($query) < 2) {
        throw new AppException('Enter at least 2 characters to search.');
    }
    $like = '%' . like_escape($query) . '%';
    $statement = db()->prepare(
        'SELECT * FROM users
         WHERE is_active = 1 AND id <> ? AND (username LIKE ? ESCAPE \'\\\\\' OR email LIKE ? ESCAPE \'\\\\\')
         ORDER BY username ASC
         LIMIT 20'
    );
    $statement->execute([$me, $like, $like]);
    $results = [];
    foreach ($statement->fetchAll() as $user) {
        $card = user_public($user);
        $card['relationship'] = relationship_between($me, (int) $user['id']);
        $results[] = $card;
    }
    return $results;
}

function list_friends(int $me): array
{
    $statement = db()->prepare(
        'SELECT u.*
         FROM friendships f
         JOIN users u ON u.id = IF(f.user_one = ?, f.user_two, f.user_one)
         WHERE f.user_one = ? OR f.user_two = ?
         ORDER BY u.username ASC'
    );
    $statement->execute([$me, $me, $me]);
    $friends = [];
    foreach ($statement->fetchAll() as $user) {
        $card = user_public($user);
        $card['relationship'] = 'friend';
        $friends[] = $card;
    }
    return $friends;
}

function list_friend_requests(int $me, string $direction): array
{
    if ($direction === 'incoming') {
        $sql = 'SELECT r.id AS request_id, r.created_at AS requested_at, u.*
                FROM friend_requests r
                JOIN users u ON u.id = r.sender_id
                WHERE r.receiver_id = ? AND r.status = \'pending\'
                ORDER BY r.id DESC';
    } else {
        $sql = 'SELECT r.id AS request_id, r.created_at AS requested_at, u.*
                FROM friend_requests r
                JOIN users u ON u.id = r.receiver_id
                WHERE r.sender_id = ? AND r.status = \'pending\'
                ORDER BY r.id DESC';
    }
    $statement = db()->prepare($sql);
    $statement->execute([$me]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $card = user_public($row);
        $card['request_id'] = (int) $row['request_id'];
        $card['requested_at'] = $row['requested_at'];
        $rows[] = $card;
    }
    return $rows;
}

function send_friend_request(int $me, int $receiverId): void
{
    if ($me === $receiverId) {
        throw new AppException('You cannot add yourself.');
    }
    $receiver = find_user($receiverId);
    if (!$receiver || (int) $receiver['is_active'] !== 1) {
        throw new AppException('That user is not available.', 404);
    }
    if (is_blocked_either($me, $receiverId)) {
        throw new AppException('Unable to send a friend request.', 403);
    }
    if (are_friends($me, $receiverId)) {
        throw new AppException('You are already friends.');
    }
    if (pending_request_between($me, $receiverId)) {
        throw new AppException('A friend request is already pending.');
    }

    rate_limit('friend_request', 'user:' . $me, 20, 3600);
    $insert = db()->prepare('INSERT INTO friend_requests (sender_id, receiver_id, status) VALUES (?, ?, \'pending\')');
    $insert->execute([$me, $receiverId]);
    $sender = find_user($me);
    create_notification(
        $receiverId,
        'friend_request',
        'New friend request',
        ($sender['username'] ?? 'Someone') . ' sent you a friend request.',
        'friends.php',
        $me
    );
}

function accept_friend_request(int $me, int $requestId): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT * FROM friend_requests WHERE id = ? FOR UPDATE');
        $statement->execute([$requestId]);
        $request = $statement->fetch();
        if (!$request || (int) $request['receiver_id'] !== $me || $request['status'] !== 'pending') {
            throw new AppException('That friend request is no longer available.', 404);
        }
        if (is_blocked_either($me, (int) $request['sender_id'])) {
            throw new AppException('Unable to accept this friend request.', 403);
        }
        $update = $pdo->prepare("UPDATE friend_requests SET status = 'accepted' WHERE id = ?");
        $update->execute([$requestId]);
        [$one, $two] = ordered_pair((int) $request['sender_id'], $me);
        $insert = $pdo->prepare('INSERT IGNORE INTO friendships (user_one, user_two) VALUES (?, ?)');
        $insert->execute([$one, $two]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $meUser = find_user($me);
    create_notification(
        (int) $request['sender_id'],
        'friend_accept',
        'Friend request accepted',
        ($meUser['username'] ?? 'Someone') . ' accepted your friend request.',
        'friends.php',
        $me
    );
}

function reject_friend_request(int $me, int $requestId): void
{
    $statement = db()->prepare("UPDATE friend_requests SET status = 'rejected' WHERE id = ? AND receiver_id = ? AND status = 'pending'");
    $statement->execute([$requestId, $me]);
    if ($statement->rowCount() === 0) {
        throw new AppException('That friend request is no longer available.', 404);
    }
}

function cancel_friend_request(int $me, int $requestId): void
{
    $statement = db()->prepare("UPDATE friend_requests SET status = 'cancelled' WHERE id = ? AND sender_id = ? AND status = 'pending'");
    $statement->execute([$requestId, $me]);
    if ($statement->rowCount() === 0) {
        throw new AppException('That friend request is no longer available.', 404);
    }
}

function remove_friend(int $me, int $friendId): void
{
    if (!are_friends($me, $friendId)) {
        throw new AppException('That person is not in your friends list.', 404);
    }
    [$one, $two] = ordered_pair($me, $friendId);
    $statement = db()->prepare('DELETE FROM friendships WHERE user_one = ? AND user_two = ?');
    $statement->execute([$one, $two]);
}

function list_blocked_users(int $me): array
{
    $statement = db()->prepare(
        'SELECT u.* FROM user_blocks b
         JOIN users u ON u.id = b.blocked_id
         WHERE b.blocker_id = ?
         ORDER BY u.username ASC'
    );
    $statement->execute([$me]);
    $rows = [];
    foreach ($statement->fetchAll() as $user) {
        $card = user_public($user);
        $card['relationship'] = 'blocked';
        $rows[] = $card;
    }
    return $rows;
}

function block_user(int $me, int $otherId): void
{
    if ($me === $otherId) {
        throw new AppException('You cannot block yourself.');
    }
    $other = find_user($otherId);
    if (!$other) {
        throw new AppException('That user is not available.', 404);
    }
    if (blocked_by_me($me, $otherId)) {
        throw new AppException('This user is already blocked.');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if (are_friends($me, $otherId)) {
            [$one, $two] = ordered_pair($me, $otherId);
            $deleteFriend = $pdo->prepare('DELETE FROM friendships WHERE user_one = ? AND user_two = ?');
            $deleteFriend->execute([$one, $two]);
        }
        $cancel = $pdo->prepare(
            "UPDATE friend_requests SET status = 'cancelled'
             WHERE status = 'pending' AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))"
        );
        $cancel->execute([$me, $otherId, $otherId, $me]);
        $insert = $pdo->prepare('INSERT INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)');
        $insert->execute([$me, $otherId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function unblock_user(int $me, int $otherId): void
{
    $statement = db()->prepare('DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?');
    $statement->execute([$me, $otherId]);
    if ($statement->rowCount() === 0) {
        throw new AppException('That user is not blocked.', 404);
    }
}
