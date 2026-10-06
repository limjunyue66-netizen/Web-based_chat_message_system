<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $method = request_method();
    $data = request_data();
    $action = (string) ($data['action'] ?? '');

    if ($method === 'POST') {
        require_csrf();
    }

    if ($action === 'me') {
        $user = current_user();
        json_ok(['user' => $user ? user_public($user, true) : null]);
    }

    if ($method !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }

    if ($action === 'register') {
        $user = register_user(
            (string) ($data['username'] ?? ''),
            (string) ($data['email'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['confirm_password'] ?? '')
        );
        json_ok(['user' => user_public($user, true)], 'Account created');
    }

    if ($action === 'login') {
        $user = login_user((string) ($data['identifier'] ?? ''), (string) ($data['password'] ?? ''));
        json_ok(['user' => user_public($user, true)], 'Logged in');
    }

    if ($action === 'logout') {
        logout_user();
        json_ok([], 'Logged out');
    }

    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
