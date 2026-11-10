<?php
require_once __DIR__ . '/staff/functions.php';
require_role(['admin']);
ensure_staff_equipment_transactions_table($conn);
$conn->query("CREATE TABLE IF NOT EXISTS notification_dismissals (
    user_id INT NOT NULL,
    notification_key VARCHAR(190) NOT NULL,
    dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, notification_key),
    INDEX idx_notification_dismissals_date (user_id, dismissed_at),
    CONSTRAINT fk_notification_dismissals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$userId = (int) current_user()['id'];
$threshold = max(0, (int) get_system_setting($conn, 'low_stock_threshold', '2'));
$borrowedSubquery = staff_borrowed_units_subquery();
$notifications = [];
$addNotification = static function (string $type, string $sourceId, string $title, string $details, string $createdAt, string $url, string $icon) use (&$notifications): void {
    $notifications[] = [
        'type' => $type,
        'notification_key' => $type . ':' . $sourceId,
        'title' => $title,
        'details' => $details,
        'created_at' => $createdAt,
        'url' => $url,
        'icon' => $icon,
        'timestamp' => strtotime($createdAt) ?: 0,
    ];
};

$pending = $conn->query("SELECT r.id,r.created_at,r.updated_at,u.full_name,i.item_name,i.asset_tag FROM equipment_requests r JOIN users u ON u.id=r.requester_id JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.status='pending' ORDER BY r.created_at DESC LIMIT 50");
while ($row = $pending->fetch_assoc()) {
    $addNotification('pending', $row['id'] . ':' . $row['updated_at'], 'New borrowing request', $row['full_name'] . ' requested ' . $row['item_name'] . ' (' . $row['asset_tag'] . ').', $row['created_at'], 'approval_requests.php', 'bi-clipboard-check');
}

$overdue = $conn->query("SELECT r.id,r.expected_return_date,released.created_at,borrower.full_name AS borrower_name,i.item_name,i.asset_tag,DATEDIFF(CURDATE(),r.expected_return_date) AS overdue_days FROM equipment_transactions released JOIN equipment_requests r ON r.id=released.request_id JOIN users borrower ON borrower.id=r.requester_id JOIN inventory_items i ON i.id=released.inventory_item_id WHERE released.action='released' AND r.expected_return_date<CURDATE() AND r.status IN ('approved','returned') AND NOT EXISTS(SELECT 1 FROM equipment_transactions returned WHERE returned.request_id=r.id AND returned.action='returned') ORDER BY r.expected_return_date LIMIT 50");
while ($row = $overdue->fetch_assoc()) {
    $addNotification('overdue', $row['id'] . ':' . $row['overdue_days'], 'Equipment return overdue', $row['borrower_name'] . ' · ' . $row['item_name'] . ' · ' . $row['overdue_days'] . ' day(s) overdue.', $row['expected_return_date'] . ' 00:00:00', 'staff/overdue.php', 'bi-clock-history');
}

$lowStockQuery = $conn->prepare("SELECT i.id,i.asset_tag,i.item_name,i.quantity-GREATEST(COALESCE(b.borrowed,0),0) AS available_units,i.updated_at FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') AND GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) BETWEEN 1 AND ? ORDER BY available_units,i.item_name LIMIT 50");
$lowStockQuery->bind_param('i', $threshold);
$lowStockQuery->execute();
$lowStock = $lowStockQuery->get_result();
while ($row = $lowStock->fetch_assoc()) {
    $addNotification('low_stock', $row['id'] . ':' . $row['updated_at'], 'Low equipment stock', $row['item_name'] . ' (' . $row['asset_tag'] . ') has ' . $row['available_units'] . ' available unit(s).', $row['updated_at'], 'inventory.php', 'bi-box-seam');
}

$damaged = $conn->query("SELECT i.id,i.asset_tag,i.item_name,i.updated_at FROM inventory_items i WHERE i.status<>'Disposed' AND i.condition_status='Damaged' ORDER BY i.updated_at DESC LIMIT 50");
while ($row = $damaged->fetch_assoc()) {
    $addNotification('damaged', $row['id'] . ':' . $row['updated_at'], 'Damaged equipment', $row['item_name'] . ' (' . $row['asset_tag'] . ') is marked damaged.', $row['updated_at'], 'inventory.php', 'bi-exclamation-octagon');
}

$maintenance = $conn->query("SELECT i.id,i.asset_tag,i.item_name,i.updated_at FROM inventory_items i WHERE i.status<>'Disposed' AND i.condition_status<>'Damaged' AND (i.status='Under Repair' OR i.condition_status='Needs Repair') ORDER BY i.updated_at DESC LIMIT 50");
while ($row = $maintenance->fetch_assoc()) {
    $addNotification('maintenance', $row['id'] . ':' . $row['updated_at'], 'Equipment under maintenance', $row['item_name'] . ' (' . $row['asset_tag'] . ') needs repair attention.', $row['updated_at'], 'maintenance_records.php', 'bi-wrench-adjustable');
}

$completedRepairs = $conn->query("SELECT h.id,h.created_at,u.full_name,i.id AS inventory_item_id,i.item_name,i.asset_tag FROM maintenance_history h JOIN users u ON u.id=h.user_id JOIN inventory_items i ON i.id=h.inventory_item_id WHERE h.action='completed' AND h.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) ORDER BY h.created_at DESC LIMIT 30");
while ($row = $completedRepairs->fetch_assoc()) {
    $addNotification('repair_completed', (string) $row['id'], 'Repair completed', $row['full_name'] . ' completed a repair on ' . $row['item_name'] . ' (' . $row['asset_tag'] . ').', $row['created_at'], 'maintenance_history.php?id=' . (int) $row['inventory_item_id'], 'bi-check-circle');
}

$accountActivity = $conn->query("SELECT l.id,l.action,l.details,l.created_at,u.full_name FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.action IN ('CREATE USER','UPDATE USER','RESET USER PASSWORD','UPDATE ADMIN PROFILE','CHANGE ADMIN PASSWORD','LOGIN','LOGOUT') ORDER BY l.id DESC LIMIT 30");
while ($row = $accountActivity->fetch_assoc()) {
    $addNotification('account', (string) $row['id'], $row['action'], ($row['full_name'] ?: 'Account') . ' · ' . ($row['details'] ?: 'Account activity'), $row['created_at'], 'logs.php', 'bi-person-gear');
}

$knownKeys = array_column($notifications, 'notification_key');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['notification_action'] ?? '';
    $transactionOpen = false;
    try {
        if ($action === 'dismiss') {
            $notificationKey = $_POST['notification_key'] ?? '';
            if (!is_string($notificationKey) || !in_array($notificationKey, $knownKeys, true)) {
                throw new RuntimeException('This notification is no longer available.');
            }
            $dismiss = $conn->prepare('INSERT INTO notification_dismissals (user_id,notification_key) VALUES (?,?) ON DUPLICATE KEY UPDATE dismissed_at=CURRENT_TIMESTAMP');
            $dismiss->bind_param('is', $userId, $notificationKey);
            $dismiss->execute();
            flash('Notification cleared.');
        } elseif ($action === 'clear_all') {
            $conn->begin_transaction();
            $transactionOpen = true;
            $dismiss = $conn->prepare('INSERT INTO notification_dismissals (user_id,notification_key) VALUES (?,?) ON DUPLICATE KEY UPDATE dismissed_at=CURRENT_TIMESTAMP');
            foreach ($knownKeys as $notificationKey) {
                $dismiss->bind_param('is', $userId, $notificationKey);
                $dismiss->execute();
            }
            $clearMarker = '__all__';
            $dismiss->bind_param('is', $userId, $clearMarker);
            $dismiss->execute();
            $conn->commit();
            $transactionOpen = false;
            flash('All notifications cleared.');
        } else {
            throw new RuntimeException('Invalid notification action.');
        }
    } catch (Throwable $exception) {
        if ($transactionOpen) $conn->rollback();
        flash($exception instanceof RuntimeException ? $exception->getMessage() : 'Could not clear notifications.', 'error');
    }
    redirect('notifications.php' . (!empty($_GET['type']) ? '?type=' . rawurlencode((string) $_GET['type']) : ''));
}

$dismissedQuery = $conn->prepare('SELECT notification_key FROM notification_dismissals WHERE user_id=?');
$dismissedQuery->bind_param('i', $userId);
$dismissedQuery->execute();
$dismissedKeys = array_fill_keys(array_column($dismissedQuery->get_result()->fetch_all(MYSQLI_ASSOC), 'notification_key'), true);
$notifications = array_values(array_filter($notifications, static fn (array $notification): bool => !isset($dismissedKeys[$notification['notification_key']])));
if (!$notifications && $knownKeys && count(array_intersect($knownKeys, array_keys($dismissedKeys))) === count($knownKeys)) {
    $clearMarker = '__all__';
    $saveClearMarker = $conn->prepare('INSERT INTO notification_dismissals (user_id,notification_key) VALUES (?,?) ON DUPLICATE KEY UPDATE dismissed_at=CURRENT_TIMESTAMP');
    $saveClearMarker->bind_param('is', $userId, $clearMarker);
    $saveClearMarker->execute();
}
if ($notifications) {
    $clearMarkerDelete = $conn->prepare("DELETE FROM notification_dismissals WHERE user_id=? AND notification_key='__all__'");
    $clearMarkerDelete->bind_param('i', $userId);
    $clearMarkerDelete->execute();
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
        <div class="admin-panel-heading"><div><h2><?= $filter !== '' ? e(ucwords(str_replace('_', ' ', $filter))) : 'All notifications' ?></h2><p><?= number_format(count($notifications)) ?> events shown</p></div><div class="admin-notification-actions"><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="notification_action" value="clear_all"><button class="btn btn-sm btn-outline-danger" type="submit" <?= $notifications ? '' : 'disabled' ?>><i class="bi bi-trash3" aria-hidden="true"></i> Clear all</button></form><a class="btn btn-sm btn-outline-secondary" href="<?= e(BASE_URL) ?>/notifications.php">Clear filter</a></div></div>
        <div class="admin-notification-list">
            <?php if ($notifications): ?>
                <?php foreach ($notifications as $notification): ?>
                    <div class="admin-notification-row notification-<?= e($notification['type']) ?>">
                        <span class="admin-notification-icon"><i class="bi <?= e($notification['icon']) ?>" aria-hidden="true"></i></span>
                        <a class="admin-notification-open" href="<?= e(BASE_URL . '/' . ltrim($notification['url'], '/')) ?>"><span class="admin-notification-copy"><strong><?= e($notification['title']) ?></strong><small><?= e($notification['details']) ?></small></span><time datetime="<?= e(date(DATE_ATOM, strtotime($notification['created_at']))) ?>"><?= e(date('M j, Y g:i A', strtotime($notification['created_at']))) ?></time></a>
                        <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="notification_action" value="dismiss"><input type="hidden" name="notification_key" value="<?= e($notification['notification_key']) ?>"><button class="admin-notification-dismiss" type="submit" aria-label="Clear notification: <?= e($notification['title']) ?>" title="Clear notification"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="admin-empty-state"><i class="bi bi-check2-circle" aria-hidden="true"></i><span>No notifications match this filter.</span></div>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
