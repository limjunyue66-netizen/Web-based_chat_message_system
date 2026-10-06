<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('chat.php');
}

$pageTitle = 'Welcome';
$activeNav = 'home';
$layout = 'guest';
require __DIR__ . '/includes/layout_start.php';
?>
<section class="hero">
    <div class="hero-copy">
        <p class="eyebrow">Browser-based communication</p>
        <h1>Talk, call, and gather in one place.</h1>
        <p class="lead">WebConnect is a private messaging system for text, voice notes, images, files, group rooms, voice calls, and video calls. It runs in the browser you already use.</p>
        <div class="hero-actions">
            <a class="btn btn-accent btn-lg" href="<?= e(app_url('register.php')) ?>">Create an account</a>
            <a class="btn btn-light btn-lg" href="<?= e(app_url('login.php')) ?>">Log in</a>
        </div>
    </div>
    <div class="hero-panel hero-visual" aria-hidden="true">
        <img class="hero-illo" src="<?= e(app_url('assets/images/hero-chat.svg')) ?>" alt="">
    </div>
</section>
<section class="feature-grid">
    <article class="feature-card">
        <img class="feature-ico" src="<?= e(app_url('assets/images/ico-chat.svg')) ?>" alt="">
        <h2>Private &amp; group chat</h2>
        <p>Send text, replies, reactions, images, files, and voice messages with read receipts.</p>
    </article>
    <article class="feature-card">
        <img class="feature-ico" src="<?= e(app_url('assets/images/ico-call.svg')) ?>" alt="">
        <h2>Voice &amp; video calls</h2>
        <p>Call friends in the browser with mute, camera, and end controls. A TURN server can be added for strict networks.</p>
    </article>
    <article class="feature-card">
        <img class="feature-ico" src="<?= e(app_url('assets/images/ico-friends.svg')) ?>" alt="">
        <h2>Friends &amp; safety</h2>
        <p>Requests, blocking, reports, and an administrator panel keep the community manageable.</p>
    </article>
    <article class="feature-card">
        <img class="feature-ico" src="<?= e(app_url('assets/images/ico-community.svg')) ?>" alt="">
        <h2>Community</h2>
        <p>Post in forums, comment, like, share, and report discussions that need review.</p>
    </article>
</section>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
