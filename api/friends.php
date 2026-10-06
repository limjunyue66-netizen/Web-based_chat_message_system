<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? 'list');
    $userId = (int) $user['id'];
    if (request_method() === 'POST') {
        require_csrf();
    }

    if ($action === 'search') {
        json_ok(['users' => search_users($userId, (string) ($data['q'] ?? ''))]);
    }
    if ($action === 'list') {
        json_ok(['friends' => list_friends($userId)]);
    }
    if ($action === 'incoming' || $action === 'outgoing') {
        json_ok(['requests' => list_friend_requests($userId, $action)]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    if ($action === 'send') {
        send_friend_request($userId, (int) ($data['user_id'] ?? 0));
        json_ok([], 'Friend request sent');
    }
    if ($action === 'accept') {
        accept_friend_request($userId, (int) ($data['request_id'] ?? 0));
        json_ok([], 'Friend request accepted');
    }
    if ($action === 'reject') {
        reject_friend_request($userId, (int) ($data['request_id'] ?? 0));
        json_ok([], 'Friend request rejected');
    }
    if ($action === 'cancel') {
        cancel_friend_request($userId, (int) ($data['request_id'] ?? 0));
        json_ok([], 'Friend request cancelled');
    }
    if ($action === 'remove') {
        remove_friend($userId, (int) ($data['user_id'] ?? 0));
        json_ok([], 'Friend removed');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
