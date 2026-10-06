<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (request_method() === 'POST' && csrf_token_is_valid()) {
    logout_user();
    flash_set('success', 'You have been logged out.');
}
redirect('login.php');
