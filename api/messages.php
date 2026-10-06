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
    $action = (string) ($data['action'] ?? 'send');

    if ($action === 'delete') {
        delete_private_message((int) $user['id'], (int) ($data['message_id'] ?? 0));
        json_ok([], 'Message deleted');
    }

    if ($action === 'delete_for_me') {
        $ids = $data['message_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        json_ok(hide_private_messages_for_me((int) $user['id'], $ids), 'Messages deleted for you');
    }

    if ($action === 'clear_chat') {
        json_ok(clear_private_chat_for_me((int) $user['id'], (int) ($data['peer_id'] ?? 0)), 'Chat cleared');
    }

    if ($action === 'delete_chat') {
        json_ok(delete_private_chat_for_me((int) $user['id'], (int) ($data['peer_id'] ?? 0)), 'Chat deleted');
    }

    if ($action === 'forward') {
        $targets = $data['targets'] ?? [];
        if (!is_array($targets)) {
            $targets = [];
        }
        $result = forward_chat_message(
            $user,
            (string) ($data['source'] ?? 'private'),
            (int) ($data['message_id'] ?? 0),
            $targets
        );
        json_ok($result, 'Message forwarded');
    }

    if ($action !== 'send') {
        throw new AppException('Unknown action.', 404);
    }

    $file = $_FILES['attachment'] ?? null;
    $message = send_private_message(
        $user,
        (int) ($data['receiver_id'] ?? 0),
        (string) ($data['message_type'] ?? 'text'),
        (string) ($data['body'] ?? ''),
        isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
        is_array($file) ? $file : null,
        isset($data['voice_duration']) ? (int) $data['voice_duration'] : null
    );
    json_ok(['message' => $message], 'Message sent');
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
