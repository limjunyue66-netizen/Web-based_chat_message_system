<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_admin_page();
$error = null;

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        change_admin_password(
            (int) $admin['id'],
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        flash_set('success', 'Administrator password updated.');
        redirect('admin/index.php');
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$mustChange = (int) $admin['must_change_password'] === 1;
$stats = $mustChange ? [] : admin_stats();
$adminPageTitle = $mustChange ? 'Change password' : 'Dashboard';
$adminNav = 'dashboard';
require dirname(__DIR__) . '/includes/admin_layout_start.php';
?>
<?php if ($mustChange): ?>
    <div class="alert alert-warning">DEMO ONLY: change the default administrator password before using the rest of the panel.</div>
<?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>

<?php if (!$mustChange): ?>
    <div class="stat-grid">
        <?php
        $cards = [
            'Total users' => $stats['total_users'],
            'Active users' => $stats['active_users'],
            'Disabled users' => $stats['disabled_users'],
            'Messages' => $stats['total_messages'],
            'Groups' => $stats['total_groups'],
            'Forum posts' => $stats['total_posts'],
            'Open reports' => $stats['open_reports'],
            'Messages, 7 days' => $stats['messages_7_days'],
        ];
        foreach ($cards as $label => $value): ?>
            <article class="stat-card"><span><?= e($label) ?></span><strong><?= (int) $value ?></strong></article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<form method="post" class="panel-card mt-4">
    <?= csrf_field() ?>
    <h2><?= $mustChange ? 'Required password change' : 'Change administrator password' ?></h2>
    <label class="form-label" for="current_password">Current password</label>
    <input class="form-control" id="current_password" name="current_password" type="password" required autocomplete="current-password">
    <label class="form-label mt-3" for="new_password">New password</label>
    <input class="form-control" id="new_password" name="new_password" type="password" required minlength="8" autocomplete="new-password">
    <label class="form-label mt-3" for="confirm_password">Confirm password</label>
    <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password">
    <button class="btn btn-accent mt-3" type="submit">Update password</button>
</form>
<?php require dirname(__DIR__) . '/includes/admin_layout_end.php'; ?>
