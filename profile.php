<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$viewId = (int) ($_GET['id'] ?? 0);
$isSelf = $viewId === 0 || $viewId === $userId;
$profile = $isSelf ? $user : find_user($viewId);
if (!$profile) {
    throw new AppException('User not found.', 404);
}
$relationship = $isSelf ? 'self' : relationship_between($userId, (int) $profile['id']);
$error = null;

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update' && $isSelf) {
            update_profile($userId, (string) ($_POST['username'] ?? ''), (string) ($_POST['email'] ?? ''), (string) ($_POST['status_message'] ?? ''));
            flash_set('success', 'Profile updated.');
        } elseif ($action === 'password' && $isSelf) {
            change_user_password($userId, (string) ($_POST['current_password'] ?? ''), (string) ($_POST['new_password'] ?? ''), (string) ($_POST['confirm_password'] ?? ''));
            flash_set('success', 'Password changed.');
        } elseif ($action === 'avatar' && $isSelf) {
            update_avatar($userId, $_FILES['avatar'] ?? []);
            flash_set('success', 'Avatar updated.');
        } elseif ($action === 'presence' && $isSelf) {
            update_presence_mode($userId, (string) ($_POST['mode'] ?? ''));
            flash_set('success', 'Status updated.');
        } elseif ($action === 'block') {
            block_user($userId, (int) $profile['id']);
            flash_set('success', 'User blocked.');
        } elseif ($action === 'unblock') {
            unblock_user($userId, (int) $profile['id']);
            flash_set('success', 'User unblocked.');
        } elseif ($action === 'friend') {
            send_friend_request($userId, (int) $profile['id']);
            flash_set('success', 'Friend request sent.');
        } elseif ($action === 'report') {
            create_report($userId, 'user', (int) $profile['id'], (string) ($_POST['reason'] ?? ''));
            flash_set('success', 'Report submitted.');
        } else {
            throw new AppException('Unknown action.');
        }
        redirect($isSelf ? 'profile.php' : 'profile.php?id=' . (int) $profile['id']);
    } catch (AppException $exception) {
        $error = $exception->getMessage();
        $profile = find_user($isSelf ? $userId : (int) $profile['id']) ?? $profile;
    }
}

if ($isSelf) {
    $profile = find_user($userId) ?? $profile;
}
$presence = presence_of($profile);
$pageTitle = $isSelf ? 'Your profile' : (string) $profile['username'];
$activeNav = 'profile';
$pageScript = 'profile.js';
require __DIR__ . '/includes/layout_start.php';
?>
<div class="profile-layout">
    <section class="panel-card profile-hero">
        <div class="profile-photo">
            <img src="<?= e(avatar_url($profile)) ?>" alt="">
            <?php if ($isSelf): ?>
                <form method="post" enctype="multipart/form-data" class="avatar-inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avatar">
                    <label class="form-label" for="avatar">Profile photo</label>
                    <input class="form-control" type="file" name="avatar" id="avatar" accept="image/jpeg,image/png,image/gif,image/webp,.jpg,.jpeg,.png,.gif,.webp" required>
                    <button class="btn btn-accent btn-sm" type="submit">Upload photo</button>
                </form>
            <?php endif; ?>
        </div>
        <div>
            <h2><?= e($profile['username']) ?></h2>
            <p class="presence presence-<?= e($presence['state']) ?>"><?= e($presence['last_seen_label']) ?></p>
            <p><?= e($profile['status_message'] !== '' ? $profile['status_message'] : 'No status message yet.') ?></p>
            <small>Joined <?= e(date('M j, Y', strtotime((string) $profile['created_at']) ?: time())) ?></small>
        </div>
    </section>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

    <?php if ($isSelf): ?>
        <form method="post" class="panel-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <h2>Profile details</h2>
            <label class="form-label" for="username">Username</label>
            <input class="form-control" id="username" name="username" required minlength="3" maxlength="32" value="<?= e($profile['username']) ?>">
            <label class="form-label mt-3" for="email">Email</label>
            <input class="form-control" id="email" name="email" type="email" required value="<?= e($profile['email']) ?>">
            <label class="form-label mt-3" for="status_message">Status message</label>
            <input class="form-control" id="status_message" name="status_message" maxlength="160" value="<?= e($profile['status_message']) ?>">
            <button class="btn btn-accent mt-3" type="submit">Save profile</button>
        </form>
        <form method="post" class="panel-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="presence">
            <h2>Presence</h2>
            <label class="form-label" for="mode">Show others that you are</label>
            <select class="form-select" id="mode" name="mode">
                <?php foreach (['online' => 'Online', 'away' => 'Away', 'busy' => 'Busy'] as $value => $label): ?>
                    <option value="<?= e($value) ?>" <?= $profile['presence_mode'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-accent mt-3" type="submit">Update presence</button>
        </form>
        <form method="post" class="panel-card">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h2>Change password</h2>
            <label class="form-label" for="current_password">Current password</label>
            <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password">
            <label class="form-label mt-3" for="new_password">New password</label>
            <input class="form-control" id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password">
            <label class="form-label mt-3" for="confirm_password">Confirm new password</label>
            <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password">
            <button class="btn btn-accent mt-3" type="submit">Change password</button>
        </form>
        <form class="logout-form profile-logout" method="post" action="<?= e(app_url('logout.php')) ?>">
            <?= csrf_field() ?>
            <button class="btn btn-light w-100" type="submit"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Log out</button>
        </form>
    <?php else: ?>
        <section class="panel-card">
            <h2>Connect</h2>
            <div class="person-actions">
                <?php if ($relationship === 'friend'): ?>
                    <a class="btn btn-accent" href="<?= e(app_url('chat.php?with=' . (int) $profile['id'])) ?>">Message</a>
                    <button class="btn btn-light" type="button" onclick="WC.calls.start(<?= (int) $profile['id'] ?>, 'voice')">Voice call</button>
                    <button class="btn btn-light" type="button" onclick="WC.calls.start(<?= (int) $profile['id'] ?>, 'video')">Video call</button>
                <?php elseif ($relationship === 'none'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="friend"><button class="btn btn-accent" type="submit">Add friend</button></form>
                <?php elseif ($relationship === 'outgoing'): ?>
                    <span class="badge text-bg-light">Friend request sent</span>
                <?php elseif ($relationship === 'incoming'): ?>
                    <a class="btn btn-accent" href="<?= e(app_url('friends.php?tab=incoming')) ?>">Review friend request</a>
                <?php endif; ?>
                <?php if ($relationship === 'blocked'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="unblock"><button class="btn btn-accent" type="submit">Unblock</button></form>
                <?php else: ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="block"><button class="btn btn-light" type="submit" data-confirm="Block this user?">Block</button></form>
                <?php endif; ?>
            </div>
            <form method="post" class="mt-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="report">
                <label class="form-label" for="reason">Report this user</label>
                <textarea class="form-control" id="reason" name="reason" minlength="10" maxlength="500" required></textarea>
                <button class="btn btn-light mt-2" type="submit">Submit report</button>
            </form>
        </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
