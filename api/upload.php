<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    $data = request_data();
    $action = (string) ($data['action'] ?? '');
    if ($action === 'avatar') {
        update_avatar((int) $user['id'], $_FILES['avatar'] ?? []);
        $fresh = find_user((int) $user['id']);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null], 'Avatar updated');
    }
    if ($action === 'group_avatar') {
        update_group_avatar((int) $user['id'], (int) ($data['group_id'] ?? 0), $_FILES['avatar'] ?? []);
        json_ok([], 'Group photo updated');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
