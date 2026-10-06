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

    if ($action === 'list') {
        json_ok(['blocked' => list_blocked_users($userId)]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    if ($action === 'block') {
        block_user($userId, (int) ($data['user_id'] ?? 0));
        json_ok([], 'User blocked');
    }
    if ($action === 'unblock') {
        unblock_user($userId, (int) ($data['user_id'] ?? 0));
        json_ok([], 'User unblocked');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
