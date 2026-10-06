<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
api_begin();

try {
    $user = require_api_user();
    if (request_method() !== 'POST') {
        throw new AppException('Method not allowed.', 405);
    }
    require_csrf();
    $data = request_data();
    create_report(
        (int) $user['id'],
        (string) ($data['target_type'] ?? ''),
        (int) ($data['target_id'] ?? 0),
        (string) ($data['reason'] ?? '')
    );
    json_ok([], 'Report submitted');
} catch (AppException $exception) {
    json_err($exception->getMessage(), $exception->getCode());
}
