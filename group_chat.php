<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$groupId = (int) ($_GET['id'] ?? $_POST['group_id'] ?? 0);
$member = require_group_member($groupId, $userId);
$group = find_group($groupId);
if (!$group) {
    throw new AppException('Group not found.', 404);
}

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'update') {
            update_group($userId, $groupId, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
            flash_set('success', 'Group updated.');
        } elseif ($action === 'avatar') {
            update_group_avatar($userId, $groupId, $_FILES['avatar'] ?? []);
            flash_set('success', 'Group photo updated.');
        } elseif ($action === 'add_member') {
            add_group_member($userId, $groupId, (int) ($_POST['user_id'] ?? 0));
            flash_set('success', 'Member added.');
        } elseif ($action === 'remove_member') {
            remove_group_member($userId, $groupId, (int) ($_POST['user_id'] ?? 0));
            flash_set('success', 'Member removed.');
        } elseif ($action === 'role') {
            set_group_role($userId, $groupId, (int) ($_POST['user_id'] ?? 0), (string) ($_POST['role'] ?? ''));
            flash_set('success', 'Role updated.');
        } elseif ($action === 'leave') {
            $result = leave_group($userId, $groupId);
            flash_set('success', $result === 'deleted' ? 'Group deleted.' : 'You left the group.');
            redirect('groups.php');
        } elseif ($action === 'delete') {
            delete_group($userId, $groupId);
            flash_set('success', 'Group deleted.');
            redirect('groups.php');
        } else {
            throw new AppException('Unknown action.');
        }
        redirect('group_chat.php?id=' . $groupId);
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
        redirect('group_chat.php?id=' . $groupId);
    }
}

$group = find_group($groupId);
$member = require_group_member($groupId, $userId);
$members = list_group_members($groupId);
$candidates = in_array($member['role'], ['owner', 'admin'], true) ? group_invite_candidates($groupId, $userId) : [];
$canManage = in_array($member['role'], ['owner', 'admin'], true);
$pageTitle = (string) $group['name'];
$activeNav = 'groups';
$fullBleed = true;
$pageScript = 'chat.js';
$callMembers = array_values(array_filter($members, static function (array $row) use ($userId): bool {
    return (int) $row['id'] !== $userId;
}));
$bootExtra = [
    'chat' => [
        'groupId' => $groupId,
        'members' => $callMembers,
    ],
];
require __DIR__ . '/includes/layout_start.php';
?>
<div class="chat-layout" id="chat-app" data-mode="group" data-layout="group" data-group="<?= (int) $groupId ?>">
    <section class="pane pane-thread">
        <header class="pane-head thread-head">
            <a class="icon-btn" href="<?= e(app_url('groups.php')) ?>" aria-label="Back to groups"><i class="fa-solid fa-arrow-left"></i></a>
            <div class="thread-title" id="thread-title" role="button" tabindex="0" title="Group info">
                <img src="<?= e(group_avatar_url($group)) ?>" alt="">
                <div>
                    <h2 id="thread-name"><?= e($group['name']) ?></h2>
                    <p id="thread-presence"><?= count($members) ?> members · You are <?= e($member['role']) ?></p>
                </div>
            </div>
            <div class="thread-actions">
                <button type="button" class="icon-btn icon-chip chip-call" id="start-voice" aria-label="Voice call"><i class="fa-solid fa-phone"></i></button>
                <button type="button" class="icon-btn icon-chip chip-video" id="start-video" aria-label="Video call"><i class="fa-solid fa-video"></i></button>
                <div class="thread-menu-wrap" id="thread-menu-wrap">
                    <button type="button" class="icon-btn" id="thread-menu-btn" aria-label="Chat menu" aria-haspopup="true" aria-expanded="false"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                    <div id="thread-menu" class="thread-menu d-none" role="menu">
                        <button type="button" role="menuitem" data-thread-action="contact"><i class="fa-solid fa-circle-info"></i> Group info</button>
                        <button type="button" role="menuitem" data-thread-action="select"><i class="fa-regular fa-square-check"></i> Select messages</button>
                        <button type="button" role="menuitem" data-thread-action="clear"><i class="fa-solid fa-broom"></i> Clear chat</button>
                    </div>
                </div>
            </div>
        </header>
        <div id="select-bar" class="select-bar d-none" hidden>
            <button type="button" class="icon-btn" id="select-cancel" aria-label="Cancel selection"><i class="fa-solid fa-xmark"></i></button>
            <span id="select-count">0 selected</span>
            <button type="button" class="icon-btn" id="select-delete" aria-label="Delete selected"><i class="fa-solid fa-trash"></i></button>
        </div>
        <div id="message-list" class="message-scroll" aria-live="polite"></div>
        <form id="composer" class="composer" autocomplete="off" action="#" method="post" onsubmit="return false;">
            <div id="reply-bar" class="reply-bar d-none">
                <div><strong>Replying to</strong><p id="reply-excerpt"></p></div>
                <button type="button" id="reply-cancel" aria-label="Cancel reply">&times;</button>
            </div>
            <div id="attach-preview" class="attach-preview d-none">
                <img id="attach-preview-img" alt="" hidden>
                <div class="attach-preview-copy">
                    <strong id="attach-preview-name">Attachment</strong>
                    <span id="attach-preview-meta"></span>
                </div>
                <button type="button" id="attach-preview-clear" aria-label="Remove attachment">&times;</button>
            </div>
            <div id="voice-bar" class="voice-bar d-none">
                <span><i class="fa-solid fa-circle text-danger"></i> Recording <strong id="voice-timer">0:00</strong></span>
                <button type="button" class="btn btn-light btn-sm" id="voice-cancel">Cancel</button>
                <button type="button" class="btn btn-accent btn-sm" id="voice-send">Send</button>
            </div>
            <div class="composer-row" id="composer-row">
                <button type="button" class="icon-btn icon-chip chip-emoji" id="emoji-toggle" aria-label="Insert emoji"><i class="fa-regular fa-face-smile"></i></button>
                <label class="icon-btn icon-chip chip-image" for="image-input" aria-label="Add image"><i class="fa-solid fa-image"></i></label>
                <input id="image-input" type="file" accept=".jpg,.jpeg,.png,.gif,.webp,image/jpeg,image/png,image/gif,image/webp" hidden>
                <label class="icon-btn icon-chip chip-file" for="file-input" aria-label="Add file"><i class="fa-solid fa-paperclip"></i></label>
                <input id="file-input" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" hidden>
                <label class="visually-hidden" for="message-input">Message</label>
                <textarea id="message-input" rows="1" maxlength="4000" placeholder="Type a message"></textarea>
                <button type="button" class="icon-btn icon-chip chip-mic" id="voice-toggle" aria-label="Record voice message"><i class="fa-solid fa-microphone"></i></button>
                <button type="button" class="send-btn" id="send-btn" aria-label="Send message"><i class="fa-solid fa-paper-plane"></i></button>
            </div>
            <div id="emoji-picker" class="emoji-picker d-none"></div>
        </form>
    </section>
    <aside class="pane pane-info" id="contact-pane">
        <div class="info-pane-head">
            <strong>Group info</strong>
            <button type="button" class="icon-btn" data-info-close aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="info-card">
            <img class="info-avatar" src="<?= e(group_avatar_url($group)) ?>" alt="">
            <h2><?= e($group['name']) ?></h2>
            <p><?= e($group['description'] !== '' ? $group['description'] : 'No description yet.') ?></p>
            <?php if ($canManage): ?>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                    <label class="form-label" for="group-name">Name</label>
                    <input class="form-control" id="group-name" name="name" required minlength="2" maxlength="80" value="<?= e($group['name']) ?>">
                    <label class="form-label mt-2" for="group-description">Description</label>
                    <textarea class="form-control" id="group-description" name="description" maxlength="255" rows="2"><?= e($group['description']) ?></textarea>
                    <button class="btn btn-accent btn-sm mt-2" type="submit">Save details</button>
                </form>
                <form method="post" enctype="multipart/form-data" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avatar">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                    <label class="form-label" for="group-avatar">Group photo</label>
                    <input class="form-control" id="group-avatar" type="file" name="avatar" accept=".jpg,.jpeg,.png,.gif,.webp" required>
                    <button class="btn btn-light btn-sm mt-2" type="submit">Upload photo</button>
                </form>
            <?php endif; ?>
            <?php if ($candidates !== []): ?>
                <form method="post" class="stack-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="add_member">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                    <label class="form-label" for="invite-user">Add a friend</label>
                    <select class="form-select" id="invite-user" name="user_id" required>
                        <?php foreach ($candidates as $candidate): ?>
                            <option value="<?= (int) $candidate['id'] ?>"><?= e($candidate['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-accent btn-sm mt-2" type="submit">Add member</button>
                </form>
            <?php endif; ?>
            <h3 class="mt-3">Members</h3>
            <ul class="member-list">
                <?php foreach ($members as $person): ?>
                    <li>
                        <img src="<?= e($person['avatar_url']) ?>" alt="">
                        <span><strong><?= e($person['username']) ?></strong><small><?= e($person['role']) ?></small></span>
                        <?php if ($canManage && (int) $person['id'] !== $userId && $person['role'] !== 'owner' && ($member['role'] === 'owner' || $person['role'] === 'member')): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="remove_member">
                                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                                <button class="btn btn-light btn-sm" type="submit">Remove</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($member['role'] === 'owner' && (int) $person['id'] !== $userId): ?>
                            <form method="post" class="role-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="role">
                                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                                <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                                <select name="role" aria-label="Role for <?= e($person['username']) ?>">
                                    <option value="admin" <?= $person['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                                    <option value="member" <?= $person['role'] === 'member' ? 'selected' : '' ?>>Member</option>
                                    <option value="owner">Make owner</option>
                                </select>
                                <button class="btn btn-light btn-sm" type="submit">Save</button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="post" class="mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="leave">
                <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                <button class="btn btn-light w-100" type="submit" data-confirm="Leave this group?">Leave group</button>
            </form>
            <?php if ($member['role'] === 'owner'): ?>
                <form method="post" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="group_id" value="<?= (int) $groupId ?>">
                    <button class="btn btn-danger w-100" type="submit" data-confirm="Delete this group for everyone?">Delete group</button>
                </form>
            <?php endif; ?>
        </div>
    </aside>
</div>
<script>
window.WC_BOOT = Object.assign(window.WC_BOOT || {}, <?= json_encode($bootExtra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>);
</script>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
