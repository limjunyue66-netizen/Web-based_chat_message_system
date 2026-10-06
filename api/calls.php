<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? '');
    $userId = (int) $user['id'];

    if ($action === 'incoming') {
        json_ok(['call' => incoming_call_for($userId)]);
    }
    if ($action === 'poll') {
        json_ok(call_signals_after($userId, (int) ($data['session_id'] ?? 0), (int) ($data['after_id'] ?? 0)));
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();

    if ($action === 'start') {
        $session = start_call($user, (int) ($data['callee_id'] ?? 0), (string) ($data['call_type'] ?? 'voice'));
        json_ok(['session' => $session], 'Call started');
    }
    if ($action === 'accept') {
        json_ok(['session' => accept_call($userId, (int) ($data['session_id'] ?? 0))], 'Call accepted');
    }
    if ($action === 'reject') {
        json_ok(['session' => reject_call($userId, (int) ($data['session_id'] ?? 0))], 'Call rejected');
    }
    if ($action === 'cancel') {
        json_ok(['session' => cancel_call($userId, (int) ($data['session_id'] ?? 0))], 'Call cancelled');
    }
    if ($action === 'end') {
        json_ok(['session' => end_call($userId, (int) ($data['session_id'] ?? 0))], 'Call ended');
    }
    if ($action === 'signal') {
        $saved = add_call_signal($userId, (int) ($data['session_id'] ?? 0), (string) ($data['signal_type'] ?? ''), $data['payload'] ?? null);
        json_ok($saved, 'Signal stored');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
