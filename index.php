<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
if (role_home() !== 'index.php') redirect(role_home());
ensure_equipment_requests_table($conn);

$summary = $conn->query("SELECT
	COUNT(CASE WHEN i.status<>'Disposed' THEN 1 END) AS total_assets,
	COALESCE(SUM(CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN GREATEST(i.quantity-COALESCE(b.borrowed,0),0) ELSE 0 END),0) AS available_units,
	COALESCE(SUM(CASE WHEN i.status='In Use' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN i.quantity WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN COALESCE(b.borrowed,0) ELSE 0 END),0) AS in_use_units,
	COUNT(CASE WHEN i.status<>'Disposed' AND (i.status='Under Repair' OR i.condition_status IN ('Needs Repair','Damaged')) THEN 1 END) AS maintenance_assets
	FROM inventory_items i LEFT JOIN (SELECT inventory_item_id,SUM(quantity) AS borrowed FROM equipment_requests WHERE status='approved' GROUP BY inventory_item_id) b ON b.inventory_item_id=i.id")->fetch_assoc();
$stateRows = $conn->query("SELECT 'Available' AS asset_state,COALESCE(SUM(GREATEST(i.quantity-COALESCE(b.borrowed,0),0)),0) AS units FROM inventory_items i LEFT JOIN (SELECT inventory_item_id,SUM(quantity) AS borrowed FROM equipment_requests WHERE status='approved' GROUP BY inventory_item_id) b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') UNION ALL SELECT 'In Use',COALESCE(SUM(quantity),0) FROM inventory_items WHERE status='In Use' AND condition_status NOT IN ('Needs Repair','Damaged') UNION ALL SELECT 'In Use',COALESCE(SUM(b.borrowed),0) FROM inventory_items i JOIN (SELECT inventory_item_id,SUM(quantity) AS borrowed FROM equipment_requests WHERE status='approved' GROUP BY inventory_item_id) b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') UNION ALL SELECT 'Under Maintenance',COALESCE(SUM(quantity),0) FROM inventory_items WHERE status<>'Disposed' AND (status='Under Repair' OR condition_status IN ('Needs Repair','Damaged')) UNION ALL SELECT 'Disposed',COALESCE(SUM(quantity),0) FROM inventory_items WHERE status='Disposed'");
$stateCounts = ['Available' => 0, 'In Use' => 0, 'Under Maintenance' => 0, 'Disposed' => 0];
while ($state = $stateRows->fetch_assoc()) $stateCounts[$state['asset_state']] += (int)$state['units'];

$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$monthKeys = [];
$monthLabels = [];
$trendCounts = [];
for ($offset = 0; $offset < 6; $offset++) {
    $month = $monthStart->modify('+' . $offset . ' months');
    $monthKeys[] = $month->format('Y-m');
    $monthLabels[] = $month->format('M');
    $trendCounts[$month->format('Y-m')] = 0;
}
$trendQuery = $conn->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') AS month_key,COUNT(*) AS added_items FROM inventory_items WHERE created_at>=? GROUP BY month_key ORDER BY month_key");
$trendFrom = $monthStart->format('Y-m-01 00:00:00');
$trendQuery->bind_param('s', $trendFrom);
$trendQuery->execute();
foreach ($trendQuery->get_result() as $trendRow) $trendCounts[$trendRow['month_key']] = (int)$trendRow['added_items'];

$repairQueue = $conn->query("SELECT id,asset_tag,item_name,location,status,condition_status FROM inventory_items WHERE status<>'Disposed' AND (status='Under Repair' OR condition_status IN ('Needs Repair','Damaged')) ORDER BY CASE WHEN status='Under Repair' THEN 0 ELSE 1 END,updated_at ASC LIMIT 5");
$recentItems = $conn->query("SELECT i.id,i.asset_tag,i.item_name,i.category,i.quantity,i.unit,i.location,i.status,i.condition_status,(SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=i.id AND h.action='completed' ORDER BY h.id DESC LIMIT 1) AS last_repaired_by FROM inventory_items i ORDER BY i.updated_at DESC,i.id DESC LIMIT 6");
$recentActivity = $conn->query('SELECT l.action,l.details,l.created_at,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 5');
$recentMaintenance = $conn->query("SELECT h.action,h.created_at,u.full_name,i.asset_tag,i.item_name FROM maintenance_history h JOIN users u ON u.id=h.user_id JOIN inventory_items i ON i.id=h.inventory_item_id ORDER BY h.id DESC LIMIT 4");
$monthlyAdds = (int)$conn->query("SELECT COUNT(*) FROM inventory_items WHERE created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_row()[0];
$monthlyRepairs = (int)$conn->query("SELECT COUNT(*) FROM maintenance_history WHERE action='completed' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_row()[0];
$roomCount = (int)$conn->query("SELECT COUNT(DISTINCT location) FROM inventory_items WHERE status<>'Disposed'")->fetch_row()[0];
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">CCST FACILITIES</p>
            <h1>Inventory Overview</h1>
            <p class="dashboard-subtitle">A current view of school equipment, availability, and repair work.</p>
        </div><a class="admin-alert-link" href="<?= e(BASE_URL) ?>/inventory.php" aria-label="View <?= e($summary['maintenance_assets']) ?> assets needing maintenance"><i class="bi bi-bell" aria-hidden="true"></i><?php if ((int)$summary['maintenance_assets'] > 0): ?><span><?= number_format((int)$summary['maintenance_assets']) ?></span><?php endif; ?></a>
    </header>
    <section class="admin-metric-grid" aria-label="Inventory summary">
        <article class="admin-metric metric-blue">
            <div class="admin-metric-icon"><i class="bi bi-box-seam-fill" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['total_assets']) ?></strong><span>Total assets</span></div>
        </article>
        <article class="admin-metric metric-green">
            <div class="admin-metric-icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['available_units']) ?></strong><span>Available units</span></div>
        </article>
        <article class="admin-metric metric-amber">
            <div class="admin-metric-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['in_use_units']) ?></strong><span>In use</span></div>
        </article>
        <article class="admin-metric metric-red">
            <div class="admin-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['maintenance_assets']) ?></strong><span>Under maintenance</span></div>
        </article>
    </section>
    <section class="admin-analysis-grid">
        <article class="admin-panel admin-chart-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Inventory overview</h2>
                    <p>Equipment units by current status</p>
                </div>
            </div>
            <div class="admin-chart-pair">
                <div class="admin-status-group">
                    <div class="admin-donut-wrap"><canvas id="inventory-state-chart" aria-label="Inventory unit status breakdown"></canvas>
                        <div class="admin-donut-center"><strong><?= number_format(array_sum($stateCounts)) ?></strong><span>Total units</span></div>
                    </div>
                    <ul class="admin-chart-legend">
                        <li><span class="legend-swatch swatch-available"></span>Available <strong><?= number_format($stateCounts['Available']) ?></strong></li>
                        <li><span class="legend-swatch swatch-inuse"></span>In use <strong><?= number_format($stateCounts['In Use']) ?></strong></li>
                        <li><span class="legend-swatch swatch-repair"></span>Maintenance <strong><?= number_format($stateCounts['Under Maintenance']) ?></strong></li>
                        <li><span class="legend-swatch swatch-disposed"></span>Disposed <strong><?= number_format($stateCounts['Disposed']) ?></strong></li>
                    </ul>
                </div>
                <div class="admin-trend-wrap">
                    <div class="admin-chart-title">Inventory trend <span>Last 6 months</span></div><canvas id="inventory-trend-chart" aria-label="New equipment added monthly"></canvas>
                </div>
            </div>
        </article>
        <article class="admin-panel admin-repair-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Maintenance watchlist</h2>
                    <p><?= number_format($repairQueue->num_rows) ?> items shown</p>
                </div><a href="<?= e(BASE_URL) ?>/inventory.php?status=Under%20Repair" aria-label="View equipment needing maintenance"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
            <div class="admin-watchlist"><?php if ($repairQueue->num_rows): while ($repair = $repairQueue->fetch_assoc()): ?><a class="admin-watch-item" href="<?= e(BASE_URL) ?>/inventory.php?edit=<?= $repair['id'] ?>#item-form"><span class="watch-status-icon"><i class="bi bi-wrench" aria-hidden="true"></i></span><span class="watch-item-copy"><strong><?= e($repair['item_name']) ?></strong><small><?= e($repair['asset_tag']) ?> · <?= e($repair['location']) ?></small></span><span class="watch-state"><?= e($repair['status'] === 'Under Repair' ? 'In repair' : $repair['condition_status']) ?></span></a><?php endwhile;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                else: ?><div class="admin-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>No equipment needs repair.</span></div><?php endif; ?></div>
        </article>
    </section>
    <section class="admin-workspace-grid">
        <article class="admin-panel admin-recent-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Recently updated equipment</h2>
                    <p>Latest inventory changes</p>
                </div><a class="admin-view-all" href="<?= e(BASE_URL) ?>/inventory.php">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="table-responsive admin-table-wrap">
                <table class="table table-hover align-middle admin-recent-table">
                    <thead>
                        <tr>
                            <th>Asset tag</th>
                            <th>Equipment</th>
                            <th>Qty</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Repaired by</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentItems->num_rows): while ($item = $recentItems->fetch_assoc()): ?><tr>
                                    <td class="inventory-tag"><?= e($item['asset_tag']) ?></td>
                                    <td><a class="inventory-item-link" href="<?= e(BASE_URL) ?>/inventory.php?edit=<?= $item['id'] ?>#item-form"><?= e($item['item_name']) ?></a><small class="d-block text-secondary"><?= e($item['category']) ?></small></td>
                                    <td><?= number_format((int)$item['quantity']) ?> <?= e($item['unit']) ?></td>
                                    <td><?= e($item['location']) ?></td>
                                    <td><span class="inventory-status"><?= e($item['status']) ?></span></td>
                                    <td><?= e($item['last_repaired_by'] ?? '—') ?></td>
                                </tr><?php endwhile;
                                else: ?><tr>
                                <td colspan="6" class="text-center py-5"><strong>No equipment records yet</strong><br><span class="muted">Add the school's first tagged asset.</span></td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="admin-panel admin-quick-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Quick actions</h2>
                    <p>Common inventory tasks</p>
                </div>
            </div>
            <div class="admin-quick-actions"><a href="<?= e(BASE_URL) ?>/inventory.php#item-form"><i class="bi bi-laptop" aria-hidden="true"></i><span>Add equipment</span></a><a href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span>Scan QR</span></a><a href="<?= e(BASE_URL) ?>/approval_requests.php"><i class="bi bi-shield-check" aria-hidden="true"></i><span>Approval requests</span></a><a href="<?= e(BASE_URL) ?>/logs.php"><i class="bi bi-journal-text" aria-hidden="true"></i><span>Activity logs</span></a></div>
        </article>
    </section>
    <section class="admin-bottom-grid">
        <article class="admin-panel admin-summary-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>This month</h2>
                    <p>Inventory activity summary</p>
                </div>
            </div>
            <div class="admin-summary-items">
                <div><i class="bi bi-plus-square" aria-hidden="true"></i><strong><?= number_format($monthlyAdds) ?></strong><span>New assets</span></div>
                <div><i class="bi bi-arrow-repeat" aria-hidden="true"></i><strong><?= number_format($monthlyRepairs) ?></strong><span>Repairs completed</span></div>
                <div><i class="bi bi-geo-alt" aria-hidden="true"></i><strong><?= number_format($roomCount) ?></strong><span>Active locations</span></div>
            </div>
            <?php if ($recentMaintenance->num_rows): ?><div class="admin-maintenance-activity">
                    <h3>Recent repairs</h3><?php while ($repairLog = $recentMaintenance->fetch_assoc()): ?><div><span class="maintenance-activity-icon"><i class="bi bi-tools" aria-hidden="true"></i></span>
                            <p><strong><?= e($repairLog['full_name']) ?></strong> <?= e($repairLog['action'] === 'completed' ? 'completed repair on' : 'started repair on') ?> <b><?= e($repairLog['item_name']) ?></b><small><?= e($repairLog['asset_tag']) ?> · <?= e(date('M j, Y g:i A', strtotime($repairLog['created_at']))) ?></small></p>
                        </div><?php endwhile; ?>
                </div><?php endif; ?>
        </article>
        <article class="admin-panel admin-activity-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Recent activity</h2>
                    <p>Latest account and inventory events</p>
                </div><a class="admin-view-all" href="<?= e(BASE_URL) ?>/logs.php">View logs <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="admin-activity-list">
                <?php if ($recentActivity->num_rows): while ($activity = $recentActivity->fetch_assoc()): ?><div class="admin-activity-row"><span class="activity-dot"></span>
                            <p><strong><?= e($activity['action']) ?></strong><span><?= e($activity['details'] ?: ($activity['full_name'] ?: 'System event')) ?></span></p><time><?= e(date('M j, g:i A', strtotime($activity['created_at']))) ?></time>
                        </div><?php endwhile;
                        else: ?><div class="admin-empty-state">No activity recorded yet.</div><?php endif; ?>
            </div>
        </article>
    </section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
    const chartFont = {
        family: 'DM Sans, sans-serif'
    };
    new Chart(document.getElementById('inventory-state-chart'), {
        type: 'doughnut',
        data: {
            labels: ['Available', 'In use', 'Under maintenance', 'Disposed'],
            datasets: [{
                data: <?= json_encode(array_values($stateCounts)) ?>,
                backgroundColor: ['#22a36a', '#1674d1', '#f0a11a', '#d84c52'],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '68%',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    bodyFont: chartFont,
                    titleFont: chartFont
                }
            }
        }
    });
    new Chart(document.getElementById('inventory-trend-chart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($monthLabels) ?>,
            datasets: [{
                label: 'Assets added',
                data: <?= json_encode(array_values($trendCounts)) ?>,
                borderColor: '#1674d1',
                backgroundColor: 'rgba(22,116,209,.13)',
                fill: true,
                tension: .34,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#1674d1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    bodyFont: chartFont,
                    titleFont: chartFont
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: chartFont,
                        color: '#667995'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        font: chartFont,
                        color: '#667995'
                    },
                    grid: {
                        color: '#e6edf7'
                    }
                }
            }
        }
    });
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>