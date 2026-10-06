<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_admin_page();
$query = trim((string) ($_GET['q'] ?? ''));

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        $userId = (int) ($_POST['user_id'] ?? 0);
        if ($action === 'disable') {
            admin_set_user_active($userId, false);
            flash_set('success', 'User disabled.');
        } elseif ($action === 'enable') {
            admin_set_user_active($userId, true);
            flash_set('success', 'User enabled.');
        } elseif ($action === 'delete') {
            admin_delete_user($userId, (string) ($_POST['confirm_username'] ?? ''));
            flash_set('success', 'User deleted.');
        } else {
            throw new AppException('Unknown action.');
        }
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
    }
    redirect('admin/users.php' . ($query !== '' ? '?q=' . urlencode($query) : ''));
}

$users = admin_list_users($query);
$adminPageTitle = 'Users';
$adminNav = 'users';
require dirname(__DIR__) . '/includes/admin_layout_start.php';
?>
<form class="search-bar" method="get">
    <label class="visually-hidden" for="q">Search users</label>
    <input class="form-control" id="q" name="q" value="<?= e($query) ?>" placeholder="Search username or email">
    <button class="btn btn-accent" type="submit">Search</button>
</form>
<div class="table-wrap">
    <table class="table align-middle">
        <thead><tr><th>User</th><th>Email</th><th>Status</th><th>Last seen</th><th>Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $person): ?>
            <tr>
                <td><strong><?= e($person['username']) ?></strong><br><small><?= e($person['status_message']) ?></small></td>
                <td><?= e($person['email']) ?></td>
                <td><?= (int) $person['is_active'] === 1 ? 'Enabled' : 'Disabled' ?></td>
                <td><?= e($person['last_seen'] ? time_ago($person['last_seen']) : 'Never') ?></td>
                <td class="admin-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                        <input type="hidden" name="action" value="<?= (int) $person['is_active'] === 1 ? 'disable' : 'enable' ?>">
                        <button class="btn btn-light btn-sm" type="submit"><?= (int) $person['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" class="delete-user-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="user_id" value="<?= (int) $person['id'] ?>">
                        <input class="form-control form-control-sm" name="confirm_username" placeholder="Type username" required aria-label="Confirm username">
                        <button class="btn btn-danger btn-sm" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__) . '/includes/admin_layout_end.php'; ?>
