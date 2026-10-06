<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (request_method() === 'POST' && csrf_token_is_valid()) {
    logout_admin();
    flash_set('success', 'Administrator logged out.');
}
redirect('admin/login.php');
