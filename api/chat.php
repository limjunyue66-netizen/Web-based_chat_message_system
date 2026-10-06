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
        json_ok(['conversations' => list_conversations($userId, (string) ($data['q'] ?? ''))]);
    }

    if ($action === 'poll') {
        json_ok(private_poll($userId, (int) ($data['with'] ?? 0), (int) ($data['since_id'] ?? 0)));
    }

    if ($action === 'history') {
        json_ok(['messages' => private_history($userId, (int) ($data['with'] ?? 0), (int) ($data['before_id'] ?? 0))]);
    }

    if ($action === 'typing') {
        if (request_method() !== 'POST') {
            throw new AppException('Method not allowed.', 405);
        }
        set_typing($userId, 'private', (int) ($data['with'] ?? 0), (int) ($data['active'] ?? 0) === 1);
        json_ok([], 'Typing updated');
    }

    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
