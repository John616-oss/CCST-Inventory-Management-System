<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);
$summary = $conn->query("SELECT COUNT(*) AS total,
    COALESCE(SUM(status='Completed'),0) AS completed,
    COALESCE(SUM(status='In Progress'),0) AS in_progress,
    COALESCE(SUM(status='Pending'),0) AS pending,
    COALESCE(SUM(status='Cannot Be Repaired'),0) AS cannot_be_repaired
    FROM maintenance_requests")->fetch_assoc();
$reportQuery = $conn->query('SELECT r.id,r.problem_category,r.issue_description,r.priority,r.status,r.repair_description,r.parts_replaced,r.created_at,r.updated_at,i.asset_tag,i.item_name,i.location,reporter.full_name AS reporter_name,assignee.full_name AS assignee_name FROM maintenance_requests r JOIN inventory_items i ON i.id=r.inventory_item_id JOIN users reporter ON reporter.id=r.reported_by LEFT JOIN users assignee ON assignee.id=r.assigned_to ORDER BY r.created_at DESC,r.id DESC LIMIT 300');
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="maintenance-report-' . date('Y-m-d') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date reported', 'Equipment', 'Equipment code', 'Location', 'Category', 'Reported issue', 'Priority', 'Status', 'Repair details', 'Parts replaced', 'Technician']);
    $csvSafe = static function ($value): string {
        $value = (string) $value;
        return preg_match('/^[=+@\\-\\t\\r]/', $value) ? "'" . $value : $value;
    };
    while ($row = $reportQuery->fetch_assoc()) {
        fputcsv($output, array_map($csvSafe, [$row['created_at'], $row['item_name'], $row['asset_tag'], $row['location'], $row['problem_category'], $row['issue_description'], $row['priority'], $row['status'], $row['repair_description'], $row['parts_replaced'], $row['assignee_name']]));
    }
    fclose($output);
    exit;
}
include __DIR__ . '/../includes/header.php';
?>
<div class="maintenance-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">MAINTENANCE ANALYTICS</p><h1>Maintenance reports</h1><p class="dashboard-subtitle">Repair requests and outcomes from the maintenance log.</p></div><div class="maintenance-report-actions no-print"><a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>/maintenance/reports.php?export=csv"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a><button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print</button></div></header>
    <section class="maintenance-metrics" aria-label="Maintenance report summary">
        <article class="maintenance-metric metric-equipment"><span class="maintenance-metric-icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span><div><strong><?= number_format((int) $summary['total']) ?></strong><span>Total requests</span></div></article>
        <article class="maintenance-metric metric-complete"><span class="maintenance-metric-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><div><strong><?= number_format((int) $summary['completed']) ?></strong><span>Completed repairs</span></div></article>
        <article class="maintenance-metric metric-active"><span class="maintenance-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><div><strong><?= number_format((int) $summary['in_progress']) ?></strong><span>In progress</span></div></article>
        <article class="maintenance-metric metric-queue"><span class="maintenance-metric-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><strong><?= number_format((int) $summary['pending']) ?></strong><span>Pending</span></div></article>
    </section>
    <section class="panel maintenance-request-list"><div class="maintenance-records-table-heading"><div class="maintenance-records-table-title"><span><i class="bi bi-clipboard2-data" aria-hidden="true"></i></span><div><h2>Repair report</h2><p>Most recent 300 requests</p></div></div></div>
        <div class="table-responsive"><table class="table align-middle maintenance-records-table"><thead><tr><th>Date reported</th><th>Equipment</th><th>Reported issue</th><th>Priority</th><th>Status</th><th>Repair details</th><th>Parts</th><th>Technician</th></tr></thead><tbody>
            <?php if ($reportQuery->num_rows): while ($row = $reportQuery->fetch_assoc()): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></td><td><strong><?= e($row['item_name']) ?></strong><small><?= e($row['asset_tag']) ?> · <?= e($row['location']) ?></small></td><td class="text-break"><?= e($row['issue_description']) ?></td><td><?= e(ucfirst($row['priority'])) ?></td><td><span class="maintenance-record-status <?= $row['status'] === 'Completed' ? 'status-completed' : ($row['status'] === 'Pending' ? 'status-pending' : ($row['status'] === 'Cannot Be Repaired' ? 'status-danger' : 'status-in-progress')) ?>"><?= e($row['status']) ?></span></td><td class="text-break"><?= e($row['repair_description'] ?: '—') ?></td><td class="text-break"><?= e($row['parts_replaced'] ?: '—') ?></td><td><?= e($row['assignee_name'] ?: 'Unassigned') ?></td></tr><?php endwhile; else: ?><tr><td class="maintenance-records-empty" colspan="8">No maintenance requests have been recorded.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
