<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? 'list');
    $userId = (int) $user['id'];

    if ($action === 'list') {
        json_ok([
            'notifications' => list_notifications($userId),
            'unread' => notification_unread_count($userId),
        ]);
    }
    if ($action === 'count') {
        json_ok(['unread' => notification_unread_count($userId)]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    if ($action === 'read') {
        mark_notification_read($userId, (int) ($data['id'] ?? 0));
        json_ok(['unread' => notification_unread_count($userId)], 'Notification marked read');
    }
    if ($action === 'read_all') {
        mark_all_notifications_read($userId);
        json_ok(['unread' => 0], 'Notifications marked read');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
