<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (current_admin()) {
    redirect('admin/index.php');
}

$error = null;
if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        login_admin((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
        redirect('admin/index.php');
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$pageTitle = 'Admin login';
$layout = 'guest';
$pageScript = 'login.js';
require dirname(__DIR__) . '/includes/layout_start.php';
?>
<section class="auth-card">
    <p class="eyebrow">DEMO ONLY</p>
    <h1>Administrator login</h1>
    <p class="text-secondary">This sign-in is separate from member accounts. Admin credentials are listed below.</p>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
        <?= csrf_field() ?>
        <label class="form-label" for="identifier">Username or email</label>
        <input class="form-control" id="identifier" name="identifier" required autocomplete="username" value="<?= e((string) ($_POST['identifier'] ?? '')) ?>">
        <label class="form-label mt-3" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
        <div class="password-tools">
            <button class="btn btn-light btn-sm" type="button" id="toggle-password" aria-pressed="false">Show password</button>
        </div>
        <div class="login-fills">
            <button class="btn btn-light btn-sm" type="button" data-fill-login data-username="admin" data-password="AdminDemo123!">Fill admin</button>
        </div>
        <button class="btn btn-accent w-100 mt-4" type="submit">Log in</button>
    </form>
    <div class="demo-creds">
        <strong>DEMO ONLY admin</strong>
        <article>
            <p><span>Username</span><code>admin</code></p>
            <p><span>Email</span><code>admin@example.com</code></p>
            <p><span>Password</span><code>AdminDemo123!</code></p>
        </article>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/layout_end.php'; ?>
