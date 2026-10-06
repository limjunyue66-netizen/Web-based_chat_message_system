<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    $data = request_data();
    $action = (string) ($data['action'] ?? 'categories');
    $userId = (int) $user['id'];

    if ($action === 'categories') {
        json_ok(['forums' => list_forums()]);
    }
    if ($action === 'posts') {
        json_ok(['posts' => list_forum_posts((int) ($data['forum_id'] ?? 0))]);
    }
    if ($action === 'post') {
        json_ok(['post' => get_forum_post((int) ($data['post_id'] ?? 0), $userId)]);
    }
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    if ($action === 'create') {
        $id = create_forum_post($user, (int) ($data['forum_id'] ?? 0), (string) ($data['title'] ?? ''), (string) ($data['content'] ?? ''));
        json_ok(['post_id' => $id], 'Post published');
    }
    if ($action === 'comment') {
        create_comment($user, (int) ($data['post_id'] ?? 0), (string) ($data['content'] ?? ''));
        json_ok(['post' => get_forum_post((int) ($data['post_id'] ?? 0), $userId)], 'Comment added');
    }
    if ($action === 'delete_comment') {
        delete_comment($userId, (int) ($data['comment_id'] ?? 0));
        json_ok([], 'Comment deleted');
    }
    if ($action === 'like') {
        json_ok(toggle_post_like($userId, (int) ($data['post_id'] ?? 0)), 'Like updated');
    }
    if ($action === 'share') {
        json_ok(share_post($userId, (int) ($data['post_id'] ?? 0)), 'Share recorded');
    }
    throw new AppException('Unknown action.', 404);
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
