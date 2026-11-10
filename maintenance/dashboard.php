<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);

$summary = $conn->query("SELECT
    (SELECT COUNT(*) FROM maintenance_history) AS total_activities,
    (SELECT COUNT(*) FROM maintenance_requests WHERE status='Completed') +
        (SELECT COUNT(*) FROM maintenance_history WHERE action='completed' AND request_id IS NULL) AS completed_repairs")->fetch_assoc();
$repairStats = $conn->query("SELECT
    (SELECT COUNT(*) FROM maintenance_requests WHERE status='In Progress') +
        (SELECT COUNT(*) FROM inventory_items i WHERE i.status='Under Repair' AND NOT EXISTS (SELECT 1 FROM maintenance_requests r WHERE r.inventory_item_id=i.id AND r.status IN ('Pending','In Progress','Cannot Be Repaired'))) AS in_progress_repairs,
    (SELECT COUNT(*) FROM maintenance_requests WHERE status='Pending') +
        (SELECT COUNT(*) FROM inventory_items i WHERE i.status<>'Disposed' AND i.status<>'Under Repair' AND i.condition_status IN ('Needs Repair','Damaged') AND NOT EXISTS (SELECT 1 FROM maintenance_requests r WHERE r.inventory_item_id=i.id AND r.status IN ('Pending','In Progress','Cannot Be Repaired'))) AS pending_repairs,
    (SELECT COUNT(*) FROM maintenance_requests WHERE status='Cannot Be Repaired') AS cannot_be_repaired
")->fetch_assoc();
$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$repairTrend = [];
for ($offset = 0; $offset < 6; $offset++) {
    $month = $monthStart->modify('+' . $offset . ' months');
    $repairTrend[$month->format('Y-m')] = ['label' => $month->format('M'), 'completed' => 0, 'in_progress' => 0, 'pending' => 0];
}
$trendQuery = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month_key,
    SUM(action='completed') AS completed,
    SUM(action='started') AS in_progress,
    SUM(action='reported') AS pending
    FROM maintenance_history WHERE created_at>=? GROUP BY month_key ORDER BY month_key");
$trendFrom = $monthStart->format('Y-m-01 00:00:00');
$trendQuery->bind_param('s', $trendFrom);
$trendQuery->execute();
foreach ($trendQuery->get_result() as $row) {
    if (isset($repairTrend[$row['month_key']])) {
        $repairTrend[$row['month_key']]['completed'] = (int) $row['completed'];
        $repairTrend[$row['month_key']]['in_progress'] = (int) $row['in_progress'];
        $repairTrend[$row['month_key']]['pending'] = (int) $row['pending'];
    }
}
$statusCounts = [
    'Completed' => (int) $summary['completed_repairs'],
    'In Progress' => (int) $repairStats['in_progress_repairs'],
    'Pending' => (int) $repairStats['pending_repairs'],
];
$statusTotal = array_sum($statusCounts);
$trendLabels = array_column($repairTrend, 'label');
$trendChartData = [
    'completed' => array_column($repairTrend, 'completed'),
    'inProgress' => array_column($repairTrend, 'in_progress'),
    'pending' => array_column($repairTrend, 'pending'),
];
$repairQueue = $conn->query("SELECT id,asset_tag,item_name,location,status,condition_status FROM inventory_items WHERE status<>'Disposed' AND (status='Under Repair' OR condition_status IN ('Needs Repair','Damaged')) ORDER BY CASE WHEN status='Under Repair' THEN 0 ELSE 1 END,updated_at ASC LIMIT 6");
$recentRecords = $conn->query("SELECT h.created_at,h.action,h.work_description,r.issue_description,r.status,i.id AS item_id,i.asset_tag,i.item_name,u.full_name AS technician
    FROM maintenance_history h JOIN inventory_items i ON i.id=h.inventory_item_id JOIN users u ON u.id=h.user_id
    LEFT JOIN maintenance_requests r ON r.id=h.request_id ORDER BY h.id DESC LIMIT 6");

include __DIR__ . '/../includes/header.php';
?>
<div class="maintenance-dashboard">
    <header class="maintenance-hero">
        <div class="maintenance-hero-copy">
            <p class="maintenance-hero-eyebrow"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> CCST FACILITIES</p>
            <h1>Welcome, <?= e(current_user()['full_name']) ?>!</h1>
            <p>Keep school equipment ready, reliable, and working.</p>
            <a class="maintenance-hero-link" href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> Start a repair scan</a>
        </div>
        <div class="maintenance-hero-mark" aria-hidden="true"><i class="bi bi-tools"></i></div>
    </header>

    <section class="maintenance-metrics" aria-label="Maintenance summary">
        <article class="maintenance-metric metric-equipment"><span class="maintenance-metric-icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span><div><strong><?= number_format((int)$summary['total_activities']) ?></strong><span>Total maintenance activities</span></div></article>
        <article class="maintenance-metric metric-complete"><span class="maintenance-metric-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><div><strong><?= number_format((int)$summary['completed_repairs']) ?></strong><span>Completed repairs</span></div></article>
        <article class="maintenance-metric metric-active"><span class="maintenance-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><div><strong><?= number_format((int)$repairStats['in_progress_repairs']) ?></strong><span>Repairs in progress</span></div></article>
        <article class="maintenance-metric metric-queue"><span class="maintenance-metric-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><div><strong><?= number_format((int)$repairStats['pending_repairs']) ?></strong><span>Pending repairs</span></div></article>
    </section>

    <section class="maintenance-insights">
        <article class="panel maintenance-panel maintenance-health-panel">
            <div class="maintenance-panel-heading"><div><h2>Maintenance status</h2><p>Open and completed repair totals</p></div><i class="bi bi-pie-chart" aria-hidden="true"></i></div>
            <div class="maintenance-status-chart-wrap"><canvas id="maintenance-status-chart" aria-label="Maintenance status distribution" role="img"></canvas><span><?= number_format($statusTotal) ?><small>repairs</small></span></div>
            <ul class="maintenance-health-legend">
                <?php foreach ($statusCounts as $label => $count): $percent = $statusTotal ? round($count / $statusTotal * 100) : 0; ?>
                    <li><i class="health-dot <?= $label === 'Completed' ? 'health-good' : ($label === 'In Progress' ? 'health-repair' : 'health-needs') ?>"></i><span><?= e($label) ?></span><strong><?= number_format($count) ?> <small><?= $percent ?>%</small></strong></li>
                <?php endforeach; ?>
            </ul>
        </article>

        <article class="panel maintenance-panel maintenance-trend-panel">
            <div class="maintenance-panel-heading"><div><h2>Maintenance activity</h2><p>Repair events · last 6 months</p></div><i class="bi bi-activity" aria-hidden="true"></i></div>
            <div class="maintenance-activity-chart-wrap"><canvas id="maintenance-activity-chart" aria-label="Monthly completed, in progress, and pending maintenance events" role="img"></canvas></div>
        </article>
    </section>

    <section class="maintenance-workspace">
        <article class="panel maintenance-panel maintenance-queue-panel" id="repair-queue">
            <div class="maintenance-panel-heading"><div><h2>Repair queue</h2><p><?= number_format($repairQueue->num_rows) ?> equipment item<?= $repairQueue->num_rows === 1 ? '' : 's' ?> shown</p></div><a href="<?= e(BASE_URL) ?>/scan.php" aria-label="Open QR scanner"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></div>
            <div class="table-responsive"><table class="table align-middle maintenance-queue-table"><thead><tr><th>Equipment</th><th>Location</th><th>Condition</th><th>Status</th><th></th></tr></thead><tbody>
                <?php if ($repairQueue->num_rows): while ($item = $repairQueue->fetch_assoc()): ?><tr>
                    <td><strong><?= e($item['item_name']) ?></strong><small><?= e($item['asset_tag']) ?></small></td>
                    <td><?= e($item['location']) ?></td>
                    <td><?= e($item['condition_status']) ?></td>
                    <td><span class="maintenance-status <?= $item['status'] === 'Under Repair' ? 'status-in-repair' : 'status-needs-attention' ?>"><?= e($item['status'] === 'Under Repair' ? 'In repair' : 'Needs attention') ?></span></td>
                    <td><a class="maintenance-row-action" href="<?= e(BASE_URL) ?>/scan.php?tag=<?= e(rawurlencode($item['asset_tag'])) ?>" aria-label="Open repair for <?= e($item['item_name']) ?>" title="Open repair"><i class="bi bi-arrow-right" aria-hidden="true"></i></a></td>
                </tr><?php endwhile; else: ?><tr><td class="maintenance-empty" colspan="5"><i class="bi bi-check2-circle" aria-hidden="true"></i><strong>All clear</strong><span>No equipment currently needs repair.</span></td></tr><?php endif; ?>
            </tbody></table></div>
        </article>

        <aside class="maintenance-side-column">
            <article class="panel maintenance-panel maintenance-actions-panel">
                <div class="maintenance-panel-heading"><div><h2>Quick actions</h2><p>Maintenance workspace</p></div><i class="bi bi-lightning-charge" aria-hidden="true"></i></div>
                <div class="maintenance-quick-actions">
                    <a href="<?= e(BASE_URL) ?>/maintenance/requests.php#report"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Report maintenance</span></a>
                    <a href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span>Scan QR code</span></a>
                    <a href="<?= e(BASE_URL) ?>/maintenance_records.php"><i class="bi bi-clock-history" aria-hidden="true"></i><span>View repair history</span></a>
                    <a href="<?= e(BASE_URL) ?>/maintenance/equipment.php"><i class="bi bi-box-seam" aria-hidden="true"></i><span>Check equipment status</span></a>
                </div>
            </article>
        </aside>
    </section>
    <section class="panel maintenance-panel maintenance-recent-records">
        <div class="maintenance-panel-heading"><div><h2>Recent maintenance records</h2><p>Latest recorded reports and repair actions</p></div><a href="<?= e(BASE_URL) ?>/maintenance_records.php">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div>
        <div class="table-responsive"><table class="table align-middle maintenance-queue-table"><thead><tr><th>Date and time</th><th>Equipment</th><th>Reported issue</th><th>Repair status</th><th>Technician</th></tr></thead><tbody>
            <?php if ($recentRecords->num_rows): while ($record = $recentRecords->fetch_assoc()): $recordStatus = $record['status'] ?? ($record['action'] === 'completed' ? 'Completed' : ($record['action'] === 'started' ? 'In Progress' : ucfirst(str_replace('_', ' ', $record['action'])))); ?>
                <tr><td><?= e(date('M j, Y g:i A', strtotime($record['created_at']))) ?></td><td><strong><?= e($record['item_name']) ?></strong><small><?= e($record['asset_tag']) ?></small></td><td><?= e($record['issue_description'] ?: ($record['work_description'] ?: 'Legacy maintenance activity')) ?></td><td><span class="maintenance-record-status <?= $recordStatus === 'Completed' ? 'status-completed' : ($recordStatus === 'Pending' ? 'status-pending' : 'status-in-progress') ?>"><?= e($recordStatus) ?></span></td><td><?= e($record['technician']) ?></td></tr>
            <?php endwhile; else: ?><tr><td colspan="5" class="maintenance-empty">No repair activity recorded yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.8/dist/chart.umd.min.js"></script>
<script>
const maintenanceStatusData = <?= json_encode(array_values($statusCounts), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const maintenanceTrendData = <?= json_encode($trendChartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const maintenanceLabels = <?= json_encode($trendLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
if (window.Chart) {
    new Chart(document.getElementById('maintenance-status-chart'), {
        type: 'doughnut',
        data: { labels: ['Completed', 'In Progress', 'Pending'], datasets: [{ data: maintenanceStatusData, backgroundColor: ['#16a34a', '#2563eb', '#f59e0b'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '72%', plugins: { legend: { display: false } } }
    });
    new Chart(document.getElementById('maintenance-activity-chart'), {
        type: 'bar',
        data: { labels: maintenanceLabels, datasets: [
            { label: 'Completed', data: maintenanceTrendData.completed, backgroundColor: '#16a34a', borderRadius: 4 },
            { label: 'In progress', data: maintenanceTrendData.inProgress, backgroundColor: '#2563eb', borderRadius: 4 },
            { label: 'Pending reports', data: maintenanceTrendData.pending, backgroundColor: '#f59e0b', borderRadius: 4 }
        ] },
        options: { responsive: true, maintainAspectRatio: false, interaction: { intersect: false, mode: 'index' }, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { position: 'bottom' } } }
    });
}
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>