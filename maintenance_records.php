<?php
require_once __DIR__ . '/includes/functions.php';
require_role(['admin', 'maintenance']);
ensure_inventory_table($conn);

$search = trim($_GET['q'] ?? '');
$userRole = current_user()['role'];
$dateFilter = $_GET['date'] ?? 'all';
$dateOptions = ['all', 'today', '7days', '30days'];
if (!in_array($dateFilter, $dateOptions, true)) {
    $dateFilter = 'all';
}
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$validDate = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
};
$dateFrom = $validDate($dateFrom);
$dateTo = $validDate($dateTo);
$equipmentId = max(0, (int) ($_GET['equipment_id'] ?? 0));
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, ['Pending', 'In Progress', 'Completed', 'Cannot Be Repaired'], true)) {
    $statusFilter = '';
}

$conditions = [];
$queryTypes = '';
$queryValues = [];
if ($search !== '') {
    $conditions[] = '(u.full_name LIKE ? OR u.email LIKE ? OR i.asset_tag LIKE ? OR i.item_name LIKE ? OR h.work_description LIKE ? OR h.parts_replaced LIKE ? OR r.issue_description LIKE ?)';
    $searchPattern = '%' . $search . '%';
    $queryTypes .= 'sssssss';
    $queryValues = array_merge($queryValues, array_fill(0, 7, $searchPattern));
}
if ($equipmentId > 0) {
    $conditions[] = 'h.inventory_item_id = ?';
    $queryTypes .= 'i';
    $queryValues[] = $equipmentId;
}
if ($statusFilter !== '') {
    $conditions[] = "COALESCE(r.status, CASE h.action WHEN 'reported' THEN 'Pending' WHEN 'started' THEN 'In Progress' WHEN 'completed' THEN 'Completed' ELSE 'Cannot Be Repaired' END) = ?";
    $queryTypes .= 's';
    $queryValues[] = $statusFilter;
}
if ($dateFilter === 'today') {
    $conditions[] = 'h.created_at >= CURDATE()';
} elseif ($dateFilter === '7days') {
    $conditions[] = 'h.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
} elseif ($dateFilter === '30days') {
    $conditions[] = 'h.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
}
if ($dateFrom !== '') {
    $conditions[] = 'h.created_at >= ?';
    $queryTypes .= 's';
    $queryValues[] = $dateFrom;
}
if ($dateTo !== '') {
    $conditions[] = 'h.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
    $queryTypes .= 's';
    $queryValues[] = $dateTo;
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$stats = $conn->query(
    "SELECT COUNT(*) AS entries,
            COALESCE(SUM(action = 'completed'), 0) AS completed,
            COUNT(DISTINCT user_id) AS technicians
     FROM maintenance_history"
)->fetch_assoc();
$repairStats = $conn->query("SELECT
    COALESCE(SUM(status<>'Disposed' AND status<>'Under Repair' AND condition_status='Needs Repair'),0) AS pending_repairs,
    COALESCE(SUM(status<>'Disposed' AND status='Under Repair' AND condition_status<>'Damaged'),0) AS ongoing_repairs,
    COALESCE(SUM(status<>'Disposed' AND condition_status='Damaged'),0) AS damaged_equipment,
    (SELECT COUNT(*) FROM users WHERE role='maintenance' AND status='active') AS maintenance_accounts
    FROM inventory_items")->fetch_assoc();
$repairQueue = $conn->query("SELECT i.id,i.asset_tag,i.item_name,i.location,i.status,i.condition_status,i.updated_at,
    (SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=i.id ORDER BY h.id DESC LIMIT 1) AS last_technician,
    (SELECT h.action FROM maintenance_history h WHERE h.inventory_item_id=i.id ORDER BY h.id DESC LIMIT 1) AS last_action
    FROM inventory_items i
    WHERE i.status<>'Disposed' AND (i.status='Under Repair' OR i.condition_status IN ('Needs Repair','Damaged'))
    ORDER BY CASE WHEN i.status='Under Repair' THEN 0 WHEN i.condition_status='Damaged' THEN 1 ELSE 2 END,i.updated_at ASC
    LIMIT 100");

$countQuery = $conn->prepare(
    'SELECT COUNT(*)
     FROM maintenance_history h
     JOIN users u ON u.id = h.user_id
    JOIN inventory_items i ON i.id = h.inventory_item_id
    LEFT JOIN maintenance_requests r ON r.id = h.request_id' . $whereSql
);
if ($queryValues) {
    stmt_bind($countQuery, $queryTypes, $queryValues);
}
$countQuery->execute();
$totalRecords = (int) $countQuery->get_result()->fetch_row()[0];

$pageSize = 5;
$totalPages = max(1, (int) ceil($totalRecords / $pageSize));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $totalPages);
$offset = ($page - 1) * $pageSize;

$historyQuery = $conn->prepare(
        'SELECT h.inventory_item_id, h.request_id, h.action, h.work_description, h.parts_replaced, h.created_at,
            r.issue_description, r.status AS request_status,
            u.full_name, u.email, i.asset_tag, i.item_name, i.location
     FROM maintenance_history h
     JOIN users u ON u.id = h.user_id
         JOIN inventory_items i ON i.id = h.inventory_item_id
         LEFT JOIN maintenance_requests r ON r.id = h.request_id' . $whereSql . '
     ORDER BY h.id DESC
     LIMIT ? OFFSET ?'
);
$historyValues = array_merge($queryValues, [$pageSize, $offset]);
stmt_bind($historyQuery, $queryTypes . 'ii', $historyValues);
$historyQuery->execute();
$history = $historyQuery->get_result();
$equipmentOptions = $conn->query('SELECT id, asset_tag, item_name FROM inventory_items ORDER BY item_name, asset_tag');
$firstRecord = $totalRecords > 0 ? $offset + 1 : 0;
$lastRecord = min($offset + $pageSize, $totalRecords);
$paginationUrl = static function (int $targetPage) use ($search, $dateFilter, $dateFrom, $dateTo, $equipmentId, $statusFilter): string {
    $query = [
        'q' => $search,
        'date' => $dateFilter,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'equipment_id' => $equipmentId,
        'status' => $statusFilter,
        'page' => $targetPage,
    ];

    return '?' . http_build_query(array_filter($query, static fn ($value) => $value !== '' && $value !== 0));
};

include __DIR__ . '/includes/header.php';
?>
<div class="inventory-page maintenance-records-page">
    <header class="maintenance-records-hero">
        <div class="maintenance-records-hero-copy">
            <p class="maintenance-records-eyebrow">CCST FACILITIES · MAINTENANCE AUDIT</p>
            <h1>Maintenance Records</h1>
            <p>Track who handled each repair and what work or parts were recorded.</p>
        </div>
        <div class="maintenance-records-hero-art" aria-hidden="true">
            <i class="bi bi-wrench-adjustable"></i>
            <i class="bi bi-gear-wide-connected"></i>
        </div>
    </header>
    <div class="maintenance-records-print no-print"><button class="btn btn-outline-primary btn-sm" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print records</button></div>

    <section class="maintenance-records-metrics" aria-label="Maintenance summary">
        <article class="maintenance-records-metric metric-total">
            <span class="maintenance-records-metric-icon"><i class="bi bi-journal-check" aria-hidden="true"></i></span>
            <div><span>Total activity</span><strong><?= number_format((int) $stats['entries']) ?></strong><small>Recorded repair actions</small></div>
        </article>
        <article class="maintenance-records-metric metric-completed">
            <span class="maintenance-records-metric-icon"><i class="bi bi-check2" aria-hidden="true"></i></span>
            <div><span>Completed repairs</span><strong><?= number_format((int) $stats['completed']) ?></strong><small>Marked completed</small></div>
        </article>
        <article class="maintenance-records-metric metric-pending">
            <span class="maintenance-records-metric-icon"><i class="bi bi-clock-history" aria-hidden="true"></i></span>
            <div><span>Pending repairs</span><strong><?= number_format((int) $repairStats['pending_repairs']) ?></strong><small>Waiting for Maintenance</small></div>
        </article>
        <article class="maintenance-records-metric metric-ongoing">
            <span class="maintenance-records-metric-icon"><i class="bi bi-tools" aria-hidden="true"></i></span>
            <div><span>Ongoing repairs</span><strong><?= number_format((int) $repairStats['ongoing_repairs']) ?></strong><small>Marked under repair</small></div>
        </article>
        <article class="maintenance-records-metric metric-damaged">
            <span class="maintenance-records-metric-icon"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i></span>
            <div><span>Damaged equipment</span><strong><?= number_format((int) $repairStats['damaged_equipment']) ?></strong><small>Excluded from borrowing</small></div>
        </article>
        <?php if ($userRole === 'admin'): ?><article class="maintenance-records-metric metric-accounts">
            <span class="maintenance-records-metric-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
            <div><span>Maintenance accounts</span><strong><?= number_format((int) $repairStats['maintenance_accounts']) ?></strong><small>Active accounts</small></div>
        </article><?php endif; ?>
    </section>

    <section class="panel maintenance-records-table-panel">
        <div class="maintenance-records-table-heading">
            <div class="maintenance-records-table-title">
                <span><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span>
                <div><h2>Repair monitoring</h2><p><?= number_format($repairQueue->num_rows) ?> equipment items need attention</p></div>
            </div>
        </div>
        <div class="table-responsive maintenance-records-table-wrap">
            <table class="table align-middle maintenance-records-table">
                <thead><tr><th>Equipment</th><th>Location</th><th>Condition</th><th>Status</th><th>Maintenance staff</th><th>Last action</th><th>Updated</th><th></th></tr></thead>
                <tbody>
                    <?php if ($repairQueue->num_rows): ?>
                        <?php while ($item = $repairQueue->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= e($item['item_name']) ?></strong><small><?= e($item['asset_tag']) ?></small></td>
                                <td><?= e($item['location']) ?></td>
                                <td><?= e($item['condition_status']) ?></td>
                                <td><?= e($item['status']) ?></td>
                                <td><?= e($item['last_technician'] ?? 'Not started') ?></td>
                                <td><?= e($item['last_action'] ? ucfirst($item['last_action']) : 'Pending') ?></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($item['updated_at']))) ?></td>
                                <td><a class="maintenance-record-view" href="maintenance_history.php?id=<?= (int) $item['id'] ?>" aria-label="View repair history for <?= e($item['asset_tag']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> History</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="maintenance-records-empty"><strong>No equipment needs repair.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <form class="maintenance-records-filters" method="get" role="search">
        <div class="maintenance-filter-search">
            <label class="visually-hidden" for="maintenance-search">Search repair records</label>
            <i class="bi bi-search" aria-hidden="true"></i>
            <input id="maintenance-search" name="q" value="<?= e($search) ?>" placeholder="Search repair records...">
        </div>
        <label class="maintenance-filter-select">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <span class="visually-hidden">Date range</span>
            <select name="date" aria-label="Filter by date">
                <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All dates</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="7days" <?= $dateFilter === '7days' ? 'selected' : '' ?>>Last 7 days</option>
                <option value="30days" <?= $dateFilter === '30days' ? 'selected' : '' ?>>Last 30 days</option>
            </select>
        </label>
        <label class="maintenance-filter-select"><span class="visually-hidden">Start date</span><input class="form-control" type="date" name="date_from" value="<?= e($dateFrom) ?>" aria-label="Start date"></label>
        <label class="maintenance-filter-select"><span class="visually-hidden">End date</span><input class="form-control" type="date" name="date_to" value="<?= e($dateTo) ?>" aria-label="End date"></label>
        <label class="maintenance-filter-select maintenance-filter-equipment">
            <i class="bi bi-wrench-adjustable" aria-hidden="true"></i>
            <span class="visually-hidden">Filter by equipment</span>
            <select name="equipment_id" aria-label="Filter by equipment">
                <option value="0">All equipment</option>
                <?php while ($equipment = $equipmentOptions->fetch_assoc()): ?>
                    <option value="<?= (int) $equipment['id'] ?>" <?= $equipmentId === (int) $equipment['id'] ? 'selected' : '' ?>>
                        <?= e($equipment['item_name']) ?> · <?= e($equipment['asset_tag']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </label>
        <label class="maintenance-filter-select">
            <i class="bi bi-layers" aria-hidden="true"></i>
            <span class="visually-hidden">Filter by status</span>
            <select name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                <option value="In Progress" <?= $statusFilter === 'In Progress' ? 'selected' : '' ?>>In progress</option>
                <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                <option value="Cannot Be Repaired" <?= $statusFilter === 'Cannot Be Repaired' ? 'selected' : '' ?>>Cannot be repaired</option>
            </select>
        </label>
        <button class="maintenance-filter-submit" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
        <a class="maintenance-filter-reset" href="maintenance_records.php"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset</a>
    </form>

    <section class="panel maintenance-records-table-panel">
        <div class="maintenance-records-table-heading">
            <div class="maintenance-records-table-title">
                <span><i class="bi bi-clipboard2-pulse" aria-hidden="true"></i></span>
                <div>
                    <h2>Repair Activity</h2>
                    <p><?= number_format($totalRecords) ?> matching records</p>
                </div>
            </div>
            <span class="maintenance-records-count">Showing <?= number_format($firstRecord) ?>–<?= number_format($lastRecord) ?> of <?= number_format($totalRecords) ?></span>
        </div>

        <div class="table-responsive maintenance-records-table-wrap">
            <table class="table align-middle maintenance-records-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Date and time</th>
                        <th>Maintenance staff</th>
                        <th>Equipment</th>
                        <th>Reported issue</th>
                        <th>Action performed</th>
                        <th>Repair status</th>
                        <th>Parts replaced</th>
                        <th><span class="visually-hidden">Details</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $recordNumber = $offset; ?>
                    <?php if ($history->num_rows): ?>
                        <?php while ($entry = $history->fetch_assoc()): ?>
                            <?php $recordNumber++; ?>
                            <tr>
                                <td><?= $recordNumber ?></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($entry['created_at']))) ?></td>
                                <td>
                                    <strong><?= e($entry['full_name']) ?></strong>
                                    <small><?= e($entry['email']) ?></small>
                                </td>
                                <td><strong><?= e($entry['item_name']) ?></strong><small><?= e($entry['asset_tag']) ?></small></td>
                                <td class="text-break"><?= e($entry['issue_description'] ?: 'Legacy maintenance record') ?></td>
                                <td class="text-break"><?= e($entry['action'] === 'reported' ? 'Damage reported' : ($entry['work_description'] ?: '—')) ?></td>
                                <td>
                                    <?php $recordStatus = $entry['request_status'] ?? match ($entry['action']) {'completed' => 'Completed', 'started' => 'In Progress', 'reported' => 'Pending', default => 'Cannot Be Repaired'}; ?>
                                    <span class="maintenance-record-status <?= $recordStatus === 'Completed' ? 'status-completed' : ($recordStatus === 'Pending' ? 'status-pending' : ($recordStatus === 'Cannot Be Repaired' ? 'status-danger' : 'status-in-progress')) ?>">
                                        <?= e($recordStatus) ?>
                                    </span>
                                </td>
                                <td class="text-break"><?= e($entry['parts_replaced'] ?: '—') ?></td>
                                <td>
                                    <a class="maintenance-record-view" href="maintenance_history.php?id=<?= (int) $entry['inventory_item_id'] ?>" aria-label="View maintenance history for <?= e($entry['asset_tag']) ?>">
                                        <i class="bi bi-eye" aria-hidden="true"></i> View
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="maintenance-records-empty">
                                <i class="bi bi-clipboard2-x" aria-hidden="true"></i>
                                <strong>No maintenance records found</strong>
                                <span>Try changing or clearing the filters.</span>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <footer class="maintenance-records-pagination">
            <span>Showing <?= number_format($firstRecord) ?> to <?= number_format($lastRecord) ?> of <?= number_format($totalRecords) ?> records</span>
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Maintenance record pages">
                    <a href="<?= e($paginationUrl(max(1, $page - 1))) ?>" aria-label="Previous page" <?= $page === 1 ? 'aria-disabled="true"' : '' ?>>
                        <i class="bi bi-chevron-left" aria-hidden="true"></i>
                    </a>
                    <?php for ($pageNumber = max(1, $page - 2); $pageNumber <= min($totalPages, $page + 2); $pageNumber++): ?>
                        <a href="<?= e($paginationUrl($pageNumber)) ?>" class="<?= $pageNumber === $page ? 'active' : '' ?>" <?= $pageNumber === $page ? 'aria-current="page"' : '' ?>><?= $pageNumber ?></a>
                    <?php endfor; ?>
                    <a href="<?= e($paginationUrl(min($totalPages, $page + 1))) ?>" aria-label="Next page" <?= $page === $totalPages ? 'aria-disabled="true"' : '' ?>>
                        <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    </a>
                </nav>
            <?php endif; ?>
        </footer>
    </section>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>