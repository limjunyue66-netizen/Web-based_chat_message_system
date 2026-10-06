<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? 'me');
    $userId = (int) $user['id'];

    if ($action === 'me') {
        $fresh = find_user($userId);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null]);
    }
    if ($action === 'view') {
        $other = find_user((int) ($data['user_id'] ?? 0));
        if (!$other) {
            throw new AppException('User not found.', 404);
        }
        $card = user_public($other);
        $card['relationship'] = relationship_between($userId, (int) $other['id']);
        $card['joined_label'] = date('M j, Y', strtotime((string) ($other['created_at'] ?? 'now')) ?: time());
        $card['email'] = null;
        json_ok(['user' => $card]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    if ($action === 'update') {
        update_profile($userId, (string) ($data['username'] ?? ''), (string) ($data['email'] ?? ''), (string) ($data['status_message'] ?? ''));
        $fresh = find_user($userId);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null], 'Profile updated');
    }
    if ($action === 'password') {
        change_user_password($userId, (string) ($data['current_password'] ?? ''), (string) ($data['new_password'] ?? ''), (string) ($data['confirm_password'] ?? ''));
        json_ok([], 'Password changed');
    }
    if ($action === 'avatar') {
        update_avatar($userId, $_FILES['avatar'] ?? []);
        $fresh = find_user($userId);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null], 'Avatar updated');
    }
    if ($action === 'presence') {
        update_presence_mode($userId, (string) ($data['mode'] ?? ''));
        $fresh = find_user($userId);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null], 'Status updated');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
