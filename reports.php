<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'staff']);

$statuses = ['Available', 'In Use', 'Under Repair', 'Disposed'];
$parseDate = static function (string $value): ?string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    return $date && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $date->format('Y-m-d') === $value ? $value : null;
};
$from = $parseDate(trim($_GET['from'] ?? '')) ?? date('Y-m-01');
$to = $parseDate(trim($_GET['to'] ?? '')) ?? date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$periodStart = $from . ' 00:00:00';
$periodEnd = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

$categoryRows = $conn->query('SELECT DISTINCT category FROM inventory_items ORDER BY category');
$categories = [];
while ($categoryRow = $categoryRows->fetch_assoc()) {
    $categories[] = $categoryRow['category'];
}
$categoryFilter = in_array($_GET['category'] ?? '', $categories, true) ? $_GET['category'] : '';
$statusFilter = in_array($_GET['status'] ?? '', $statuses, true) ? $_GET['status'] : '';

$inventoryConditions = ['i.updated_at >= ?', 'i.updated_at < ?'];
$inventoryParams = [$periodStart, $periodEnd];
$inventoryTypes = 'ss';
if ($categoryFilter !== '') {
    $inventoryConditions[] = 'i.category = ?';
    $inventoryParams[] = $categoryFilter;
    $inventoryTypes .= 's';
}
if ($statusFilter !== '') {
    $inventoryConditions[] = 'i.status = ?';
    $inventoryParams[] = $statusFilter;
    $inventoryTypes .= 's';
}
$inventoryWhere = implode(' AND ', $inventoryConditions);
$inventorySelect = "SELECT i.id,i.asset_tag,i.item_name,i.category,i.quantity,i.unit,i.location,i.condition_status,i.status,i.updated_at,
    CASE WHEN i.status = 'Available' AND i.condition_status NOT IN ('Needs Repair','Damaged')
        THEN i.quantity ELSE 0 END AS available_units,
    (SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id = h.user_id
        WHERE h.inventory_item_id = i.id AND h.action = 'completed' ORDER BY h.id DESC LIMIT 1) AS last_repaired_by
    FROM inventory_items i WHERE $inventoryWhere";
$inventoryQuery = $conn->prepare($inventorySelect . ' ORDER BY i.updated_at DESC,i.id DESC LIMIT 500');
stmt_bind($inventoryQuery, $inventoryTypes, $inventoryParams);
$inventoryQuery->execute();
$inventoryRows = $inventoryQuery->get_result();

$summaryQuery = $conn->prepare("SELECT COUNT(i.id) AS records,COALESCE(SUM(i.quantity),0) AS total_units,
    COALESCE(SUM(CASE WHEN i.status = 'Available' AND i.condition_status NOT IN ('Needs Repair','Damaged')
        THEN i.quantity ELSE 0 END),0) AS available_units,
    COALESCE(SUM(CASE WHEN i.status='In Use' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN i.quantity ELSE 0 END),0) AS in_use_units,
    COALESCE(SUM(CASE WHEN i.status <> 'Disposed' AND i.condition_status = 'Damaged'
        THEN i.quantity ELSE 0 END),0) AS damaged_units,
    COALESCE(SUM(CASE WHEN i.status <> 'Disposed' AND i.condition_status <> 'Damaged'
        AND (i.status = 'Under Repair' OR i.condition_status = 'Needs Repair')
        THEN i.quantity ELSE 0 END),0) AS maintenance_units
    FROM inventory_items i WHERE $inventoryWhere");
stmt_bind($summaryQuery, $inventoryTypes, $inventoryParams);
$summaryQuery->execute();
$summary = $summaryQuery->get_result()->fetch_assoc();
$totalAssetUnits = (int) $summary['total_units'];
$availableAssetUnits = min($totalAssetUnits, (int) $summary['available_units']);
$otherAssetUnits = max($totalAssetUnits - $availableAssetUnits, 0);
$availablePercentage = $totalAssetUnits > 0 ? round($availableAssetUnits / $totalAssetUnits * 100, 1) : 0;
$otherPercentage = $totalAssetUnits > 0 ? round($otherAssetUnits / $totalAssetUnits * 100, 1) : 0;

$statusQuery = $conn->prepare("SELECT CASE
        WHEN i.status='Disposed' THEN 'Disposed'
        WHEN i.status='Under Repair' OR i.condition_status IN ('Needs Repair','Damaged') THEN 'Under Maintenance'
        WHEN i.status='In Use' THEN 'In Use'
        ELSE 'Available'
    END AS asset_state,COALESCE(SUM(i.quantity),0) AS units
    FROM inventory_items i WHERE $inventoryWhere GROUP BY asset_state");
stmt_bind($statusQuery, $inventoryTypes, $inventoryParams);
$statusQuery->execute();
$statusCounts = ['Available' => 0, 'In Use' => 0, 'Under Maintenance' => 0, 'Disposed' => 0];
$statusRows = $statusQuery->get_result();
while ($statusRow = $statusRows->fetch_assoc()) {
    $statusCounts[$statusRow['asset_state']] = (int) $statusRow['units'];
}

$roomQuery = $conn->prepare("SELECT i.location,SUM(i.quantity) AS total_equipment
    FROM inventory_items i WHERE $inventoryWhere
    GROUP BY i.location ORDER BY total_equipment DESC,i.location ASC LIMIT 8");
stmt_bind($roomQuery, $inventoryTypes, $inventoryParams);
$roomQuery->execute();
$roomRows = $roomQuery->get_result();
$parseRoomLocation = static function (string $location): array {
    $building = '—';
    $floor = '—';
    if (preg_match('/\bBuilding\s*[:#-]?\s*([^,;|]+)/i', $location, $match)) {
        $building = trim(preg_replace('/\s*[-–]\s*Floor\b.*$/i', '', $match[1]));
    }
    if (preg_match('/\bFloor\s*[:#-]?\s*([A-Za-z0-9-]+)/i', $location, $match)) {
        $floor = trim($match[1]);
    } elseif (preg_match('/\b((?:ground|first|second|third|\d+(?:st|nd|rd|th))\s+floor)\b/i', $location, $match)) {
        $floor = trim($match[1]);
    }
    return [$building !== '' ? $building : '—', $floor !== '' ? $floor : '—'];
};

$recentActivities = [];
$recentLogConditions = ['l.created_at >= ?', 'l.created_at < ?', "l.action IN ('ADD INVENTORY ITEM','UPDATE INVENTORY ITEM')"];
$recentLogParams = [$periodStart, $periodEnd];
$recentLogTypes = 'ss';
if ($categoryFilter !== '' || $statusFilter !== '') {
    $itemConditions = ['filter_item.item_name = l.details'];
    if ($categoryFilter !== '') {
        $itemConditions[] = 'filter_item.category = ?';
        $recentLogParams[] = $categoryFilter;
        $recentLogTypes .= 's';
    }
    if ($statusFilter !== '') {
        $itemConditions[] = 'filter_item.status = ?';
        $recentLogParams[] = $statusFilter;
        $recentLogTypes .= 's';
    }
    $recentLogConditions[] = 'EXISTS (SELECT 1 FROM inventory_items filter_item WHERE ' . implode(' AND ', $itemConditions) . ')';
}
$recentLogQuery = $conn->prepare("SELECT l.action,l.details AS equipment,u.full_name AS user_name,l.created_at
    FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
    WHERE " . implode(' AND ', $recentLogConditions) . "
    ORDER BY l.created_at DESC LIMIT 100");
stmt_bind($recentLogQuery, $recentLogTypes, $recentLogParams);
$recentLogQuery->execute();
$recentLogRows = $recentLogQuery->get_result();
while ($activity = $recentLogRows->fetch_assoc()) {
    $activity['action'] = $activity['action'] === 'ADD INVENTORY ITEM' ? 'Added' : 'Updated';
    $activity['equipment'] = $activity['equipment'] ?: '—';
    $activity['user_name'] = $activity['user_name'] ?? 'Deleted user';
    $recentActivities[] = $activity;
}

$maintenanceConditions = ['h.created_at >= ?', 'h.created_at < ?'];
$maintenanceParams = [$periodStart, $periodEnd];
$maintenanceTypes = 'ss';
if ($categoryFilter !== '') {
    $maintenanceConditions[] = 'i.category = ?';
    $maintenanceParams[] = $categoryFilter;
    $maintenanceTypes .= 's';
}
if ($statusFilter !== '') {
    $maintenanceConditions[] = 'i.status = ?';
    $maintenanceParams[] = $statusFilter;
    $maintenanceTypes .= 's';
}
$maintenanceQuery = $conn->prepare("SELECT h.action,i.item_name AS equipment,u.full_name AS user_name,h.created_at
    FROM maintenance_history h JOIN inventory_items i ON i.id = h.inventory_item_id
    JOIN users u ON u.id = h.user_id
    WHERE " . implode(' AND ', $maintenanceConditions) . "
    ORDER BY h.created_at DESC LIMIT 100");
stmt_bind($maintenanceQuery, $maintenanceTypes, $maintenanceParams);
$maintenanceQuery->execute();
$maintenanceRows = $maintenanceQuery->get_result();
$maintenanceLabels = [
    'started' => 'Maintenance started',
    'completed' => 'Maintenance completed',
    'reported' => 'Damage reported',
    'cannot_be_repaired' => 'Marked unrepairable',
];
while ($activity = $maintenanceRows->fetch_assoc()) {
    $activity['action'] = $maintenanceLabels[$activity['action']] ?? 'Maintenance';
    $recentActivities[] = $activity;
}

$damageConditions = ['t.created_at >= ?', 't.created_at < ?', "t.action = 'damaged_reported'"];
$damageParams = [$periodStart, $periodEnd];
$damageTypes = 'ss';
if ($categoryFilter !== '') {
    $damageConditions[] = 'i.category = ?';
    $damageParams[] = $categoryFilter;
    $damageTypes .= 's';
}
if ($statusFilter !== '') {
    $damageConditions[] = 'i.status = ?';
    $damageParams[] = $statusFilter;
    $damageTypes .= 's';
}
$damageQuery = $conn->prepare("SELECT 'Damage reported' AS action,
    CONCAT(i.item_name,' (',i.asset_tag,')') AS equipment,u.full_name AS user_name,t.created_at
    FROM equipment_transactions t JOIN inventory_items i ON i.id = t.inventory_item_id
    JOIN users u ON u.id = t.user_id
    WHERE " . implode(' AND ', $damageConditions) . "
    ORDER BY t.created_at DESC,t.id DESC LIMIT 100");
stmt_bind($damageQuery, $damageTypes, $damageParams);
$damageQuery->execute();
$damageRows = $damageQuery->get_result();
while ($activity = $damageRows->fetch_assoc()) {
    $recentActivities[] = $activity;
}
usort($recentActivities, static function (array $left, array $right): int {
    return strcmp($right['created_at'], $left['created_at']);
});
$recentActivities = array_slice($recentActivities, 0, 10);

$activityQuery = $conn->prepare('SELECT l.action,l.details,l.created_at,u.full_name,u.role
    FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
    WHERE l.created_at >= ? AND l.created_at < ? ORDER BY l.id DESC LIMIT 300');
$activityQuery->bind_param('ss', $periodStart, $periodEnd);
$activityQuery->execute();
$activityRows = $activityQuery->get_result();

$export = $_GET['export'] ?? '';
$csvCell = static function ($value): string {
    $value = (string) $value;
    return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
};
if ($export === 'inventory') {
    $filename = 'ccst-inventory-report.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fwrite($output, "\xEF\xBB\xBF");
    if ($export === 'inventory') {
        fputcsv($output, ['Asset Tag', 'Equipment', 'Category', 'Quantity', 'Available Units', 'Location', 'Condition', 'Status', 'Last Repaired By', 'Last Updated']);
        while ($row = $inventoryRows->fetch_assoc()) {
            fputcsv($output, array_map($csvCell, [$row['asset_tag'], $row['item_name'], $row['category'], $row['quantity'] . ' ' . $row['unit'], $row['available_units'], $row['location'], $row['condition_status'], $row['status'], $row['last_repaired_by'] ?? '', $row['updated_at']]));
        }
    }
    fclose($output);
    exit;
}

$inventoryExportUrl = 'reports.php?' . http_build_query([
    'from' => $from,
    'to' => $to,
    'category' => $categoryFilter,
    'status' => $statusFilter,
    'export' => 'inventory',
]);
$mainExportUrl = $inventoryExportUrl;
include __DIR__ . '/includes/header.php';
?>
<div class="reports-page">
    <header class="reports-page-heading">
        <div>
            <p class="dashboard-eyebrow">CCST FACILITIES</p>
            <h1>Reports &amp; Analytics</h1>
            <p class="dashboard-subtitle">View insights and generate reports for your inventory.</p>
        </div>
        <div class="reports-heading-actions">
            <button class="btn btn-outline-secondary no-print" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print Report</button>
            <a class="btn btn-primary no-print" href="<?= e($mainExportUrl) ?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a>
        </div>
    </header>

    <section class="panel reports-filter-panel no-print" aria-label="Report filters">
        <form class="reports-filter-form" method="get">
            <label><span>Date from</span><input class="form-control" type="date" name="from" value="<?= e($from) ?>" required></label>
            <label><span>Date to</span><input class="form-control" type="date" name="to" value="<?= e($to) ?>" required></label>
            <label><span>Category</span><select class="form-select" name="category"><option value="">All categories</option><?php foreach ($categories as $category): ?><option value="<?= e($category) ?>" <?= $categoryFilter === $category ? 'selected' : '' ?>><?= e($category) ?></option><?php endforeach; ?></select></label>
            <label><span>Asset status</span><select class="form-select" name="status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply Filters</button>
            <a class="btn btn-outline-secondary" href="reports.php">Reset Filters</a>
        </form>
    </section>

    <section class="reports-metric-grid" aria-label="Inventory summary">
        <article><span>Total Assets</span><strong><?= number_format($totalAssetUnits) ?></strong><small><?= number_format((int) $summary['records']) ?> asset records in this period</small></article>
        <article><span>Available</span><strong><?= number_format($availableAssetUnits) ?></strong><small>Ready to use</small></article>
        <article><span>In Use</span><strong><?= number_format((int) $summary['in_use_units']) ?></strong><small>Based on the saved equipment status</small></article>
        <article><span>Under Maintenance</span><strong><?= number_format((int) $summary['maintenance_units']) ?></strong><small>Being repaired or serviced</small></article>
    </section>

    <section class="reports-overview-grid">
        <article class="panel reports-overview-card">
            <div class="report-section-heading"><div><h2>Inventory Overview</h2><p>Available equipment compared with all other inventory.</p></div></div>
            <div class="reports-inventory-overview">
                <div class="reports-doughnut-wrap">
                    <canvas id="inventory-overview-chart" aria-label="Available and other inventory units"></canvas>
                    <div class="reports-doughnut-center" aria-hidden="true"><strong><?= number_format($totalAssetUnits) ?></strong><span>Total units</span></div>
                </div>
                <div class="reports-chart-legend">
                    <div><span><i class="legend-dot legend-available" aria-hidden="true"></i> Available</span><strong><?= number_format($availableAssetUnits) ?></strong><small><?= number_format($availablePercentage, 1) ?>%</small></div>
                    <div><span><i class="legend-dot legend-other" aria-hidden="true"></i> Others</span><strong><?= number_format($otherAssetUnits) ?></strong><small><?= number_format($otherPercentage, 1) ?>%</small></div>
                </div>
            </div>
        </article>
        <article class="panel reports-overview-card">
            <div class="report-section-heading"><div><h2>Inventory by Status</h2><p>Equipment units grouped by their current inventory status.</p></div></div>
            <div class="reports-line-chart"><canvas id="inventory-status-chart" aria-label="Equipment units by inventory status"></canvas></div>
            <?php if ($totalAssetUnits===0): ?><p class="reports-chart-empty">No equipment matches these filters.</p><?php endif; ?>
        </article>
    </section>

    <section class="reports-overview-grid reports-tables-grid">
        <article class="panel reports-overview-card">
            <div class="report-section-heading"><div><h2>Top Rooms by Equipment</h2><p>Locations ranked by total equipment units.</p></div></div>
            <div class="table-responsive">
                <table class="table align-middle reports-summary-table">
                    <thead><tr><th>Room</th><th>Building</th><th>Floor</th><th>Total Equipment</th></tr></thead>
                    <tbody>
                    <?php if ($roomRows->num_rows): while ($room = $roomRows->fetch_assoc()): [$building, $floor] = $parseRoomLocation((string) $room['location']); ?>
                        <tr><td><?= e($room['location']) ?></td><td><?= e($building) ?></td><td><?= e($floor) ?></td><td><?= number_format((int) $room['total_equipment']) ?></td></tr>
                    <?php endwhile; else: ?><tr><td colspan="4" class="reports-table-empty">No rooms match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="reports-data-note">Building and floor are shown when included in the saved location; the database does not store them separately.</p>
        </article>
        <article class="panel reports-overview-card">
            <div class="report-section-heading"><div><h2>Recent Activities</h2><p>Latest inventory, maintenance, and damage reports.</p></div></div>
            <div class="table-responsive">
                <table class="table align-middle reports-summary-table">
                    <thead><tr><th>Action</th><th>Equipment</th><th>User</th><th>Date &amp; Time</th></tr></thead>
                    <tbody>
                    <?php if ($recentActivities): foreach ($recentActivities as $activity): ?>
                        <tr><td><?= e($activity['action']) ?></td><td><?= e($activity['equipment'] ?: '—') ?></td><td><?= e($activity['user_name'] ?: 'Deleted user') ?></td><td><?= e(date('M j, Y g:i A', strtotime($activity['created_at']))) ?></td></tr>
                    <?php endforeach; else: ?><tr><td colspan="4" class="reports-table-empty">No activities match these filters.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </article>
    </section>

    <details class="reports-details-panel">
        <summary>Detailed report records <span>Filtered data tables and CSV exports</span></summary>
        <div class="reports-details-content">
            <section class="panel reports-table-panel">
                <div class="report-section-heading"><div><h2>Equipment detail</h2><p><?= number_format($inventoryRows->num_rows) ?> records shown, up to 500</p></div><a class="btn btn-sm btn-outline-primary no-print" href="<?= e($inventoryExportUrl) ?>"><i class="bi bi-download" aria-hidden="true"></i> Export inventory</a></div>
                <div class="table-responsive"><table class="table table-hover align-middle reports-table"><thead><tr><th>Asset tag</th><th>Equipment</th><th>Category</th><th>Quantity</th><th>Available</th><th>Location</th><th>Condition</th><th>Status</th><th>Last repaired by</th><th>Updated</th></tr></thead><tbody>
                    <?php if ($inventoryRows->num_rows): while ($row = $inventoryRows->fetch_assoc()): ?><tr><td class="inventory-tag"><?= e($row['asset_tag']) ?></td><td><strong><?= e($row['item_name']) ?></strong></td><td><?= e($row['category']) ?></td><td><?= number_format((int) $row['quantity']) ?> <?= e($row['unit']) ?></td><td><?= number_format((int) $row['available_units']) ?></td><td><?= e($row['location']) ?></td><td><?= e($row['condition_status']) ?></td><td><?= e($row['status']) ?></td><td><?= e($row['last_repaired_by'] ?? '—') ?></td><td><?= e(date('M j, Y', strtotime($row['updated_at']))) ?></td></tr><?php endwhile; else: ?><tr><td colspan="10" class="reports-table-empty">No equipment found for this report.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <section class="panel reports-table-panel">
                <div class="report-section-heading"><div><h2>User activity</h2><p><?= number_format($activityRows->num_rows) ?> events shown, up to 300</p></div></div>
                <div class="table-responsive"><table class="table table-hover align-middle reports-table"><thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Description</th></tr></thead><tbody>
                    <?php if ($activityRows->num_rows): while ($row = $activityRows->fetch_assoc()): ?><tr><td><?= e(date('M j, Y g:i A', strtotime($row['created_at']))) ?></td><td><?= e($row['full_name'] ?? 'Deleted user') ?></td><td><?= e(ucfirst($row['role'] ?? 'Unknown')) ?></td><td><?= e($row['action']) ?></td><td class="text-break"><?= e($row['details'] ?: '—') ?></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="reports-table-empty">No user activity found for this period.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>
    </details>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('inventory-overview-chart'), {
    type: 'doughnut',
    data: {
        labels: ['Available', 'Others'],
        datasets: [{
            data: [<?= json_encode($availableAssetUnits) ?>, <?= json_encode($otherAssetUnits) ?>],
            backgroundColor: ['#008F87', '#DCE3EA'],
            borderWidth: 0,
            hoverOffset: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: { legend: { display: false } }
    }
});
new Chart(document.getElementById('inventory-status-chart'), {
    type: 'doughnut',
    data: {
        labels: <?= json_encode(array_keys($statusCounts), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
        datasets: [
            { data: <?= json_encode(array_values($statusCounts)) ?>, backgroundColor: ['#008F87', '#0B2D50', '#D6A64F', '#DCE3EA'], borderWidth: 0 }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 7, padding: 16 } } }
    }
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
