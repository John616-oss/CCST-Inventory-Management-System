<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
$user = current_user();
ensure_staff_equipment_transactions_table($conn);
$borrowedSubquery = staff_borrowed_units_subquery();

$summary = $conn->query("SELECT
    COALESCE(SUM(CASE WHEN i.status<>'Disposed' THEN i.quantity ELSE 0 END),0) AS total_equipment,
    COALESCE(SUM(CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN GREATEST(i.quantity-COALESCE(b.borrowed,0),0) ELSE 0 END),0) AS available_units,
    COALESCE(SUM(GREATEST(COALESCE(b.borrowed,0),0)),0) AS in_use_units,
    COALESCE(SUM(CASE WHEN i.status<>'Disposed' AND (i.status='Under Repair' OR i.condition_status IN ('Needs Repair','Damaged')) THEN 1 ELSE 0 END),0) AS maintenance_assets
    FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id")->fetch_assoc();
$stateRows = $conn->query("SELECT 'Available' AS asset_state,COALESCE(SUM(GREATEST(i.quantity-COALESCE(b.borrowed,0),0)),0) AS units FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') UNION ALL SELECT 'In Use',COALESCE(SUM(GREATEST(b.borrowed,0)),0) FROM $borrowedSubquery b UNION ALL SELECT 'Under Maintenance',COALESCE(SUM(GREATEST(i.quantity-COALESCE(b.borrowed,0),0)),0) FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status<>'Disposed' AND (i.status='Under Repair' OR i.condition_status IN ('Needs Repair','Damaged')) UNION ALL SELECT 'Disposed',COALESCE(SUM(quantity),0) FROM inventory_items WHERE status='Disposed'");
$stateCounts = ['Available' => 0, 'In Use' => 0, 'Under Maintenance' => 0, 'Disposed' => 0];
while ($state = $stateRows->fetch_assoc()) $stateCounts[$state['asset_state']] += (int)$state['units'];
$categoryRows = $conn->query("SELECT category,SUM(quantity) AS units FROM inventory_items WHERE status<>'Disposed' GROUP BY category ORDER BY units DESC LIMIT 4");
$categoryLabels = [];
$categoryValues = [];
$topCategoryUnits = 0;
while ($category = $categoryRows->fetch_assoc()) {
    $categoryLabels[] = $category['category'];
    $categoryValues[] = (int)$category['units'];
    $topCategoryUnits += (int)$category['units'];
}
$otherUnits = max(0, (int)$conn->query("SELECT COALESCE(SUM(quantity),0) FROM inventory_items WHERE status<>'Disposed'")->fetch_row()[0] - $topCategoryUnits);
if ($otherUnits > 0) {
    $categoryLabels[] = 'Other';
    $categoryValues[] = $otherUnits;
}

$monthStart = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
$monthLabels = [];
$trendCounts = [];
for ($offset = 0; $offset < 6; $offset++) {
    $month = $monthStart->modify('+' . $offset . ' months');
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
$pendingBorrowRequests = (int) $conn->query("SELECT COUNT(*) FROM equipment_requests WHERE status='pending'")->fetch_row()[0];
$overdueReturns = (int) $conn->query("SELECT COUNT(*) FROM equipment_requests r JOIN (SELECT request_id,SUM(CASE action WHEN 'released' THEN CAST(quantity AS SIGNED) WHEN 'returned' THEN -CAST(quantity AS SIGNED) ELSE 0 END) AS outstanding FROM equipment_transactions WHERE request_id IS NOT NULL AND action IN ('released','returned') GROUP BY request_id HAVING outstanding>0) b ON b.request_id=r.id WHERE r.expected_return_date<CURDATE() AND r.status IN ('approved','returned')")->fetch_row()[0];
$recentActivity = $conn->query("SELECT l.action,l.details,l.created_at,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.action IN ('ADD INVENTORY ITEM','UPDATE INVENTORY ITEM','DELETE INVENTORY ITEM','START EQUIPMENT REPAIR','COMPLETE EQUIPMENT REPAIR','RELEASE EQUIPMENT','RETURN EQUIPMENT','REPORT DAMAGED EQUIPMENT') ORDER BY l.id DESC LIMIT 5");
$inventoryAlerts = $conn->query("SELECT
    COUNT(CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') AND GREATEST(i.quantity-COALESCE(b.borrowed,0),0) BETWEEN 1 AND 2 THEN 1 END) AS low_stock,
    COUNT(CASE WHEN i.status<>'Disposed' AND i.condition_status='Damaged' THEN 1 END) AS damaged,
    COUNT(CASE WHEN i.status<>'Disposed' AND (i.status='Under Repair' OR i.condition_status='Needs Repair') THEN 1 END) AS under_maintenance,
    COUNT(CASE WHEN i.status NOT IN ('Available','Disposed') OR i.condition_status IN ('Needs Repair','Damaged') THEN 1 END) AS unavailable
    FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id")->fetch_assoc();
$recentTransactions = $conn->query("SELECT t.action,t.quantity,t.condition_status,t.details,t.created_at,u.full_name AS staff_name,i.asset_tag,i.item_name,r.id AS request_id,borrower.full_name AS borrower_name FROM equipment_transactions t JOIN users u ON u.id=t.user_id JOIN inventory_items i ON i.id=t.inventory_item_id LEFT JOIN equipment_requests r ON r.id=t.request_id LEFT JOIN users borrower ON borrower.id=r.requester_id ORDER BY t.id DESC LIMIT 6");
$monthlyAdds = (int)$conn->query("SELECT COUNT(*) FROM inventory_items WHERE created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_row()[0];
$monthlyRepairs = (int)$conn->query("SELECT COUNT(*) FROM maintenance_history WHERE action='completed' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetch_row()[0];
$roomCount = (int)$conn->query("SELECT COUNT(DISTINCT location) FROM inventory_items WHERE status<>'Disposed'")->fetch_row()[0];
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-dashboard staff-dashboard">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">INVENTORY OPERATIONS</p>
            <h1>Staff Dashboard</h1>
            <p class="dashboard-subtitle">Track equipment availability, locations, and repair progress.</p>
        </div><a class="admin-alert-link" href="<?= e(BASE_URL) ?>/staff/overdue.php" aria-label="View <?= number_format($overdueReturns) ?> overdue returns"><i class="bi bi-bell" aria-hidden="true"></i><?php if ($overdueReturns > 0): ?><span><?= number_format($overdueReturns) ?></span><?php endif; ?></a>
    </header>
    <section class="admin-metric-grid" aria-label="Equipment summary">
        <article class="admin-metric metric-blue">
            <div class="admin-metric-icon"><i class="bi bi-box-seam-fill" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['total_equipment']) ?></strong><span>Total equipment</span></div>
        </article>
        <article class="admin-metric metric-green">
            <div class="admin-metric-icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></div>
            <div><strong><?= number_format((int)$summary['available_units']) ?></strong><span>Available</span></div>
        </article>
        <article class="admin-metric metric-amber">
            <div class="admin-metric-icon"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></div>
            <div><strong><?= number_format($pendingBorrowRequests) ?></strong><span>Pending borrow requests</span></div>
        </article>
        <article class="admin-metric metric-red">
            <div class="admin-metric-icon"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></div>
            <div><strong><?= number_format($overdueReturns) ?></strong><span>Overdue returns</span></div>
        </article>
    </section>
    <section class="admin-analysis-grid">
        <article class="admin-panel admin-chart-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Equipment status</h2>
                    <p>Units across the school inventory</p>
                </div>
            </div>
            <div class="admin-chart-pair">
                <div class="admin-status-group">
                    <div class="admin-donut-wrap"><canvas id="staff-status-chart" aria-label="Equipment units by status"></canvas>
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
                    <div class="admin-chart-title">New inventory <span>Last 6 months</span></div><canvas id="staff-trend-chart" aria-label="Inventory records added over the last six months"></canvas>
                </div>
            </div>
        </article>
        <article class="admin-panel admin-repair-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Repair watchlist</h2>
                    <p><?= number_format($repairQueue->num_rows) ?> items shown</p>
                </div><a href="<?= e(BASE_URL) ?>/inventory.php" aria-label="View equipment"><i class="bi bi-arrow-up-right" aria-hidden="true"></i></a>
            </div>
            <div class="admin-watchlist">
                <?php if ($repairQueue->num_rows): while ($repair = $repairQueue->fetch_assoc()): ?><a class="admin-watch-item" href="<?= e(BASE_URL) ?>/inventory.php?edit=<?= $repair['id'] ?>#item-form"><span class="watch-status-icon"><i class="bi bi-wrench" aria-hidden="true"></i></span><span class="watch-item-copy"><strong><?= e($repair['item_name']) ?></strong><small><?= e($repair['asset_tag']) ?> · <?= e($repair['location']) ?></small></span><span class="watch-state"><?= e($repair['status'] === 'Under Repair' ? 'In repair' : $repair['condition_status']) ?></span></a><?php endwhile;
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                    else: ?><div class="admin-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>No equipment needs attention.</span></div><?php endif; ?>
            </div>
        </article>
    </section>
    <section class="admin-workspace-grid">
        <article class="admin-panel admin-category-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Inventory by category</h2>
                    <p>Active equipment units</p>
                </div>
            </div>
            <div class="admin-category-content">
                <div class="admin-category-donut"><canvas id="staff-category-chart" aria-label="Equipment units by category"></canvas></div>
                <ul class="admin-category-legend"><?php foreach ($categoryLabels as $categoryIndex => $categoryLabel): ?><li><span class="category-swatch category-swatch-<?= $categoryIndex % 5 ?>"></span><span><?= e($categoryLabel) ?></span><strong><?= number_format((int)$categoryValues[$categoryIndex]) ?></strong></li><?php endforeach; ?><?php if (!$categoryLabels): ?><li class="text-secondary">No categories yet</li><?php endif; ?></ul>
            </div>
        </article>
        <article class="admin-panel admin-quick-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Quick actions</h2>
                    <p>Common equipment transactions</p>
                </div>
            </div>
            <div class="admin-quick-actions staff-quick-actions">
                <a href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span>Scan QR Code</span></a>
                <a href="<?= e(BASE_URL) ?>/staff/release_returns.php#release"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Release Equipment</span></a>
                <a href="<?= e(BASE_URL) ?>/staff/release_returns.php#return"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i><span>Process Return</span></a>
                <a href="<?= e(BASE_URL) ?>/staff/damaged.php"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Report Damaged Equipment</span></a>
            </div>
        </article>
    </section>
    <section class="staff-operations-grid">
        <article class="admin-panel staff-alert-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Inventory Alerts</h2>
                    <p>Items requiring attention</p>
                </div>
                <i class="bi bi-bell" aria-hidden="true"></i>
            </div>
            <div class="staff-alert-list">
                <a class="staff-alert-row alert-low-stock" href="<?= e(BASE_URL) ?>/inventory.php">
                    <span><i class="bi bi-box-seam" aria-hidden="true"></i> Low stock</span>
                    <strong><?= number_format((int)$inventoryAlerts['low_stock']) ?></strong>
                </a>
                <a class="staff-alert-row alert-damaged" href="<?= e(BASE_URL) ?>/inventory.php">
                    <span><i class="bi bi-exclamation-octagon" aria-hidden="true"></i> Damaged equipment</span>
                    <strong><?= number_format((int)$inventoryAlerts['damaged']) ?></strong>
                </a>
                <a class="staff-alert-row alert-maintenance" href="<?= e(BASE_URL) ?>/inventory.php?status=Under%20Repair">
                    <span><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> Under maintenance</span>
                    <strong><?= number_format((int)$inventoryAlerts['under_maintenance']) ?></strong>
                </a>
                <a class="staff-alert-row alert-unavailable" href="<?= e(BASE_URL) ?>/inventory.php">
                    <span><i class="bi bi-slash-circle" aria-hidden="true"></i> Unavailable equipment</span>
                    <strong><?= number_format((int)$inventoryAlerts['unavailable']) ?></strong>
                </a>
            </div>
        </article>
        <article class="admin-panel staff-transactions-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Recent Transactions</h2>
                    <p>Latest releases, returns, and damage reports</p>
                </div>
                <a class="admin-view-all" href="<?= e(BASE_URL) ?>/staff/release_returns.php">View all <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="staff-transaction-list">
                <?php if ($recentTransactions->num_rows): while ($transaction = $recentTransactions->fetch_assoc()): ?>
                    <?php
                    $transactionLabel = match ($transaction['action']) {
                        'released' => 'Borrowed / Released',
                        'returned' => 'Returned',
                        'damaged_reported' => 'Damage Reported',
                        default => 'Updated',
                    };
                    ?>
                    <div class="staff-transaction-row">
                        <span class="staff-transaction-icon <?= e('transaction-' . $transaction['action']) ?>">
                            <i class="bi <?= $transaction['action'] === 'released' ? 'bi-box-arrow-up-right' : ($transaction['action'] === 'returned' ? 'bi-box-arrow-in-left' : 'bi-exclamation-triangle') ?>" aria-hidden="true"></i>
                        </span>
                        <div class="staff-transaction-copy">
                            <strong><?= e($transaction['item_name']) ?> · <?= e($transactionLabel) ?></strong>
                            <small>
                                <?= e($transaction['asset_tag']) ?> · <?= number_format((int)$transaction['quantity']) ?> unit<?= (int)$transaction['quantity'] === 1 ? '' : 's' ?> · <?= e($transaction['staff_name']) ?>
                                <?php if ($transaction['borrower_name']): ?> · Borrower: <?= e($transaction['borrower_name']) ?><?php endif; ?>
                                <?php if ($transaction['details']): ?> · <?= e($transaction['details']) ?><?php elseif ($transaction['condition_status']): ?> · <?= e($transaction['condition_status']) ?><?php endif; ?>
                            </small>
                        </div>
                        <time><?= e(date('M j, g:i A', strtotime($transaction['created_at']))) ?></time>
                    </div>
                <?php endwhile; else: ?>
                    <div class="admin-empty-state">No Staff transactions recorded yet.</div>
                <?php endif; ?>
            </div>
        </article>
    </section>
    <section class="admin-bottom-grid">
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
                                <td colspan="6" class="text-center py-4"><strong>No equipment records yet.</strong></td>
                            </tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
        <article class="admin-panel admin-activity-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Recent inventory activity</h2>
                    <p>Equipment and repair updates</p>
                </div>
            </div>
            <div class="admin-activity-list">
                <?php if ($recentActivity->num_rows): while ($activity = $recentActivity->fetch_assoc()): ?><div class="admin-activity-row"><span class="activity-dot"></span>
                            <p><strong><?= e($activity['action']) ?></strong><span><?= e($activity['details'] ?: ($activity['full_name'] ?: 'Inventory update')) ?></span></p><time><?= e(date('M j, g:i A', strtotime($activity['created_at']))) ?></time>
                        </div><?php endwhile;
                        else: ?><div class="admin-empty-state">No inventory activity recorded yet.</div><?php endif; ?>
            </div>
        </article>
    </section>
    <section class="admin-staff-month-summary"><span><i class="bi bi-plus-square" aria-hidden="true"></i><strong><?= number_format($monthlyAdds) ?></strong> assets added this month</span><span><i class="bi bi-arrow-repeat" aria-hidden="true"></i><strong><?= number_format($monthlyRepairs) ?></strong> repairs completed</span><span><i class="bi bi-geo-alt" aria-hidden="true"></i><strong><?= number_format($roomCount) ?></strong> active locations</span></section>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
    const staffChartFont = {
        family: 'DM Sans, sans-serif'
    };
    new Chart(document.getElementById('staff-status-chart'), {
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
                    bodyFont: staffChartFont,
                    titleFont: staffChartFont
                }
            }
        }
    });
    new Chart(document.getElementById('staff-category-chart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($categoryLabels) ?>,
            datasets: [{
                data: <?= json_encode($categoryValues) ?>,
                backgroundColor: ['#1674d1', '#22a36a', '#f0a11a', '#7f67d8', '#31a5bb'],
                borderWidth: 0,
                hoverOffset: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            cutout: '48%',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    bodyFont: staffChartFont,
                    titleFont: staffChartFont
                }
            }
        }
    });
    new Chart(document.getElementById('staff-trend-chart'), {
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
                    bodyFont: staffChartFont,
                    titleFont: staffChartFont
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: staffChartFont,
                        color: '#667995'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        font: staffChartFont,
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
<?php include __DIR__ . '/../includes/footer.php'; ?>