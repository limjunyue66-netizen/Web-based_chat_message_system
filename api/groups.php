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
        json_ok(['groups' => list_groups($userId)]);
    }
    if ($action === 'members') {
        $groupId = (int) ($data['group_id'] ?? 0);
        require_group_member($groupId, $userId);
        json_ok(['members' => list_group_members($groupId), 'candidates' => group_invite_candidates($groupId, $userId)]);
    }
    if ($action === 'poll') {
        json_ok(group_poll($userId, (int) ($data['group_id'] ?? 0), (int) ($data['since_id'] ?? 0)));
    }
    if ($action === 'history') {
        json_ok(['messages' => group_history($userId, (int) ($data['group_id'] ?? 0), (int) ($data['before_id'] ?? 0))]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }

    if ($action === 'create') {
        $group = create_group($user, (string) ($data['name'] ?? ''), (string) ($data['description'] ?? ''));
        json_ok(['group' => $group], 'Group created');
    }
    if ($action === 'update') {
        update_group($userId, (int) ($data['group_id'] ?? 0), (string) ($data['name'] ?? ''), (string) ($data['description'] ?? ''));
        json_ok([], 'Group updated');
    }
    if ($action === 'avatar') {
        update_group_avatar($userId, (int) ($data['group_id'] ?? 0), $_FILES['avatar'] ?? []);
        json_ok([], 'Group photo updated');
    }
    if ($action === 'delete') {
        delete_group($userId, (int) ($data['group_id'] ?? 0));
        json_ok([], 'Group deleted');
    }
    if ($action === 'leave') {
        $result = leave_group($userId, (int) ($data['group_id'] ?? 0));
        json_ok(['result' => $result], $result === 'deleted' ? 'Group deleted' : 'You left the group');
    }
    if ($action === 'add_member') {
        add_group_member($userId, (int) ($data['group_id'] ?? 0), (int) ($data['user_id'] ?? 0));
        json_ok([], 'Member added');
    }
    if ($action === 'remove_member') {
        remove_group_member($userId, (int) ($data['group_id'] ?? 0), (int) ($data['user_id'] ?? 0));
        json_ok([], 'Member removed');
    }
    if ($action === 'role') {
        set_group_role($userId, (int) ($data['group_id'] ?? 0), (int) ($data['user_id'] ?? 0), (string) ($data['role'] ?? ''));
        json_ok([], 'Role updated');
    }
    if ($action === 'typing') {
        set_typing($userId, 'group', (int) ($data['group_id'] ?? 0), (int) ($data['active'] ?? 0) === 1);
        json_ok([], 'Typing updated');
    }
    if ($action === 'send') {
        $file = $_FILES['attachment'] ?? null;
        $message = send_group_message(
            $user,
            (int) ($data['group_id'] ?? 0),
            (string) ($data['message_type'] ?? 'text'),
            (string) ($data['body'] ?? ''),
            isset($data['reply_to_id']) ? (int) $data['reply_to_id'] : null,
            is_array($file) ? $file : null,
            isset($data['voice_duration']) ? (int) $data['voice_duration'] : null
        );
        json_ok(['message' => $message], 'Message sent');
    }
    if ($action === 'delete_message') {
        delete_group_message($userId, (int) ($data['message_id'] ?? 0));
        json_ok([], 'Message deleted');
    }
    if ($action === 'delete_for_me') {
        $ids = $data['message_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        json_ok(
            hide_group_messages_for_me($userId, (int) ($data['group_id'] ?? 0), $ids),
            'Messages deleted for you'
        );
    }
    if ($action === 'clear_chat') {
        json_ok(clear_group_chat_for_me($userId, (int) ($data['group_id'] ?? 0)), 'Chat cleared');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
