<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);

$summary = $conn->query("SELECT
    COUNT(CASE WHEN status<>'Disposed' THEN 1 END) AS equipment_count,
    COUNT(CASE WHEN status<>'Disposed' AND (status='Under Repair' OR condition_status IN ('Needs Repair','Damaged')) THEN 1 END) AS repair_queue_count,
    COUNT(CASE WHEN status='Under Repair' THEN 1 END) AS in_repair_count
    FROM inventory_items")->fetch_assoc();
$health = ['Good' => 0, 'Under Repair' => 0, 'Needs Repair' => 0, 'Damaged' => 0];
$healthRows = $conn->query("SELECT CASE
    WHEN status='Under Repair' THEN 'Under Repair'
    WHEN condition_status='Damaged' THEN 'Damaged'
    WHEN condition_status='Needs Repair' THEN 'Needs Repair'
    ELSE 'Good' END AS health_group, COUNT(*) AS total
    FROM inventory_items WHERE status<>'Disposed' GROUP BY health_group");
while ($row = $healthRows->fetch_assoc()) $health[$row['health_group']] = (int)$row['total'];
$healthTotal = array_sum($health);
$healthGoodEnd = $healthTotal ? $health['Good'] / $healthTotal * 100 : 0;
$healthRepairEnd = $healthGoodEnd + ($healthTotal ? $health['Under Repair'] / $healthTotal * 100 : 0);
$healthNeedsEnd = $healthRepairEnd + ($healthTotal ? $health['Needs Repair'] / $healthTotal * 100 : 0);
$healthStyle = '--health-good:' . number_format($healthGoodEnd, 1, '.', '') . '%;--health-repair:' . number_format($healthRepairEnd, 1, '.', '') . '%;--health-needs:' . number_format($healthNeedsEnd, 1, '.', '') . '%';

$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$repairTrend = [];
for ($offset = 0; $offset < 6; $offset++) {
    $month = $monthStart->modify('+' . $offset . ' months');
    $repairTrend[$month->format('Y-m')] = ['label' => $month->format('M'), 'total' => 0];
}
$trendQuery = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month_key, COUNT(*) AS total FROM maintenance_history WHERE action='completed' AND created_at>=? GROUP BY month_key ORDER BY month_key");
$trendFrom = $monthStart->format('Y-m-01 00:00:00');
$trendQuery->bind_param('s', $trendFrom);
$trendQuery->execute();
foreach ($trendQuery->get_result() as $row) $repairTrend[$row['month_key']]['total'] = (int)$row['total'];
$trendMax = max(1, max(array_column($repairTrend, 'total')));
$repairsThisMonth = (int)$conn->query("SELECT COUNT(*) FROM maintenance_history WHERE action='completed' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_row()[0];
$repairQueue = $conn->query("SELECT id,asset_tag,item_name,location,status,condition_status FROM inventory_items WHERE status<>'Disposed' AND (status='Under Repair' OR condition_status IN ('Needs Repair','Damaged')) ORDER BY CASE WHEN status='Under Repair' THEN 0 ELSE 1 END,updated_at ASC LIMIT 6");
$recentActivity = $conn->query('SELECT h.action,h.created_at,i.asset_tag,i.item_name FROM maintenance_history h JOIN inventory_items i ON i.id=h.inventory_item_id ORDER BY h.id DESC LIMIT 5');

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
        <article class="maintenance-metric metric-equipment"><span class="maintenance-metric-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><div><strong><?= number_format((int)$summary['equipment_count']) ?></strong><span>Equipment items</span></div></article>
        <article class="maintenance-metric metric-queue"><span class="maintenance-metric-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span><div><strong><?= number_format((int)$summary['repair_queue_count']) ?></strong><span>Need attention</span></div></article>
        <article class="maintenance-metric metric-active"><span class="maintenance-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><div><strong><?= number_format((int)$summary['in_repair_count']) ?></strong><span>In repair</span></div></article>
        <article class="maintenance-metric metric-complete"><span class="maintenance-metric-icon"><i class="bi bi-check2-circle" aria-hidden="true"></i></span><div><strong><?= number_format($repairsThisMonth) ?></strong><span>Repairs completed this month</span></div></article>
    </section>

    <section class="maintenance-insights">
        <article class="panel maintenance-panel maintenance-health-panel">
            <div class="maintenance-panel-heading"><div><h2>Equipment condition</h2><p>Current condition of active equipment</p></div><i class="bi bi-pie-chart" aria-hidden="true"></i></div>
            <div class="maintenance-health-content">
                <div class="maintenance-health-ring<?= $healthTotal === 0 ? ' maintenance-health-empty-ring' : '' ?>" style="<?= e($healthStyle) ?>" role="img" aria-label="Equipment condition: <?= number_format($health['Good']) ?> good, <?= number_format($health['Under Repair']) ?> under repair, <?= number_format($health['Needs Repair']) ?> need repair, <?= number_format($health['Damaged']) ?> damaged"><div><strong><?= number_format($healthTotal) ?></strong><span>Active items</span></div></div>
                <ul class="maintenance-health-legend">
                    <li><i class="health-dot health-good"></i><span>Good condition</span><strong><?= number_format($health['Good']) ?></strong></li>
                    <li><i class="health-dot health-repair"></i><span>Under repair</span><strong><?= number_format($health['Under Repair']) ?></strong></li>
                    <li><i class="health-dot health-needs"></i><span>Needs repair</span><strong><?= number_format($health['Needs Repair']) ?></strong></li>
                    <li><i class="health-dot health-damaged"></i><span>Damaged</span><strong><?= number_format($health['Damaged']) ?></strong></li>
                </ul>
            </div>
        </article>

        <article class="panel maintenance-panel maintenance-trend-panel">
            <div class="maintenance-panel-heading"><div><h2>Repair activity</h2><p>Completed repairs · last 6 months</p></div><i class="bi bi-activity" aria-hidden="true"></i></div>
            <div class="maintenance-bar-chart" role="img" aria-label="Monthly completed repairs for the last six months">
                <?php foreach ($repairTrend as $month): $barHeight = $month['total'] ? max(8, $month['total'] / $trendMax * 100) : 0; ?>
                    <div class="maintenance-bar-column"><span class="maintenance-bar-value"><?= $month['total'] ?: '' ?></span><div class="maintenance-bar-track"><span style="--bar-height:<?= number_format($barHeight, 1, '.', '') ?>%"></span></div><span class="maintenance-bar-label"><?= e($month['label']) ?></span></div>
                <?php endforeach; ?>
            </div>
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
                    <a href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span>Scan equipment</span></a>
                    <a href="#repair-queue"><i class="bi bi-clipboard2-check" aria-hidden="true"></i><span>View repair queue</span></a>
                    <a href="<?= e(BASE_URL) ?>/maintenance/profile.php"><i class="bi bi-person-circle" aria-hidden="true"></i><span>My profile</span></a>
                    <a href="#recent-activity"><i class="bi bi-clock-history" aria-hidden="true"></i><span>Recent activity</span></a>
                </div>
            </article>
            <article class="panel maintenance-panel maintenance-activity-panel" id="recent-activity">
                <div class="maintenance-panel-heading"><div><h2>Recent activity</h2><p>Latest repair updates</p></div><i class="bi bi-clock-history" aria-hidden="true"></i></div>
                <div class="maintenance-activity-list">
                    <?php if ($recentActivity->num_rows): while ($activity = $recentActivity->fetch_assoc()): ?>
                        <div class="maintenance-activity-row"><span class="maintenance-activity-icon <?= $activity['action'] === 'completed' ? 'activity-complete' : '' ?>"><i class="bi <?= $activity['action'] === 'completed' ? 'bi-check2' : 'bi-wrench-adjustable' ?>" aria-hidden="true"></i></span><p><strong><?= e($activity['item_name']) ?></strong><span><?= e($activity['action'] === 'completed' ? 'Repair completed' : 'Repair started') ?> · <?= e($activity['asset_tag']) ?></span><time datetime="<?= e(date('c', strtotime($activity['created_at']))) ?>"><?= e(date('M j, Y · g:i A', strtotime($activity['created_at']))) ?></time></p></div>
                    <?php endwhile; else: ?><p class="maintenance-activity-empty">Repair actions will appear here.</p><?php endif; ?>
                </div>
            </article>
        </aside>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>