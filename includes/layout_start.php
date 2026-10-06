<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? APP_NAME;
$activeNav = $activeNav ?? '';
$fullBleed = !empty($fullBleed);
$layout = $layout ?? 'app';
$me = current_user();
if ($layout === 'app' && !$me) {
    flash_set('warning', 'Please log in to continue.');
    redirect('login.php');
}
$unreadNotifications = $me ? notification_unread_count((int) $me['id']) : 0;
$flash = flash_take();
$boot = [
    'baseUrl' => rtrim(app_url(), '/'),
    'basePath' => app_base_path(),
    'csrf' => csrf_token(),
    'me' => $me ? user_public($me, true) : null,
    'iceServers' => json_decode((string) RTC_ICE_SERVERS, true) ?: [],
    'unread' => $unreadNotifications,
];
$navItems = [
    'chat' => ['Chat', 'chat.php', 'fa-comment-dots', 'nav-chat'],
    'friends' => ['Friends', 'friends.php', 'fa-user-group', 'nav-friends'],
    'groups' => ['Groups', 'groups.php', 'fa-users', 'nav-groups'],
    'forums' => ['Community', 'forums.php', 'fa-comments', 'nav-forums'],
    'notifications' => ['Alerts', 'notifications.php', 'fa-bell', 'nav-alerts'],
    'profile' => ['Profile', 'profile.php', 'fa-circle-user', 'nav-profile'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(app_url('assets/images/mark.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(asset_url('assets/css/app.css')) ?>">
</head>
<body class="<?= $layout === 'guest' ? 'guest-body' : 'app-body' ?>" data-page="<?= e($activeNav) ?>">
<script>window.WC_BOOT = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<?php if ($layout === 'guest'): ?>
    <header class="guest-bar">
        <a class="brand" href="<?= e(app_url('index.php')) ?>">
            <img class="brand-mark" src="<?= e(app_url('assets/images/mark.svg')) ?>" width="42" height="42" alt="">
            <span><?= e(APP_NAME) ?></span>
        </a>
        <nav class="guest-links">
            <?php if ($me): ?>
                <a href="<?= e(app_url('chat.php')) ?>">Open messages</a>
            <?php else: ?>
                <a href="<?= e(app_url('login.php')) ?>">Log in</a>
                <a class="btn btn-accent btn-sm" href="<?= e(app_url('register.php')) ?>">Create account</a>
            <?php endif; ?>
        </nav>
    </header>
    <main class="guest-main">
<?php else: ?>
    <div class="app-shell">
        <aside class="side-nav">
            <a class="brand brand-compact" href="<?= e(app_url('chat.php')) ?>">
                <img class="brand-mark" src="<?= e(app_url('assets/images/mark.svg')) ?>" width="42" height="42" alt="">
                <span class="brand-copy">
                    <strong><?= e(APP_NAME) ?></strong>
                    <small>Messages &amp; calls</small>
                </span>
            </a>
            <nav class="side-links">
                <?php foreach ($navItems as $key => [$label, $href, $icon, $tone]): ?>
                    <a class="<?= $activeNav === $key ? 'is-active' : '' ?>" href="<?= e(app_url($href)) ?>">
                        <span class="nav-ico <?= e($tone) ?>" aria-hidden="true"><i class="fa-solid <?= e($icon) ?>"></i></span>
                        <span><?= e($label) ?></span>
                        <?php if ($key === 'notifications' && $unreadNotifications > 0): ?>
                            <em class="nav-badge" id="nav-badge"><?= (int) $unreadNotifications ?></em>
                        <?php elseif ($key === 'notifications'): ?>
                            <em class="nav-badge d-none" id="nav-badge">0</em>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form class="logout-form" method="post" action="<?= e(app_url('logout.php')) ?>">
                <?= csrf_field() ?>
                <button type="submit"><span class="nav-ico nav-logout" aria-hidden="true"><i class="fa-solid fa-right-from-bracket"></i></span><span>Log out</span></button>
            </form>
        </aside>
        <div class="app-main <?= $fullBleed ? 'is-bleed' : '' ?>">
            <?php if (!$fullBleed): ?>
                <header class="page-heading">
                    <div>
                        <p class="eyebrow"><?= e(APP_NAME) ?></p>
                        <h1><?= e($pageTitle) ?></h1>
                    </div>
                    <a class="me-card" href="<?= e(app_url('profile.php')) ?>">
                        <img src="<?= e($me ? avatar_url($me) : '') ?>" alt="">
                        <span>
                            <strong><?= e($me['username'] ?? '') ?></strong>
                            <small><?= e(presence_of($me)['label']) ?></small>
                        </span>
                    </a>
                </header>
            <?php endif; ?>
<?php endif; ?>
<?php if ($flash): ?>
    <div class="flash-wrap">
        <div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?> mb-0" role="status"><?= e($flash['message']) ?></div>
    </div>
<?php endif; ?>
