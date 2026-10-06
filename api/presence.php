<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? 'ping');
    if ($action === 'ping') {
        $statement = db()->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?');
        $statement->execute([(int) $user['id']]);
        $fresh = find_user((int) $user['id']);
        json_ok(['presence' => $fresh ? presence_of($fresh) : null]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    if ($action === 'mode') {
        update_presence_mode((int) $user['id'], (string) ($data['mode'] ?? ''));
        $fresh = find_user((int) $user['id']);
        json_ok(['user' => $fresh ? user_public($fresh, true) : null], 'Status updated');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
