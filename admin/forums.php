<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_admin_page();

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'save_forum') {
            $forumId = (int) ($_POST['forum_id'] ?? 0);
            admin_save_forum(
                $forumId > 0 ? $forumId : null,
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                (int) ($_POST['sort_order'] ?? 0),
                isset($_POST['member_can_post'])
            );
            flash_set('success', 'Forum category saved.');
        } elseif ($action === 'delete_forum') {
            admin_delete_forum((int) ($_POST['forum_id'] ?? 0));
            flash_set('success', 'Forum category deleted.');
        } elseif ($action === 'announce') {
            admin_create_announcement((int) ($_POST['forum_id'] ?? 0), (string) ($_POST['title'] ?? ''), (string) ($_POST['content'] ?? ''));
            flash_set('success', 'Post published.');
        } elseif ($action === 'hide' || $action === 'unhide') {
            admin_set_post_hidden((int) ($_POST['post_id'] ?? 0), $action === 'hide');
            flash_set('success', $action === 'hide' ? 'Post hidden.' : 'Post restored.');
        } elseif ($action === 'delete_post') {
            admin_delete_post((int) ($_POST['post_id'] ?? 0));
            flash_set('success', 'Post deleted.');
        } else {
            throw new AppException('Unknown action.');
        }
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
    }
    redirect('admin/forums.php');
}

$forums = list_forums();
$posts = admin_list_posts();
$adminPageTitle = 'Forums';
$adminNav = 'forums';
require dirname(__DIR__) . '/includes/admin_layout_start.php';
?>
<div class="split-page">
    <section class="panel-card">
        <h2>New category</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_forum">
            <label class="form-label" for="name">Name</label>
            <input class="form-control" id="name" name="name" required maxlength="80">
            <label class="form-label mt-2" for="description">Description</label>
            <input class="form-control" id="description" name="description" maxlength="255">
            <label class="form-label mt-2" for="sort_order">Sort order</label>
            <input class="form-control" id="sort_order" name="sort_order" type="number" value="0">
            <label class="form-check mt-3"><input class="form-check-input" type="checkbox" name="member_can_post" checked> Members can post</label>
            <button class="btn btn-accent mt-3" type="submit">Add category</button>
        </form>
    </section>
    <section class="panel-card">
        <h2>Publish a post</h2>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="announce">
            <label class="form-label" for="forum_id">Category</label>
            <select class="form-select" id="forum_id" name="forum_id">
                <?php foreach ($forums as $forum): ?>
                    <option value="<?= (int) $forum['id'] ?>"><?= e($forum['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label class="form-label mt-2" for="title">Title</label>
            <input class="form-control" id="title" name="title" required minlength="4" maxlength="160">
            <label class="form-label mt-2" for="content">Content</label>
            <textarea class="form-control" id="content" name="content" required minlength="4" rows="4"></textarea>
            <button class="btn btn-accent mt-3" type="submit">Publish</button>
        </form>
    </section>
</div>

<h2 class="mt-4">Categories</h2>
<div class="person-list">
    <?php foreach ($forums as $forum): ?>
        <article class="panel-card">
            <form method="post" class="stack-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_forum">
                <input type="hidden" name="forum_id" value="<?= (int) $forum['id'] ?>">
                <label class="form-label">Name</label>
                <input class="form-control" name="name" value="<?= e($forum['name']) ?>" required>
                <label class="form-label mt-2">Description</label>
                <input class="form-control" name="description" value="<?= e($forum['description']) ?>">
                <label class="form-label mt-2">Sort</label>
                <input class="form-control" name="sort_order" type="number" value="<?= (int) $forum['sort_order'] ?>">
                <label class="form-check mt-2"><input class="form-check-input" type="checkbox" name="member_can_post" <?= (int) $forum['member_can_post'] === 1 ? 'checked' : '' ?>> Members can post</label>
                <button class="btn btn-accent btn-sm mt-2" type="submit">Save</button>
            </form>
            <form method="post" class="mt-2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_forum">
                <input type="hidden" name="forum_id" value="<?= (int) $forum['id'] ?>">
                <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Delete this category and its posts?')">Delete category</button>
            </form>
        </article>
    <?php endforeach; ?>
</div>

<h2 class="mt-4">Posts</h2>
<div class="table-wrap">
    <table class="table">
        <thead><tr><th>Title</th><th>Forum</th><th>Author</th><th>State</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($posts as $post): ?>
            <tr>
                <td><?= e($post['title']) ?></td>
                <td><?= e($post['forum_name']) ?></td>
                <td><?= e($post['username'] ?: 'WebConnect Team') ?></td>
                <td><?= (int) $post['is_hidden'] === 1 ? 'Hidden' : 'Visible' ?></td>
                <td class="admin-actions">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <input type="hidden" name="action" value="<?= (int) $post['is_hidden'] === 1 ? 'unhide' : 'hide' ?>">
                        <button class="btn btn-light btn-sm" type="submit"><?= (int) $post['is_hidden'] === 1 ? 'Restore' : 'Hide' ?></button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="btn btn-danger btn-sm" type="submit" onclick="return confirm('Delete this post?')">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php require dirname(__DIR__) . '/includes/admin_layout_end.php'; ?>
