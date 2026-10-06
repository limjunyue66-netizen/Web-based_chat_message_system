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
    $scope = (string) ($data['scope'] ?? 'private');
    $emoji = (string) ($data['emoji'] ?? '');
    $messageId = (int) ($data['message_id'] ?? 0);
    if ($scope === 'group') {
        $reactions = toggle_reaction('group_message_reactions', 'group_messages', (int) $user['id'], $messageId, $emoji, true);
    } else {
        $reactions = toggle_reaction('message_reactions', 'messages', (int) $user['id'], $messageId, $emoji, false);
    }
    json_ok(['reactions' => $reactions], 'Reaction updated');
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
