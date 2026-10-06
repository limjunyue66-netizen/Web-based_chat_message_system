<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
$admin = require_admin_page();
$status = (string) ($_GET['status'] ?? 'open');
if (!in_array($status, ['open', 'reviewed', 'resolved', 'all'], true)) {
    $status = 'open';
}

if (request_method() === 'POST') {
    try {
        if (!csrf_token_is_valid()) {
            throw new AppException('Invalid security token. Refresh the page and try again.', 419);
        }
        review_report(
            (int) $admin['id'],
            (int) ($_POST['report_id'] ?? 0),
            (string) ($_POST['status'] ?? 'reviewed'),
            (string) ($_POST['admin_note'] ?? '')
        );
        flash_set('success', 'Report updated.');
    } catch (AppException $exception) {
        flash_set('danger', $exception->getMessage());
    }
    redirect('admin/reports.php?status=' . urlencode($status));
}

$reports = list_reports($status === 'all' ? null : $status);
$adminPageTitle = 'Reports';
$adminNav = 'reports';
require dirname(__DIR__) . '/includes/admin_layout_start.php';
?>
<ul class="nav nav-pills gap-2 mb-4">
    <?php foreach (['open' => 'Open', 'reviewed' => 'Reviewed', 'resolved' => 'Resolved', 'all' => 'All'] as $key => $label): ?>
        <li class="nav-item"><a class="nav-link <?= $status === $key ? 'active' : '' ?>" href="<?= e(app_url('admin/reports.php?status=' . $key)) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
</ul>
<?php if ($reports === []): ?><div class="empty-card">No reports in this view.</div><?php endif; ?>
<div class="person-list">
    <?php foreach ($reports as $report): ?>
        <article class="panel-card">
            <p class="eyebrow"><?= e($report['status']) ?> · <?= e(str_replace('_', ' ', (string) $report['target_type'])) ?></p>
            <h2><?= e($report['target_label']) ?></h2>
            <p><?= e($report['reason']) ?></p>
            <small>Reported by <?= e($report['reporter_username'] ?: 'Former member') ?> · <?= e(time_ago($report['created_at'])) ?></small>
            <?php if ($report['admin_note']): ?><p class="mt-2">Note: <?= e($report['admin_note']) ?></p><?php endif; ?>
            <form method="post" class="stack-form mt-3">
                <?= csrf_field() ?>
                <input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>">
                <label class="form-label" for="status-<?= (int) $report['id'] ?>">Status</label>
                <select class="form-select" id="status-<?= (int) $report['id'] ?>" name="status">
                    <?php foreach (['open', 'reviewed', 'resolved'] as $option): ?>
                        <option value="<?= e($option) ?>" <?= $report['status'] === $option ? 'selected' : '' ?>><?= e(ucfirst($option)) ?></option>
                    <?php endforeach; ?>
                </select>
                <label class="form-label mt-2" for="note-<?= (int) $report['id'] ?>">Admin note</label>
                <textarea class="form-control" id="note-<?= (int) $report['id'] ?>" name="admin_note" maxlength="500"><?= e((string) ($report['admin_note'] ?? '')) ?></textarea>
                <button class="btn btn-accent btn-sm mt-2" type="submit">Save review</button>
            </form>
        </article>
    <?php endforeach; ?>
</div>
<?php require dirname(__DIR__) . '/includes/admin_layout_end.php'; ?>
