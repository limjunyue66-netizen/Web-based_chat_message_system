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
        register_user(
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['email'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );
        flash_set('success', 'Your account is ready.');
        redirect('chat.php');
    } catch (AppException $exception) {
        $error = $exception->getMessage();
    }
}

$pageTitle = 'Create account';
$layout = 'guest';
require __DIR__ . '/includes/layout_start.php';
?>
<section class="auth-card">
    <h1>Create your account</h1>
    <p class="text-secondary">Usernames are 3-32 characters. Passwords need at least 8 characters, one letter, and one number.</p>
    <?php if ($error): ?><div class="alert alert-danger" role="alert"><?= e($error) ?></div><?php endif; ?>
    <form method="post" action="<?= e(app_url('register.php')) ?>" novalidate>
        <?= csrf_field() ?>
        <label class="form-label" for="username">Username</label>
        <input class="form-control" id="username" name="username" required minlength="3" maxlength="32" pattern="[A-Za-z0-9_]{3,32}" autocomplete="username" value="<?= e((string) ($_POST['username'] ?? '')) ?>">
        <label class="form-label mt-3" for="email">Email</label>
        <input class="form-control" id="email" name="email" type="email" required maxlength="190" autocomplete="email" value="<?= e((string) ($_POST['email'] ?? '')) ?>">
        <label class="form-label mt-3" for="password">Password</label>
        <input class="form-control" id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
        <label class="form-label mt-3" for="confirm_password">Confirm password</label>
        <input class="form-control" id="confirm_password" name="confirm_password" type="password" required minlength="8" autocomplete="new-password">
        <button class="btn btn-accent w-100 mt-4" type="submit">Register</button>
    </form>
    <p class="auth-switch">Already registered? <a href="<?= e(app_url('login.php')) ?>">Log in</a></p>
</section>
<?php require __DIR__ . '/includes/layout_end.php'; ?>
