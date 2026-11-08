<?php
require_once __DIR__ . '/staff/functions.php';
require_role(['admin']);
ensure_staff_equipment_transactions_table($conn);
$threshold = max(0, (int) get_system_setting($conn, 'low_stock_threshold', '2'));
$borrowedSubquery = staff_borrowed_units_subquery();
$notifications = [];
$addNotification = static function (string $type, string $title, string $details, string $createdAt, string $url, string $icon) use (&$notifications): void {
    $notifications[] = [
        'type' => $type,
        'title' => $title,
        'details' => $details,
        'created_at' => $createdAt,
        'url' => $url,
        'icon' => $icon,
        'timestamp' => strtotime($createdAt) ?: 0,
    ];
};

$pending = $conn->query("SELECT r.id,r.created_at,u.full_name,i.item_name,i.asset_tag FROM equipment_requests r JOIN users u ON u.id=r.requester_id JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.status='pending' ORDER BY r.created_at DESC LIMIT 50");
while ($row = $pending->fetch_assoc()) {
    $addNotification('pending', 'New borrowing request', $row['full_name'] . ' requested ' . $row['item_name'] . ' (' . $row['asset_tag'] . ').', $row['created_at'], 'approval_requests.php', 'bi-clipboard-check');
}

$overdue = $conn->query("SELECT r.id,r.expected_return_date,released.created_at,borrower.full_name AS borrower_name,i.item_name,i.asset_tag,DATEDIFF(CURDATE(),r.expected_return_date) AS overdue_days FROM equipment_transactions released JOIN equipment_requests r ON r.id=released.request_id JOIN users borrower ON borrower.id=r.requester_id JOIN inventory_items i ON i.id=released.inventory_item_id WHERE released.action='released' AND r.expected_return_date<CURDATE() AND r.status IN ('approved','returned') AND NOT EXISTS(SELECT 1 FROM equipment_transactions returned WHERE returned.request_id=r.id AND returned.action='returned') ORDER BY r.expected_return_date LIMIT 50");
while ($row = $overdue->fetch_assoc()) {
    $addNotification('overdue', 'Equipment return overdue', $row['borrower_name'] . ' · ' . $row['item_name'] . ' · ' . $row['overdue_days'] . ' day(s) overdue.', $row['expected_return_date'] . ' 00:00:00', 'staff/overdue.php', 'bi-clock-history');
}

$lowStockQuery = $conn->prepare("SELECT i.asset_tag,i.item_name,i.quantity-GREATEST(COALESCE(b.borrowed,0),0) AS available_units,i.updated_at FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') AND GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) BETWEEN 1 AND ? ORDER BY available_units,i.item_name LIMIT 50");
$lowStockQuery->bind_param('i', $threshold);
$lowStockQuery->execute();
$lowStock = $lowStockQuery->get_result();
while ($row = $lowStock->fetch_assoc()) {
    $addNotification('low_stock', 'Low equipment stock', $row['item_name'] . ' (' . $row['asset_tag'] . ') has ' . $row['available_units'] . ' available unit(s).', $row['updated_at'], 'inventory.php', 'bi-box-seam');
}

$damaged = $conn->query("SELECT i.asset_tag,i.item_name,i.updated_at FROM inventory_items i WHERE i.status<>'Disposed' AND i.condition_status='Damaged' ORDER BY i.updated_at DESC LIMIT 50");
while ($row = $damaged->fetch_assoc()) {
    $addNotification('damaged', 'Damaged equipment', $row['item_name'] . ' (' . $row['asset_tag'] . ') is marked damaged.', $row['updated_at'], 'inventory.php', 'bi-exclamation-octagon');
}

$maintenance = $conn->query("SELECT i.asset_tag,i.item_name,i.updated_at FROM inventory_items i WHERE i.status<>'Disposed' AND i.condition_status<>'Damaged' AND (i.status='Under Repair' OR i.condition_status='Needs Repair') ORDER BY i.updated_at DESC LIMIT 50");
while ($row = $maintenance->fetch_assoc()) {
    $addNotification('maintenance', 'Equipment under maintenance', $row['item_name'] . ' (' . $row['asset_tag'] . ') needs repair attention.', $row['updated_at'], 'maintenance_records.php', 'bi-wrench-adjustable');
}

$completedRepairs = $conn->query("SELECT h.created_at,u.full_name,i.id AS inventory_item_id,i.item_name,i.asset_tag FROM maintenance_history h JOIN users u ON u.id=h.user_id JOIN inventory_items i ON i.id=h.inventory_item_id WHERE h.action='completed' AND h.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY h.created_at DESC LIMIT 30");
while ($row = $completedRepairs->fetch_assoc()) {
    $addNotification('repair_completed', 'Repair completed', $row['full_name'] . ' completed a repair on ' . $row['item_name'] . ' (' . $row['asset_tag'] . ').', $row['created_at'], 'maintenance_history.php?id=' . (int) $row['inventory_item_id'], 'bi-check-circle');
}

$accountActivity = $conn->query("SELECT l.action,l.details,l.created_at,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.action IN ('CREATE USER','UPDATE USER','RESET USER PASSWORD','UPDATE ADMIN PROFILE','CHANGE ADMIN PASSWORD','LOGIN','LOGOUT') ORDER BY l.id DESC LIMIT 30");
while ($row = $accountActivity->fetch_assoc()) {
    $addNotification('account', $row['action'], ($row['full_name'] ?: 'Account') . ' · ' . ($row['details'] ?: 'Account activity'), $row['created_at'], 'logs.php', 'bi-person-gear');
}

usort($notifications, static fn (array $left, array $right): int => $right['timestamp'] <=> $left['timestamp']);
$counts = array_fill_keys(['pending','overdue','low_stock','damaged','maintenance','repair_completed','account'], 0);
foreach ($notifications as $notification) {
    $counts[$notification['type']]++;
}
$filter = $_GET['type'] ?? '';
if (array_key_exists($filter, $counts) && $filter !== '') {
    $notifications = array_values(array_filter($notifications, static fn (array $notification): bool => $notification['type'] === $filter));
} else {
    $filter = '';
}
include __DIR__ . '/includes/header.php';
?>
<div class="admin-dashboard admin-notifications-page">
    <header class="admin-dashboard-intro">
        <div>
            <p class="dashboard-eyebrow">SYSTEM MONITORING</p>
            <h1>Notifications</h1>
            <p class="dashboard-subtitle">Live alerts generated from current system records.</p>
        </div>
    </header>

    <section class="admin-notification-metrics" aria-label="Notification totals">
        <?php foreach ([['pending','Pending requests','bi-clipboard-check'],['overdue','Overdue','bi-clock-history'],['low_stock','Low stock','bi-box-seam'],['damaged','Damaged','bi-exclamation-octagon'],['maintenance','Under maintenance','bi-wrench-adjustable'],['repair_completed','Completed repairs','bi-check-circle'],['account','Account activity','bi-person-gear']] as [$type,$label,$icon]): ?>
            <a class="admin-notification-metric <?= $filter === $type ? 'active' : '' ?>" href="<?= e(BASE_URL) ?>/notifications.php?type=<?= e($type) ?>">
                <i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span><strong><?= number_format($counts[$type]) ?></strong>
            </a>
        <?php endforeach; ?>
    </section>

    <section class="admin-panel admin-notification-list-panel">
        <div class="admin-panel-heading"><div><h2><?= $filter !== '' ? e(ucwords(str_replace('_', ' ', $filter))) : 'All notifications' ?></h2><p><?= number_format(count($notifications)) ?> events shown</p></div><a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/notifications.php">Clear filter</a></div>
        <div class="admin-notification-list">
            <?php if ($notifications): ?>
                <?php foreach ($notifications as $notification): ?>
                    <a class="admin-notification-row notification-<?= e($notification['type']) ?>" href="<?= e(BASE_URL . '/' . ltrim($notification['url'], '/')) ?>">
                        <span class="admin-notification-icon"><i class="bi <?= e($notification['icon']) ?>" aria-hidden="true"></i></span>
                        <span class="admin-notification-copy"><strong><?= e($notification['title']) ?></strong><small><?= e($notification['details']) ?></small></span>
                        <time datetime="<?= e(date(DATE_ATOM, strtotime($notification['created_at']))) ?>"><?= e(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></time>
                    </a>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="admin-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>No notifications match this filter.</span></div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
