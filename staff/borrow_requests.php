<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
ensure_staff_equipment_transactions_table($conn);

$search = trim($_GET['q'] ?? '');
$statusOptions = ['pending', 'approved', 'rejected', 'returned', 'cancelled'];
$statusFilter = $_GET['status'] ?? '';
if (!in_array($statusFilter, $statusOptions, true)) {
    $statusFilter = '';
}

$conditions = [];
$types = '';
$values = [];
if ($search !== '') {
    $conditions[] = '(borrower.full_name LIKE ? OR borrower.email LIKE ? OR i.item_name LIKE ? OR i.asset_tag LIKE ? OR r.purpose LIKE ?)';
    $searchPattern = '%' . $search . '%';
    $types .= 'sssss';
    $values = array_fill(0, 5, $searchPattern);
}
if ($statusFilter !== '') {
    $conditions[] = 'r.status = ?';
    $types .= 's';
    $values[] = $statusFilter;
}
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$query = $conn->prepare(
    'SELECT r.id, r.quantity, r.purpose, r.expected_return_date, r.status, r.review_note, r.created_at,
            borrower.full_name AS borrower_name, borrower.email AS borrower_email,
            reviewer.full_name AS reviewer_name, i.item_name, i.asset_tag, i.location,
            (SELECT t.created_at FROM equipment_transactions t WHERE t.request_id = r.id AND t.action = \'released\' LIMIT 1) AS released_at,
            (SELECT t.created_at FROM equipment_transactions t WHERE t.request_id = r.id AND t.action = \'returned\' LIMIT 1) AS returned_at
     FROM equipment_requests r
     JOIN users borrower ON borrower.id = r.requester_id
     JOIN inventory_items i ON i.id = r.inventory_item_id
     LEFT JOIN users reviewer ON reviewer.id = r.reviewed_by' . $whereSql . '
     ORDER BY FIELD(r.status, \'pending\', \'approved\', \'returned\', \'rejected\', \'cancelled\'), r.created_at DESC
     LIMIT 300'
);
if ($values) {
    stmt_bind($query, $types, $values);
}
$query->execute();
$requests = $query->get_result();
include __DIR__ . '/../includes/header.php';
?>
<div class="approval-page staff-borrow-requests-page">
    <header class="approval-page-heading">
        <div>
            <p class="dashboard-eyebrow">EQUIPMENT TRANSACTIONS</p>
            <h1>Borrow Requests</h1>
            <p class="dashboard-subtitle">Review request details and prepare Admin-approved equipment for release.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/requests.php">
            <i class="bi bi-plus-lg" aria-hidden="true"></i> New request
        </a>
    </header>

    <section class="panel approval-list-panel">
        <form class="staff-request-filters" method="get" role="search">
            <label class="staff-request-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="visually-hidden">Search requests</span>
                <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search borrower, equipment, tag, or purpose">
            </label>
            <label>
                <span class="visually-hidden">Filter by approval status</span>
                <select class="form-select" name="status" aria-label="Filter by approval status">
                    <option value="">All statuses</option>
                    <?php foreach ($statusOptions as $status): ?>
                        <option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
            <?php if ($search !== '' || $statusFilter !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/staff/borrow_requests.php" aria-label="Clear filters" title="Clear filters">
                    <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                </a>
            <?php endif; ?>
        </form>

        <div class="approval-table-heading">
            <h2>Requests</h2>
            <span><?= number_format($requests->num_rows) ?> shown, up to 300</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle approval-table">
                <thead>
                    <tr>
                        <th>Request ID</th>
                        <th>Borrower</th>
                        <th>Equipment</th>
                        <th>Qty</th>
                        <th>Request date</th>
                        <th>Expected return</th>
                        <th>Approval status</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($requests->num_rows): ?>
                        <?php while ($request = $requests->fetch_assoc()): ?>
                            <?php
                            $statusLabel = ucfirst($request['status']);
                            if ($request['status'] === 'returned' && $request['released_at'] && !$request['returned_at']) {
                                $statusLabel = 'Awaiting Staff Return';
                            }
                            ?>
                            <tr>
                                <td class="inventory-tag">BR-<?= str_pad((string) $request['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td>
                                    <strong><?= e($request['borrower_name']) ?></strong>
                                    <small class="d-block text-secondary"><?= e($request['borrower_email']) ?></small>
                                </td>
                                <td>
                                    <strong><?= e($request['item_name']) ?></strong>
                                    <small class="d-block text-secondary"><?= e($request['asset_tag']) ?> · <?= e($request['location']) ?></small>
                                </td>
                                <td><?= number_format((int) $request['quantity']) ?></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($request['created_at']))) ?></td>
                                <td><?= e(date('M j, Y', strtotime($request['expected_return_date']))) ?></td>
                                <td><span class="request-status status-<?= e($request['status']) ?>"><?= e($statusLabel) ?></span></td>
                                <td>
                                    <details class="staff-request-details">
                                        <summary class="btn btn-sm btn-outline-primary"><i class="bi bi-eye" aria-hidden="true"></i> View</summary>
                                        <div>
                                            <strong>Purpose</strong>
                                            <p><?= e($request['purpose']) ?></p>
                                            <strong>Admin review</strong>
                                            <p><?= e($request['review_note'] ?: 'No review note') ?></p>
                                            <?php if ($request['reviewer_name']): ?>
                                                <small>Reviewed by <?= e($request['reviewer_name']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($request['released_at']): ?>
                                                <small class="d-block">Released <?= e(date('M j, Y g:i A', strtotime($request['released_at']))) ?></small>
                                            <?php endif; ?>
                                            <?php if ($request['status'] === 'approved' && !$request['released_at']): ?>
                                                <a class="btn btn-sm btn-primary mt-2" href="<?= e(BASE_URL) ?>/staff/release_returns.php#release">Prepare release</a>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="8" class="text-center py-5"><strong>No borrow requests found.</strong></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>