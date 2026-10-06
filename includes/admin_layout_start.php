<?php
declare(strict_types=1);

$adminPageTitle = $adminPageTitle ?? 'Admin';
$adminNav = $adminNav ?? 'dashboard';
$links = [
    'dashboard' => ['Dashboard', 'index.php', 'fa-gauge'],
    'users' => ['Users', 'users.php', 'fa-users'],
    'forums' => ['Forums', 'forums.php', 'fa-comments'],
    'reports' => ['Reports', 'reports.php', 'fa-flag'],
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($adminPageTitle) ?> · <?= e(APP_NAME) ?> Admin</title>
    <link rel="icon" href="<?= e(app_url('assets/images/mark.svg')) ?>" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= e(app_url('assets/css/app.css')) ?>">
</head>
<body class="app-body">
<div class="app-shell">
    <aside class="side-nav">
        <a class="brand brand-compact" href="<?= e(app_url('admin/index.php')) ?>">
            <span class="brand-mark">W</span>
            <span class="brand-copy"><strong>Admin</strong><small><?= e(APP_NAME) ?></small></span>
        </a>
        <nav class="side-links">
            <?php foreach ($links as $key => [$label, $href, $icon]): ?>
                <a class="<?= $adminNav === $key ? 'is-active' : '' ?>" href="<?= e(app_url('admin/' . $href)) ?>">
                    <i class="fa-solid <?= e($icon) ?>"></i><span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <form class="logout-form" method="post" action="<?= e(app_url('admin/logout.php')) ?>">
            <?= csrf_field() ?>
            <button type="submit"><i class="fa-solid fa-right-from-bracket"></i><span>Log out</span></button>
        </form>
    </aside>
    <div class="app-main">
        <header class="page-heading">
            <div>
                <p class="eyebrow">Administration</p>
                <h1><?= e($adminPageTitle) ?></h1>
            </div>
        </header>
        <?php $flash = flash_take(); if ($flash): ?>
            <div class="flash-wrap"><div class="alert alert-<?= e($flash['type'] === 'error' ? 'danger' : $flash['type']) ?>"><?= e($flash['message']) ?></div></div>
        <?php endif; ?>
