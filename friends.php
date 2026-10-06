<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_page_user();
$userId = (int) $user['id'];
$tab = (string) ($_GET['tab'] ?? 'friends');
if (!in_array($tab, ['friends', 'incoming', 'outgoing', 'search', 'blocked'], true)) {
    $tab = 'friends';
}
$query = trim((string) ($_GET['q'] ?? ''));
$error = null;

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        $action = (string) ($_POST['action'] ?? '');
        $target = (int) ($_POST['user_id'] ?? $_POST['request_id'] ?? 0);
        if ($action === 'send') {
            send_friend_request($userId, (int) $_POST['user_id']);
            flash_set('success', 'Friend request sent.');
        } elseif ($action === 'accept') {
            accept_friend_request($userId, (int) $_POST['request_id']);
            flash_set('success', 'Friend request accepted.');
        } elseif ($action === 'reject') {
            reject_friend_request($userId, (int) $_POST['request_id']);
            flash_set('success', 'Friend request rejected.');
        } elseif ($action === 'cancel') {
            cancel_friend_request($userId, (int) $_POST['request_id']);
            flash_set('success', 'Friend request cancelled.');
        } elseif ($action === 'remove') {
            remove_friend($userId, (int) $_POST['user_id']);
            flash_set('success', 'Friend removed.');
        } elseif ($action === 'block') {
            block_user($userId, (int) $_POST['user_id']);
            flash_set('success', 'User blocked.');
        } elseif ($action === 'unblock') {
            unblock_user($userId, (int) $_POST['user_id']);
            flash_set('success', 'User unblocked.');
        } else {
            throw new AppException('Unknown action.');
        }
        redirect('friends.php?tab=' . urlencode($tab) . ($query !== '' ? '&q=' . urlencode($query) : ''));
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$friends = list_friends($userId);
$incoming = list_friend_requests($userId, 'incoming');
$outgoing = list_friend_requests($userId, 'outgoing');
$blocked = list_blocked_users($userId);
$results = [];
if ($tab === 'search' && $query !== '') {
    try {
        $results = search_users($userId, $query);
    } catch (AppException $exception) {
        $error = $error ?: $exception->getMessage();
    }
}

$pageTitle = 'Friends';
$activeNav = 'friends';
$pageScript = 'friends.js';
require __DIR__ . '/includes/layout_start.php';

function friend_actions(array $person, string $tab): void
{
    $id = (int) $person['id'];
    $relationship = (string) ($person['relationship'] ?? 'none');
    echo '<div class="person-actions">';
    if ($relationship === 'friend' || $tab === 'friends') {
        echo '<a class="btn btn-accent btn-sm" href="' . e(app_url('chat.php?with=' . $id)) . '">Message</a>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="remove"><input type="hidden" name="user_id" value="' . $id . '"><button class="btn btn-light btn-sm" type="submit" data-confirm="Remove this friend?">Remove</button></form>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="block"><input type="hidden" name="user_id" value="' . $id . '"><button class="btn btn-light btn-sm" type="submit" data-confirm="Block this user?">Block</button></form>';
    } elseif ($tab === 'incoming') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="accept"><input type="hidden" name="request_id" value="' . (int) $person['request_id'] . '"><button class="btn btn-accent btn-sm" type="submit">Accept</button></form>';
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="reject"><input type="hidden" name="request_id" value="' . (int) $person['request_id'] . '"><button class="btn btn-light btn-sm" type="submit">Reject</button></form>';
    } elseif ($tab === 'outgoing') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="' . (int) $person['request_id'] . '"><button class="btn btn-light btn-sm" type="submit">Cancel</button></form>';
    } elseif ($relationship === 'blocked' || $tab === 'blocked') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="unblock"><input type="hidden" name="user_id" value="' . $id . '"><button class="btn btn-accent btn-sm" type="submit">Unblock</button></form>';
    } elseif ($relationship === 'incoming') {
        echo '<a class="btn btn-light btn-sm" href="' . e(app_url('friends.php?tab=incoming')) . '">Review request</a>';
    } elseif ($relationship === 'outgoing') {
        echo '<span class="badge text-bg-light">Request sent</span>';
    } elseif ($relationship !== 'self') {
        echo '<form method="post">' . csrf_field() . '<input type="hidden" name="action" value="send"><input type="hidden" name="user_id" value="' . $id . '"><button class="btn btn-accent btn-sm" type="submit">Add friend</button></form>';
    }
    echo '<a class="btn btn-light btn-sm" href="' . e(app_url('profile.php?id=' . $id)) . '">Profile</a>';
    echo '</div>';
}
?>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
<ul class="nav nav-pills gap-2 mb-4 flex-wrap">
    <?php
    $tabs = [
        'friends' => ['Friends (' . count($friends) . ')', 'fa-user-group'],
        'incoming' => ['Incoming (' . count($incoming) . ')', 'fa-inbox'],
        'outgoing' => ['Sent (' . count($outgoing) . ')', 'fa-paper-plane'],
        'search' => ['Search', 'fa-magnifying-glass'],
        'blocked' => ['Blocked (' . count($blocked) . ')', 'fa-ban'],
    ];
    foreach ($tabs as $key => [$label, $icon]): ?>
        <li class="nav-item">
            <a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e(app_url('friends.php?tab=' . $key)) ?>">
                <i class="fa-solid <?= e($icon) ?>" aria-hidden="true"></i> <?= e($label) ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($tab === 'search'): ?>
    <form class="search-bar" method="get" action="<?= e(app_url('friends.php')) ?>">
        <input type="hidden" name="tab" value="search">
        <label class="visually-hidden" for="q">Search users</label>
        <input class="form-control" id="q" name="q" value="<?= e($query) ?>" placeholder="Search by username or email" minlength="2">
        <button class="btn btn-accent" type="submit">Search</button>
    </form>
<?php endif; ?>

<div class="person-list">
<?php
$rows = match ($tab) {
    'incoming' => $incoming,
    'outgoing' => $outgoing,
    'search' => $results,
    'blocked' => $blocked,
    default => $friends,
};
if ($rows === []): ?>
    <div class="empty-state">
        <img class="empty-visual" src="<?= e(app_url('assets/images/ico-friends.svg')) ?>" alt="">
        <strong><?= $tab === 'search' ? 'Find people' : 'Nothing here yet' ?></strong>
        <p><?= $tab === 'search' ? 'Search for someone by username or email.' : 'Nothing to show in this list yet.' ?></p>
    </div>
<?php else: foreach ($rows as $person): ?>
    <article class="person-card">
        <img src="<?= e($person['avatar_url']) ?>" alt="">
        <div>
            <h2><?= e($person['username']) ?></h2>
            <p><?= e($person['status_message'] !== '' ? $person['status_message'] : $person['presence_label']) ?></p>
            <small class="presence presence-<?= e($person['presence']) ?>"><?= e($person['last_seen_label']) ?></small>
        </div>
        <?php friend_actions($person, $tab); ?>
    </article>
<?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
