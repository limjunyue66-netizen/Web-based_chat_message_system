<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$error = null;

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $group = create_group($user, (string) ($_POST['name'] ?? ''), (string) ($_POST['description'] ?? ''));
        flash_set('success', 'Group created.');
        redirect('group_chat.php?id=' . (int) $group['id']);
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$groups = list_groups($userId);
$pageTitle = 'Groups';
$activeNav = 'groups';
$pageScript = 'groups.js';
require __DIR__ . '/includes/layout_start.php';
?>
<div class="split-page">
    <section>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($groups === []): ?>
            <div class="empty-state">
                <img class="empty-visual" src="<?= e(app_url('assets/images/ico-community.svg')) ?>" alt="">
                <strong>No groups yet</strong>
                <p>Create one on the right and add friends to start chatting.</p>
            </div>
        <?php else: ?>
            <div class="person-list">
                <?php foreach ($groups as $group): ?>
                    <a class="person-card person-link" href="<?= e(app_url('group_chat.php?id=' . (int) $group['id'])) ?>">
                        <img src="<?= e($group['avatar_url']) ?>" alt="">
                        <div>
                            <h2><?= e($group['name']) ?></h2>
                            <p><?= e($group['last_message']) ?></p>
                            <small><?= e(ucfirst((string) $group['my_role'])) ?><?= $group['last_label'] !== '' ? ' · ' . e($group['last_label']) : '' ?></small>
                        </div>
                        <?php if ((int) $group['unread'] > 0): ?><em class="nav-badge"><?= (int) $group['unread'] ?></em><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <aside class="panel-card">
        <h2>New group</h2>
        <p class="text-secondary">You become the owner and can add friends after the group is created.</p>
        <form method="post">
            <?= csrf_field() ?>
            <label class="form-label" for="name">Group name</label>
            <input class="form-control" id="name" name="name" required minlength="2" maxlength="80">
            <label class="form-label mt-3" for="description">Description</label>
            <textarea class="form-control" id="description" name="description" maxlength="255" rows="3"></textarea>
            <button class="btn btn-accent mt-3" type="submit">Create group</button>
        </form>
    </aside>
</div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
