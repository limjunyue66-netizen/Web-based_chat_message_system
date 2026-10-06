<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "OK  {$label}\n";
        return;
    }
    $failures++;
    echo "FAIL {$label}\n";
}

function expect_error(callable $action, string $label): void
{
    try {
        $action();
        check(false, $label);
    } catch (AppException $exception) {
        check($exception->getMessage() !== '', $label);
    }
}

db()->exec('DELETE FROM rate_limits');

$suffix = bin2hex(random_bytes(3));
$nameA = 'smokea' . $suffix;
$nameB = 'smokeb' . $suffix;
$emailA = $nameA . '@example.test';
$emailB = $nameB . '@example.test';
$password = 'SmokePass1';

register_shutdown_function(static function () use ($nameA, $nameB): void {
    try {
        $statement = db()->prepare("SELECT id FROM users WHERE username IN (?, ?)");
        $statement->execute([$nameA, $nameB]);
        foreach ($statement->fetchAll() as $row) {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([(int) $row['id']]);
        }
        db()->prepare("DELETE FROM reports WHERE reason LIKE 'Smoke %'")->execute();
        db()->exec('DELETE FROM rate_limits');
    } catch (Throwable $exception) {
        echo $exception->getMessage() . PHP_EOL;
    }
});

expect_error(static fn () => register_user('ab', 'bad', 'short', 'short'), 'registration validation');
$userA = register_user($nameA, $emailA, $password, $password);
check((int) $userA['id'] > 0, 'registration');
expect_error(static fn () => register_user($nameA, 'other' . $emailA, $password, $password), 'duplicate username');
expect_error(static fn () => register_user($nameA . 'x', $emailA, $password, $password), 'duplicate email');
expect_error(static fn () => login_user($nameA, 'WrongPass1'), 'invalid password');
$logged = login_user($nameA, $password);
check((int) $logged['id'] === (int) $userA['id'], 'login');

$userB = register_user($nameB, $emailB, $password, $password);
db()->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([(int) $userB['id']]);
expect_error(static fn () => login_user($nameB, $password), 'disabled account');
db()->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([(int) $userB['id']]);
login_user($nameB, $password);
check(true, 're-enabled login');

$alex = find_user_by_login('demo_alex');
$blair = find_user_by_login('demo_blair');
check($alex && $blair, 'demo users exist');
$found = search_users((int) $userA['id'], 'demo_bl');
check(count($found) >= 1, 'search users');

send_friend_request((int) $userA['id'], (int) $blair['id']);
$incoming = list_friend_requests((int) $blair['id'], 'incoming');
$request = null;
foreach ($incoming as $row) {
    if ((int) $row['id'] === (int) $userA['id']) {
        $request = $row;
    }
}
check($request !== null, 'friend request received');
accept_friend_request((int) $blair['id'], (int) $request['request_id']);
check(are_friends((int) $userA['id'], (int) $blair['id']), 'friend request accepted');
send_friend_request((int) $userB['id'], (int) $userA['id']);
$incomingA = list_friend_requests((int) $userA['id'], 'incoming');
reject_friend_request((int) $userA['id'], (int) $incomingA[0]['request_id']);
check(!are_friends((int) $userA['id'], (int) $userB['id']), 'friend request rejected');
send_friend_request((int) $userA['id'], (int) $alex['id']);
$outgoing = list_friend_requests((int) $userA['id'], 'outgoing');
cancel_friend_request((int) $userA['id'], (int) $outgoing[0]['request_id']);
check(pending_request_between((int) $userA['id'], (int) $alex['id']) === null, 'friend request cancelled');
send_friend_request((int) $userA['id'], (int) $alex['id']);
accept_friend_request((int) $alex['id'], (int) list_friend_requests((int) $alex['id'], 'incoming')[0]['request_id']);
remove_friend((int) $userA['id'], (int) $alex['id']);
check(!are_friends((int) $userA['id'], (int) $alex['id']), 'friend removed');
send_friend_request((int) $userA['id'], (int) $alex['id']);
accept_friend_request((int) $alex['id'], (int) list_friend_requests((int) $alex['id'], 'incoming')[0]['request_id']);

$first = send_private_message($userA, (int) $blair['id'], 'text', 'Smoke hello', null, null, null);
$reply = send_private_message($blair, (int) $userA['id'], 'text', 'Smoke reply', (int) $first['id'], null, null);
check((int) ($reply['reply_to_id'] ?? 0) === (int) $first['id'], 'reply to message');
$reactions = toggle_reaction('message_reactions', 'messages', (int) $userA['id'], (int) $reply['id'], '👍', false);
check(count($reactions) === 1, 'reaction added');
toggle_reaction('message_reactions', 'messages', (int) $userA['id'], (int) $reply['id'], '👍', false);
set_typing((int) $userA['id'], 'private', (int) $blair['id'], true);
$polled = private_poll((int) $blair['id'], (int) $userA['id'], 0);
check(($polled['messages'][0]['delivery_status'] ?? '') !== '' || count($polled['messages']) >= 1, 'message history');
$seen = false;
foreach ($polled['messages'] as $message) {
    if ((int) $message['id'] === (int) $first['id'] && $message['delivery_status'] === 'read') {
        $seen = true;
    }
}
check($seen, 'read receipt');
check(in_array($userA['username'], $polled['typing'], true), 'typing indicator');
set_typing((int) $userA['id'], 'private', (int) $blair['id'], false);
delete_private_message((int) $userA['id'], (int) $first['id']);
$afterDelete = private_poll((int) $blair['id'], (int) $userA['id'], 0);
$deleted = false;
foreach ($afterDelete['messages'] as $message) {
    if ((int) $message['id'] === (int) $first['id'] && $message['is_deleted']) {
        $deleted = true;
    }
}
check($deleted, 'delete message');
check(count(list_conversations((int) $blair['id'])) >= 1, 'conversation list');

$group = create_group($userA, 'Smoke Group ' . $suffix, 'Temporary test group');
add_group_member((int) $userA['id'], (int) $group['id'], (int) $blair['id']);
set_group_role((int) $userA['id'], (int) $group['id'], (int) $blair['id'], 'admin');
$groupMessage = send_group_message($userA, (int) $group['id'], 'text', 'Group smoke', null, null, null);
$groupView = group_poll((int) $blair['id'], (int) $group['id'], 0);
check(count($groupView['messages']) >= 1, 'group message');
delete_group_message((int) $userA['id'], (int) $groupMessage['id']);
expect_error(static fn () => remove_group_member((int) $blair['id'], (int) $group['id'], (int) $userA['id']), 'admin cannot remove owner');
leave_group((int) $blair['id'], (int) $group['id']);
check(group_member((int) $group['id'], (int) $blair['id']) === null, 'leave group');
delete_group((int) $userA['id'], (int) $group['id']);
check(find_group((int) $group['id']) === null, 'delete group');

$postId = create_forum_post($userA, 1, 'Smoke discussion ' . $suffix, 'This is a smoke-test discussion post.');
create_comment($userA, $postId, 'Smoke comment');
$like = toggle_post_like((int) $blair['id'], $postId);
check((int) $like['like_count'] === 1, 'forum like');
$share = share_post((int) $blair['id'], $postId);
check((int) $share['share_count'] === 1 && str_contains($share['share_url'], 'forums.php?post='), 'forum share');
expect_error(static fn () => create_forum_post($userA, 4, 'Announcement attempt', 'Members cannot post announcements.'), 'announcements are locked');
create_report((int) $blair['id'], 'forum_post', $postId, 'Smoke report for moderator review.');
$open = list_reports('open');
$reportId = 0;
foreach ($open as $report) {
    if ((int) $report['target_id'] === $postId && $report['target_type'] === 'forum_post') {
        $reportId = (int) $report['id'];
    }
}
check($reportId > 0, 'report created');
review_report(1, $reportId, 'resolved', 'Smoke review');
check(true, 'report reviewed');

block_user((int) $userA['id'], (int) $blair['id']);
expect_error(static fn () => send_private_message($userA, (int) $blair['id'], 'text', 'blocked', null, null, null), 'block prevents messages');
expect_error(static fn () => start_call($userA, (int) $blair['id'], 'voice'), 'block prevents calls');
unblock_user((int) $userA['id'], (int) $blair['id']);
check(!is_blocked_either((int) $userA['id'], (int) $blair['id']), 'unblock');
send_friend_request((int) $userA['id'], (int) $blair['id']);
$again = null;
foreach (list_friend_requests((int) $blair['id'], 'incoming') as $row) {
    if ((int) $row['id'] === (int) $userA['id']) {
        $again = $row;
    }
}
check($again !== null, 'friend request after unblock');
accept_friend_request((int) $blair['id'], (int) $again['request_id']);

$call = start_call($userA, (int) $blair['id'], 'video');
check($call['status'] === 'ringing' && $call['call_type'] === 'video', 'video call session');
$incoming = incoming_call_for((int) $blair['id']);
check($incoming && (int) $incoming['id'] === (int) $call['id'], 'incoming call');
$accepted = accept_call((int) $blair['id'], (int) $call['id']);
check($accepted['status'] === 'accepted', 'accept call');
add_call_signal((int) $userA['id'], (int) $call['id'], 'offer', ['type' => 'offer', 'sdp' => 'v=0']);
$signals = call_signals_after((int) $blair['id'], (int) $call['id'], 0);
check(count($signals['signals']) === 1, 'call signalling');
$ended = end_call((int) $userA['id'], (int) $call['id']);
check($ended['status'] === 'ended', 'end call');

$stats = admin_stats();
check($stats['total_users'] >= 2 && isset($stats['open_reports']), 'admin statistics');
$admin = login_admin('admin', 'AdminDemo123!');
check((int) $admin['must_change_password'] === 1, 'admin must change demo password');

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$tmp = tempnam(sys_get_temp_dir(), 'wc');
file_put_contents($tmp, $png);
check(file_signature_ok($tmp, 'png'), 'png signature');
file_put_contents($tmp, '%PDF-1.4');
check(file_signature_ok($tmp, 'pdf'), 'pdf signature');
unlink($tmp);

update_profile((int) $userA['id'], $nameA, $emailA, 'Smoke status');
update_presence_mode((int) $userA['id'], 'busy');
$fresh = find_user((int) $userA['id']);
check($fresh && $fresh['presence_mode'] === 'busy' && $fresh['status_message'] === 'Smoke status', 'profile update');
change_user_password((int) $userA['id'], $password, 'SmokePass2', 'SmokePass2');
login_user($nameA, 'SmokePass2');
check(true, 'password change');

$unread = notification_unread_count((int) $blair['id']);
check($unread >= 1, 'notifications created');
mark_all_notifications_read((int) $blair['id']);
check(notification_unread_count((int) $blair['id']) === 0, 'notifications can be marked read');

echo $failures === 0 ? "ALL TESTS PASSED\n" : "FAILURES {$failures}\n";
exit($failures === 0 ? 0 : 1);
