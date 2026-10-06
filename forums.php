<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$forums = list_forums();
$selectedSlug = (string) ($_GET['forum'] ?? ($forums[0]['slug'] ?? ''));
$selected = $selectedSlug !== '' ? find_forum_by_slug($selectedSlug) : null;
if (!$selected && $forums) {
    $selected = $forums[0];
}
$postId = (int) ($_GET['post'] ?? 0);
$error = null;
$shareUrl = null;

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $newId = create_forum_post($user, (int) ($_POST['forum_id'] ?? 0), (string) ($_POST['title'] ?? ''), (string) ($_POST['content'] ?? ''));
            flash_set('success', 'Post published.');
            redirect('forums.php?post=' . $newId);
        } elseif ($action === 'comment') {
            create_comment($user, (int) ($_POST['post_id'] ?? 0), (string) ($_POST['content'] ?? ''));
            flash_set('success', 'Comment added.');
            redirect('forums.php?post=' . (int) $_POST['post_id']);
        } elseif ($action === 'delete_comment') {
            delete_comment($userId, (int) ($_POST['comment_id'] ?? 0));
            flash_set('success', 'Comment deleted.');
            redirect('forums.php?post=' . (int) ($_POST['post_id'] ?? 0));
        } elseif ($action === 'delete_post') {
            $deleted = delete_forum_post($userId, (int) ($_POST['post_id'] ?? 0));
            flash_set('success', 'Discussion deleted.');
            $slug = (string) ($deleted['forum_slug'] ?? '');
            redirect($slug !== '' ? 'forums.php?forum=' . rawurlencode($slug) : 'forums.php');
        } elseif ($action === 'like') {
            toggle_post_like($userId, (int) ($_POST['post_id'] ?? 0));
            redirect('forums.php?post=' . (int) $_POST['post_id']);
        } elseif ($action === 'share') {
            $shared = share_post($userId, (int) ($_POST['post_id'] ?? 0));
            flash_set('success', 'Share recorded. Copy this link: ' . $shared['share_url']);
            redirect('forums.php?post=' . (int) $_POST['post_id'] . '&shared=1');
        } elseif ($action === 'report') {
            create_report($userId, 'forum_post', (int) ($_POST['post_id'] ?? 0), (string) ($_POST['reason'] ?? ''));
            flash_set('success', 'Report submitted.');
            redirect('forums.php?post=' . (int) $_POST['post_id']);
        } else {
            throw new AppException('Unknown action.');
        }
    } catch (AppException $exception) {
        $error = $exception->getMessage();
        $postId = (int) ($_POST['post_id'] ?? $postId);
    }
}

$post = null;
if ($postId > 0) {
    try {
        $post = get_forum_post($postId, $userId);
        $shareUrl = $post['share_url'];
    } catch (AppException $exception) {
        $error = $error ?: $exception->getMessage();
    }
}
$posts = ($selected && !$post) ? list_forum_posts((int) $selected['id']) : [];

$pageTitle = 'Community';
$activeNav = 'forums';
$pageScript = 'forums.js';
require __DIR__ . '/includes/layout_start.php';
?>
<div class="split-page forum-page">
    <aside class="panel-card">
        <h2>Forums</h2>
        <nav class="forum-nav">
            <?php foreach ($forums as $forum): ?>
                <a class="<?= $selected && (int) $selected['id'] === (int) $forum['id'] ? 'is-active' : '' ?>" href="<?= e(app_url('forums.php?forum=' . rawurlencode((string) $forum['slug']))) ?>">
                    <strong><?= e($forum['name']) ?></strong>
                    <small><?= e($forum['description']) ?></small>
                    <em><?= (int) $forum['post_count'] ?></em>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>
    <section>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($post): ?>
            <a class="back-link" href="<?= e(app_url('forums.php?forum=' . rawurlencode((string) ($selected['slug'] ?? '')))) ?>">&larr; All discussions</a>
            <article class="post-card">
                <header class="post-card-head">
                    <img src="<?= e($post['avatar_url']) ?>" alt="">
                    <div>
                        <h2><?= e($post['title']) ?></h2>
                        <p><?= e($post['author']) ?> · <?= e($post['created_label']) ?></p>
                    </div>
                    <form method="post" class="post-delete-form" onsubmit="return confirm('Delete this discussion and all its replies?');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete_post">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="btn btn-light btn-sm post-delete-btn" type="submit" aria-label="Delete discussion">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </form>
                </header>
                <div class="post-body"><?= nl2br(e($post['content'])) ?></div>
                <div class="post-tools">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="like">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="btn btn-light btn-sm" type="submit"><?= $post['liked_by_me'] ? 'Liked' : 'Like' ?> · <?= (int) $post['like_count'] ?></button>
                    </form>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="share">
                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                        <button class="btn btn-light btn-sm" type="submit">Share · <?= (int) $post['share_count'] ?></button>
                    </form>
                </div>
                <?php if ($shareUrl): ?>
                    <label class="form-label mt-3" for="share-url">Share link</label>
                    <div class="input-group">
                        <input class="form-control" id="share-url" readonly value="<?= e($shareUrl) ?>">
                        <button class="btn btn-light" type="button" id="copy-share">Copy</button>
                    </div>
                <?php endif; ?>
                <form method="post" class="mt-3">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="report">
                    <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                    <label class="form-label" for="report-reason">Report this post</label>
                    <textarea class="form-control" id="report-reason" name="reason" minlength="10" maxlength="500" required placeholder="Why are you reporting this post?"></textarea>
                    <button class="btn btn-light btn-sm mt-2" type="submit">Submit report</button>
                </form>
            </article>
            <section class="comment-chat">
                <header class="comment-chat-head">
                    <h3><i class="fa-solid fa-comments" aria-hidden="true"></i> Discussion</h3>
                    <span><?= count($post['comments']) ?> <?= count($post['comments']) === 1 ? 'reply' : 'replies' ?></span>
                </header>
                <div class="comment-thread">
                    <?php if ($post['comments'] === []): ?>
                        <div class="comment-empty">No replies yet. Start the conversation below.</div>
                    <?php endif; ?>
                    <?php foreach ($post['comments'] as $comment): ?>
                        <article class="comment-msg<?= !empty($comment['mine']) ? ' is-mine' : '' ?>">
                            <img class="comment-avatar" src="<?= e($comment['avatar_url']) ?>" alt="">
                            <div class="comment-bubble">
                                <div class="comment-meta">
                                    <strong><?= e($comment['author']) ?></strong>
                                    <small><?= e($comment['created_label']) ?></small>
                                </div>
                                <p><?= nl2br(e($comment['content'])) ?></p>
                                <?php if ($comment['mine']): ?>
                                    <form method="post" class="comment-delete">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_comment">
                                        <input type="hidden" name="comment_id" value="<?= (int) $comment['id'] ?>">
                                        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                                        <button type="submit" aria-label="Delete comment"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <form method="post" class="comment-composer" id="comment-composer">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="comment">
                    <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
                    <button type="button" class="icon-btn icon-chip chip-emoji" id="comment-emoji-toggle" aria-label="Insert emoji"><i class="fa-regular fa-face-smile"></i></button>
                    <label class="visually-hidden" for="comment">Write a reply</label>
                    <textarea id="comment" name="content" required maxlength="2000" rows="1" placeholder="Write a reply…"></textarea>
                    <button class="send-btn" type="submit" aria-label="Send reply"><i class="fa-solid fa-paper-plane"></i></button>
                    <div id="comment-emoji-picker" class="comment-emoji-picker d-none" hidden></div>
                </form>
            </section>
        <?php elseif ($selected): ?>
            <header class="section-head">
                <div>
                    <h2><?= e($selected['name']) ?></h2>
                    <p><?= e($selected['description']) ?></p>
                </div>
            </header>
            <?php if ($posts === []): ?>
                <div class="empty-state">
                    <img class="empty-visual" src="<?= e(app_url('assets/images/ico-community.svg')) ?>" alt="">
                    <strong>No posts yet</strong>
                    <p>Be the first to start a discussion in this category.</p>
                </div>
            <?php endif; ?>
            <div class="post-list">
                <?php foreach ($posts as $item): ?>
                    <article class="post-card post-list-card">
                        <a class="post-link" href="<?= e(app_url('forums.php?post=' . (int) $item['id'])) ?>">
                            <h3><?= e($item['title']) ?></h3>
                            <p><?= e(str_limit($item['content'], 180)) ?></p>
                            <small><?= e($item['author']) ?> · <?= e($item['created_label']) ?> · <?= (int) $item['comment_count'] ?> comments · <?= (int) $item['like_count'] ?> likes</small>
                        </a>
                        <form method="post" class="post-delete-form" onsubmit="return confirm('Delete this discussion and all its replies?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_post">
                            <input type="hidden" name="post_id" value="<?= (int) $item['id'] ?>">
                            <button class="icon-btn post-delete-btn" type="submit" aria-label="Delete discussion" title="Delete">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php if ((int) $selected['member_can_post'] === 1): ?>
                <form method="post" class="panel-card mt-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="forum_id" value="<?= (int) $selected['id'] ?>">
                    <h2>Start a discussion</h2>
                    <label class="form-label" for="title">Title</label>
                    <input class="form-control" id="title" name="title" required minlength="4" maxlength="160">
                    <label class="form-label mt-3" for="content">Content</label>
                    <textarea class="form-control" id="content" name="content" required minlength="4" maxlength="5000" rows="5"></textarea>
                    <button class="btn btn-accent mt-3" type="submit">Publish</button>
                </form>
            <?php else: ?>
                <div class="empty-card mt-4">Announcements are published by administrators.</div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
