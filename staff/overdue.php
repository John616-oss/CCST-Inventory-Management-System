<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
ensure_staff_equipment_transactions_table($conn);

$search = trim($_GET['q'] ?? '');
$whereSql = '';
$searchPattern = '%' . $search . '%';
if ($search !== '') {
    $whereSql = ' AND (borrower.full_name LIKE ? OR i.item_name LIKE ? OR i.asset_tag LIKE ?)';
}

$query = $conn->prepare(
    "SELECT r.id, r.expected_return_date, borrower.full_name AS borrower_name,
            i.item_name, i.asset_tag, released.quantity AS released_quantity,
            released.created_at AS borrowed_at,
            DATEDIFF(CURDATE(), r.expected_return_date) AS overdue_days,
            releaser.full_name AS released_by
     FROM equipment_transactions released
     JOIN equipment_requests r ON r.id = released.request_id
     JOIN users borrower ON borrower.id = r.requester_id
     JOIN users releaser ON releaser.id = released.user_id
     JOIN inventory_items i ON i.id = released.inventory_item_id
     WHERE released.action = 'released'
       AND r.expected_return_date < CURDATE()
       AND r.status IN ('approved', 'returned')
       AND NOT EXISTS (
           SELECT 1 FROM equipment_transactions returned
           WHERE returned.request_id = r.id AND returned.action = 'returned'
       )" . $whereSql . "
     ORDER BY r.expected_return_date ASC, released.created_at ASC
     LIMIT 300"
    );
if ($search !== '') {
    $query->bind_param('sss', $searchPattern, $searchPattern, $searchPattern);
}
$query->execute();
$overdueItems = $query->get_result();
include __DIR__ . '/../includes/header.php';
?>
<div class="approval-page staff-overdue-page">
    <header class="approval-page-heading">
        <div>
            <p class="dashboard-eyebrow">EQUIPMENT FOLLOW-UP</p>
            <h1>Overdue Items</h1>
            <p class="dashboard-subtitle">Track released equipment that has not been checked back in by its expected return date.</p>
        </div>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/staff/release_returns.php#return">
            <i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> Process return
        </a>
    </header>

    <section class="panel approval-list-panel">
        <form class="staff-request-filters" method="get" role="search">
            <label class="staff-request-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span class="visually-hidden">Search overdue equipment</span>
                <input class="form-control" name="q" value="<?= e($search) ?>" placeholder="Search borrower, equipment, or tag">
            </label>
            <button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Search</button>
            <?php if ($search !== ''): ?>
                <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/staff/overdue.php" aria-label="Clear search"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a>
            <?php endif; ?>
        </form>

        <div class="approval-table-heading">
            <h2>Overdue returns</h2>
            <span><?= number_format($overdueItems->num_rows) ?> shown, up to 300</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle approval-table">
                <thead>
                    <tr>
                        <th>Borrower</th>
                        <th>Equipment</th>
                        <th>Borrow date</th>
                        <th>Expected return</th>
                        <th>Overdue days</th>
                        <th>Borrowing status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($overdueItems->num_rows): ?>
                        <?php while ($item = $overdueItems->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= e($item['borrower_name']) ?></strong></td>
                                <td><strong><?= e($item['item_name']) ?></strong><small class="d-block text-secondary"><?= e($item['asset_tag']) ?> · <?= number_format((int) $item['released_quantity']) ?> unit(s)</small></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($item['borrowed_at']))) ?></td>
                                <td><?= e(date('M j, Y', strtotime($item['expected_return_date']))) ?></td>
                                <td><span class="staff-overdue-days"><?= number_format((int) $item['overdue_days']) ?> days</span></td>
                                <td><span class="request-status status-approved">Overdue</span></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/staff/release_returns.php#return">Process return</a></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center py-5"><strong>No overdue equipment found.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>