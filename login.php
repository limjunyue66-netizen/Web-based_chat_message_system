<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('chat.php');
}

$error = null;
if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        login_user((string) ($_POST['identifier'] ?? ''), (string) ($_POST['password'] ?? ''));
        redirect('chat.php');
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$pageTitle = 'Log in';
$layout = 'guest';
$pageScript = 'login.js';
require __DIR__ . '/includes/layout_start.php';
?>
<section class="auth-card">
    <h1>Welcome back</h1>
    <p class="text-secondary">Use your username or email to sign in. DEMO ONLY accounts are listed below.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(app_url('login.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="identifier">Username or email</label>
        <input class="form-control" id="identifier" name="identifier" required autocomplete="username" value="<?= e((string) ($_POST['identifier'] ?? '')) ?>">
        <label class="form-label mt-3" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required autocomplete="current-password">
        <div class="password-tools">
            <button class="btn btn-light btn-sm" type="button" id="toggle-password" aria-pressed="false">Show password</button>
        </div>
        <div class="login-fills">
            <button class="btn btn-light btn-sm" type="button" data-fill-login data-username="demo_alex" data-password="DemoPass123">Fill demo_alex</button>
            <button class="btn btn-light btn-sm" type="button" data-fill-login data-username="demo_blair" data-password="DemoPass123">Fill demo_blair</button>
        </div>
        <button class="btn btn-accent w-100 mt-4" type="submit">Log in</button>
    </form>
    <div class="demo-creds">
        <strong>DEMO ONLY accounts</strong>
        <article>
            <p><span>Username</span><code>demo_alex</code></p>
            <p><span>Email</span><code>alex@example.com</code></p>
            <p><span>Password</span><code>DemoPass123</code></p>
        </article>
        <article>
            <p><span>Username</span><code>demo_blair</code></p>
            <p><span>Email</span><code>blair@example.com</code></p>
            <p><span>Password</span><code>DemoPass123</code></p>
        </article>
    </div>
    <p class="auth-switch">New here? <a href="<?= e(app_url('register.php')) ?>">Create an account</a></p>
</section>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
