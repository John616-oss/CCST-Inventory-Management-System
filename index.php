<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
if (role_home() !== 'index.php') redirect(role_home());

$summary = $conn->query("SELECT
    COALESCE(SUM(i.quantity),0) AS total_equipment_units,
    COALESCE(SUM(CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN i.quantity ELSE 0 END),0) AS available_units,
    COALESCE(SUM(CASE WHEN i.status='In Use' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN i.quantity ELSE 0 END),0) AS in_use_units,
    COALESCE(SUM(CASE WHEN i.status<>'Disposed' AND i.condition_status='Damaged' THEN i.quantity ELSE 0 END),0) AS damaged_units,
    COALESCE(SUM(CASE WHEN i.status<>'Disposed' AND i.condition_status<>'Damaged' AND (i.status='Under Repair' OR i.condition_status='Needs Repair') THEN i.quantity ELSE 0 END),0) AS maintenance_units
    FROM inventory_items i")->fetch_assoc();
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
        </div>
    </header>
    <section class="admin-metric-grid" aria-label="Inventory summary">
        <article class="admin-metric">
            <div><strong><?= number_format((int)$summary['total_equipment_units']) ?></strong><span>Total equipment units</span></div>
        </article>
        <article class="admin-metric">
            <div><strong><?= number_format((int)$summary['available_units']) ?></strong><span>Available units</span></div>
        </article>
        <article class="admin-metric">
            <div><strong><?= number_format((int)$summary['in_use_units']) ?></strong><span>In use</span></div>
        </article>
        <article class="admin-metric">
            <div><strong><?= number_format((int)$summary['maintenance_units']) ?></strong><span>Under maintenance</span></div>
        </article>
        <article class="admin-metric">
            <div><strong><?= number_format((int)$summary['damaged_units']) ?></strong><span>Damaged equipment</span></div>
        </article>
    </section>
    <section class="admin-analysis-grid admin-repair-grid">
        <article class="admin-panel admin-repair-panel">
            <div class="admin-panel-heading">
                <div>
                    <h2>Maintenance watchlist</h2>
                    <p><?= number_format($repairQueue->num_rows) ?> items shown</p>
                </div><a href="<?= e(BASE_URL) ?>/inventory.php?status=Under%20Repair" aria-label="View equipment needing maintenance">View</a>
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
            <div class="admin-quick-actions"><a href="<?= e(BASE_URL) ?>/inventory.php#item-form"><i class="bi bi-laptop" aria-hidden="true"></i><span>Add equipment</span></a><a href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i><span>Scan QR</span></a><a href="<?= e(BASE_URL) ?>/logs.php"><i class="bi bi-journal-text" aria-hidden="true"></i><span>Activity logs</span></a></div>
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
<?php include __DIR__ . '/includes/footer.php'; ?>