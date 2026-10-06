<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'read_all') {
            mark_all_notifications_read($userId);
            flash_set('success', 'All notifications marked read.');
        } elseif ($action === 'read') {
            mark_notification_read($userId, (int) ($_POST['id'] ?? 0));
        } else {
            throw new AppException('Unknown action.');
        }
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
    }
    $next = (string) ($_POST['next'] ?? 'notifications.php');
    if (!preg_match('/^[a-z0-9_]+\.php(\?.*)?$/i', $next)) {
        $next = 'notifications.php';
    }
    redirect($next);
}

$notifications = list_notifications($userId, 50);
$pageTitle = 'Notifications';
$activeNav = 'notifications';
$pageScript = 'notifications.js';
require __DIR__ . '/includes/layout_start.php';
?>
<div class="section-head">
    <p><?= (int) notification_unread_count($userId) ?> unread</p>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="read_all">
        <button class="btn btn-light" type="submit">Mark all read</button>
    </form>
</div>
<div class="person-list">
    <?php if ($notifications === []): ?>
        <div class="empty-state">
            <img class="empty-visual" src="<?= e(app_url('assets/images/ico-chat.svg')) ?>" alt="">
            <strong>All quiet</strong>
            <p>You have no notifications yet.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($notifications as $item): ?>
        <article class="person-card <?= (int) $item['is_read'] === 1 ? 'is-read' : '' ?>">
            <div class="note-icon"><i class="fa-solid fa-bell"></i></div>
            <div>
                <h2><?= e($item['title']) ?></h2>
                <p><?= e($item['body']) ?></p>
                <small><?= e(time_ago($item['created_at'])) ?></small>
            </div>
            <div class="person-actions">
                <?php if (!empty($item['link_url']) && preg_match('/^[a-z0-9_]+\.php(\?.*)?$/i', (string) $item['link_url'])): ?>
                    <a class="btn btn-accent btn-sm" href="<?= e(app_url($item['link_url'])) ?>">Open</a>
                <?php endif; ?>
                <?php if ((int) $item['is_read'] === 0): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="read">
                        <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="next" value="notifications.php">
                        <button class="btn btn-light btn-sm" type="submit">Mark read</button>
                    </form>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
