<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin']);

$search = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['from'] ?? '');
$dateTo = trim($_GET['to'] ?? '');
foreach (['dateFrom', 'dateTo'] as $dateVariable) {
    $value = $$dateVariable;
    if ($value !== '') {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $$dateVariable = '';
        }
    }
}

$conditions = [];
$types = '';
$values = [];
if ($search !== '') {
    $conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR l.action LIKE ? OR l.details LIKE ?)';
    $pattern = '%' . $search . '%';
    $types .= 'ssss';
    $values = array_fill(0, 4, $pattern);
}
if ($dateFrom !== '') {
    $conditions[] = 'l.created_at >= ?';
    $types .= 's';
    $values[] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $conditions[] = 'l.created_at < ?';
    $types .= 's';
    $values[] = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$query = $conn->prepare(
    'SELECT l.id,l.action,l.details,l.ip_address,l.created_at,u.full_name,u.email,u.role
     FROM activity_logs l
     LEFT JOIN users u ON u.id = l.user_id' . $whereSql . '
     ORDER BY l.id DESC
     LIMIT 500'
);
if ($values) {
    stmt_bind($query, $types, $values);
}
$query->execute();
$logs = $query->get_result();
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-logs-page">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">SYSTEM AUDIT</p>
            <h1>Activity Logs</h1>
            <p class="dashboard-subtitle">Review recorded account, inventory, approval, and transaction events.</p>
        </div>
    </header>

    <section class="admin-panel admin-log-panel">
        <form class="admin-log-filters" method="get" role="search">
            <label class="admin-log-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search logs</span><input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search user, action, or details"></label>
            <label><span>From</span><input class="form-control" name="from" type="date" value="<?= e($dateFrom) ?>"></label>
            <label><span>To</span><input class="form-control" name="to" type="date" value="<?= e($dateTo) ?>"></label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <?php if ($search !== '' || $dateFrom !== '' || $dateTo !== ''): ?><a class="btn btn-outline-secondary" href="logs.php" aria-label="Clear log filters"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a><?php endif; ?>
        </form>
        <div class="admin-log-heading"><div><h2>Recorded activity</h2><p><?= number_format($logs->num_rows) ?> entries shown, up to 500</p></div></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle admin-log-table">
                <thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Description</th><th>IP address</th></tr></thead>
                <tbody>
                    <?php if ($logs->num_rows): ?>
                        <?php while ($entry = $logs->fetch_assoc()): ?>
                            <tr>
                                <td><?= e(date('M j, Y g:i:s A', strtotime($entry['created_at']))) ?></td>
                                <td><?= e($entry['full_name'] ?? 'Deleted user') ?><small class="d-block text-secondary"><?= e($entry['email'] ?? '') ?></small></td>
                                <td><?= e(ucfirst($entry['role'] ?? 'Unknown')) ?></td>
                                <td><strong><?= e($entry['action']) ?></strong></td>
                                <td class="text-break"><?= e($entry['details'] ?: '—') ?></td>
                                <td><?= e($entry['ip_address'] ?: '—') ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5"><strong>No activity logs found.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>