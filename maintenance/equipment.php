<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);
$search = trim($_GET['q'] ?? '');
$filter = $_GET['status'] ?? '';
$statuses = ['Available', 'In Use', 'Under Maintenance', 'Damaged', 'Cannot Be Repaired'];
if (!in_array($filter, $statuses, true)) {
    $filter = '';
}
$sql = "SELECT inventory_status.* FROM (
    SELECT i.id,i.asset_tag,i.item_name,i.category,i.quantity,i.unit,i.location,i.condition_status,i.status AS inventory_status,
        CASE WHEN latest.status='Cannot Be Repaired' THEN 'Cannot Be Repaired'
             WHEN i.condition_status='Damaged' THEN 'Damaged'
             WHEN i.status='Under Repair' OR i.condition_status='Needs Repair' THEN 'Under Maintenance'
             WHEN i.status='In Use' THEN 'In Use' ELSE 'Available' END AS status_label
    FROM inventory_items i
    LEFT JOIN (SELECT r.inventory_item_id,r.status FROM maintenance_requests r JOIN
        (SELECT inventory_item_id,MAX(id) AS latest_id FROM maintenance_requests GROUP BY inventory_item_id) latest_request
        ON latest_request.latest_id=r.id) latest ON latest.inventory_item_id=i.id
    WHERE i.status<>'Disposed'
) inventory_status WHERE 1=1";
$types = '';
$values = [];
if ($search !== '') {
    $sql .= ' AND (item_name LIKE ? OR asset_tag LIKE ? OR category LIKE ? OR location LIKE ?)';
    $pattern = '%' . $search . '%';
    $types = 'ssss';
    $values = [$pattern, $pattern, $pattern, $pattern];
}
if ($filter !== '') {
    $sql .= ' AND status_label=?';
    $types .= 's';
    $values[] = $filter;
}
$sql .= " ORDER BY FIELD(status_label,'Under Maintenance','Damaged','Cannot Be Repaired','In Use','Available'),item_name,asset_tag LIMIT 150";
$query = $conn->prepare($sql);
if ($values) {
    stmt_bind($query, $types, $values);
}
$query->execute();
$items = $query->get_result();
include __DIR__ . '/../includes/header.php';
?>
<div class="maintenance-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">EQUIPMENT MONITORING</p><h1>Equipment status</h1><p class="dashboard-subtitle">Inspect current condition and open the repair history.</p></div><a class="btn btn-primary" href="<?= e(BASE_URL) ?>/maintenance/requests.php#report"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Report damage</a></header>
    <form class="maintenance-records-filters" method="get" role="search"><div class="maintenance-filter-search"><i class="bi bi-search" aria-hidden="true"></i><input name="q" value="<?= e($search) ?>" placeholder="Search name, code, category, or location" aria-label="Search equipment"></div><label class="maintenance-filter-select"><select name="status" aria-label="Filter by equipment status"><option value="">All statuses</option><?php foreach ($statuses as $status): ?><option value="<?= e($status) ?>" <?= $filter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button class="maintenance-filter-submit" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button><a class="maintenance-filter-reset" href="equipment.php">Reset</a></form>
    <section class="panel maintenance-request-list"><div class="table-responsive"><table class="table align-middle maintenance-records-table"><thead><tr><th>Equipment</th><th>Category</th><th>Location</th><th>Quantity</th><th>Condition</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if ($items->num_rows): while ($item = $items->fetch_assoc()): ?><tr><td><strong><?= e($item['item_name']) ?></strong><small><?= e($item['asset_tag']) ?></small></td><td><?= e($item['category']) ?></td><td><?= e($item['location']) ?></td><td><?= number_format((int) $item['quantity']) ?> <?= e($item['unit']) ?></td><td><?= e($item['condition_status']) ?></td><td><span class="maintenance-record-status <?= in_array($item['status_label'], ['Damaged', 'Cannot Be Repaired'], true) ? 'status-danger' : ($item['status_label'] === 'Available' ? 'status-completed' : 'status-in-progress') ?>"><?= e($item['status_label']) ?></span></td><td><a class="maintenance-record-view" href="<?= e(BASE_URL) ?>/scan.php?tag=<?= e(rawurlencode($item['asset_tag'])) ?>"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> Inspect</a> <a class="maintenance-record-view" href="<?= e(BASE_URL) ?>/maintenance_history.php?id=<?= (int) $item['id'] ?>"><i class="bi bi-clock-history" aria-hidden="true"></i> History</a></td></tr><?php endwhile; else: ?><tr><td colspan="7" class="maintenance-records-empty">No equipment matched these filters.</td></tr><?php endif; ?>
    </tbody></table></div></section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
