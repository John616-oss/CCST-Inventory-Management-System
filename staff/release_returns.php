<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
ensure_staff_equipment_transactions_table($conn);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['transaction_action'] ?? '';
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $scannedTag = trim($_POST['asset_tag'] ?? '');

    if (!in_array($action, ['release', 'return'], true) || $requestId < 1 || $quantity === false || $quantity < 1 || $scannedTag === '') {
        flash('Enter a valid request, quantity, and scanned equipment tag.', 'error');
        redirect('staff/release_returns.php' . ($action === 'return' ? '#return' : '#release'));
    }

    try {
        $conn->begin_transaction();
        $requestQuery = $conn->prepare('SELECT id, requester_id, inventory_item_id, quantity, status FROM equipment_requests WHERE id = ? FOR UPDATE');
        $requestQuery->bind_param('i', $requestId);
        $requestQuery->execute();
        $request = $requestQuery->get_result()->fetch_assoc();
        if (!$request) {
            throw new DomainException('Borrow request was not found.');
        }

        $itemQuery = $conn->prepare('SELECT id, asset_tag, item_name, quantity, status, condition_status FROM inventory_items WHERE id = ? FOR UPDATE');
        $itemQuery->bind_param('i', $request['inventory_item_id']);
        $itemQuery->execute();
        $item = $itemQuery->get_result()->fetch_assoc();
        if (!$item || !hash_equals($item['asset_tag'], $scannedTag)) {
            throw new DomainException('The scanned QR code does not match the requested equipment.');
        }

        if ($action === 'release') {
            if ($request['status'] !== 'approved') {
                throw new DomainException('Only Admin-approved requests can be released.');
            }
            if ($quantity !== (int) $request['quantity']) {
                throw new DomainException('Release the full quantity approved for this request.');
            }
            if ($item['status'] !== 'Available' || in_array($item['condition_status'], ['Needs Repair', 'Damaged'], true)) {
                throw new DomainException('This equipment is unavailable due to its current status or condition.');
            }

            $releaseExists = $conn->prepare("SELECT id FROM equipment_transactions WHERE request_id = ? AND action = 'released' LIMIT 1");
            $releaseExists->bind_param('i', $requestId);
            $releaseExists->execute();
            if ($releaseExists->get_result()->num_rows) {
                throw new DomainException('This request has already been released.');
            }

            $borrowedQuery = $conn->prepare("SELECT COALESCE(SUM(CASE action WHEN 'released' THEN CAST(quantity AS SIGNED) WHEN 'returned' THEN -CAST(quantity AS SIGNED) ELSE 0 END), 0) AS borrowed FROM equipment_transactions WHERE inventory_item_id = ? AND action IN ('released', 'returned')");
            $borrowedQuery->bind_param('i', $item['id']);
            $borrowedQuery->execute();
            $borrowed = max(0, (int) $borrowedQuery->get_result()->fetch_assoc()['borrowed']);
            $available = max(0, (int) $item['quantity'] - $borrowed);
            if ($quantity > $available) {
                throw new DomainException('Only ' . $available . ' unit(s) are currently available to release.');
            }

            $insert = $conn->prepare("INSERT INTO equipment_transactions(request_id, inventory_item_id, user_id, action, quantity) VALUES(?, ?, ?, 'released', ?)");
            $insert->bind_param('iiii', $requestId, $item['id'], $user['id'], $quantity);
            $insert->execute();
            log_action('RELEASE EQUIPMENT', 'BR-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT) . ' · ' . $item['asset_tag'] . ' · ' . $quantity . ' unit(s)');
            $conn->commit();
            flash('Equipment release recorded.');
        } else {
            if (!in_array($request['status'], ['approved', 'returned'], true)) {
                throw new DomainException('Only approved requests with a recorded release can be returned.');
            }

            $releaseQuery = $conn->prepare("SELECT quantity FROM equipment_transactions WHERE request_id = ? AND action = 'released' FOR UPDATE");
            $releaseQuery->bind_param('i', $requestId);
            $releaseQuery->execute();
            $release = $releaseQuery->get_result()->fetch_assoc();
            if (!$release) {
                throw new DomainException('No equipment release is recorded for this request.');
            }

            $returnExists = $conn->prepare("SELECT id FROM equipment_transactions WHERE request_id = ? AND action = 'returned' LIMIT 1");
            $returnExists->bind_param('i', $requestId);
            $returnExists->execute();
            if ($returnExists->get_result()->num_rows) {
                throw new DomainException('This request has already been returned.');
            }

            if ($quantity !== (int) $release['quantity']) {
                throw new DomainException('Record the full released quantity in one return.');
            }
            $condition = $_POST['condition_status'] ?? '';
            if (!in_array($condition, ['Good', 'Needs Repair', 'Damaged'], true)) {
                throw new DomainException('Select a valid return condition.');
            }

            $insert = $conn->prepare("INSERT INTO equipment_transactions(request_id, inventory_item_id, user_id, action, quantity, condition_status) VALUES(?, ?, ?, 'returned', ?, ?)");
            $insert->bind_param('iiiis', $requestId, $item['id'], $user['id'], $quantity, $condition);
            $insert->execute();

            if ($condition !== 'Good') {
                $updateItem = $conn->prepare("UPDATE inventory_items SET condition_status = ?, status = 'Under Repair' WHERE id = ?");
                $updateItem->bind_param('si', $condition, $item['id']);
                $updateItem->execute();
            } else {
                $outstandingQuery = $conn->prepare("SELECT COALESCE(SUM(CASE action WHEN 'released' THEN CAST(quantity AS SIGNED) WHEN 'returned' THEN -CAST(quantity AS SIGNED) ELSE 0 END), 0) AS borrowed FROM equipment_transactions WHERE inventory_item_id = ? AND action IN ('released', 'returned')");
                $outstandingQuery->bind_param('i', $item['id']);
                $outstandingQuery->execute();
                $outstanding = max(0, (int) $outstandingQuery->get_result()->fetch_assoc()['borrowed']);
                if ($outstanding === 0 && $item['status'] === 'In Use') {
                    $openMaintenance = $conn->prepare("SELECT id FROM maintenance_requests WHERE inventory_item_id=? AND status IN ('Pending','In Progress','Cannot Be Repaired') LIMIT 1");
                    $openMaintenance->bind_param('i', $item['id']);
                    $openMaintenance->execute();
                    if ($openMaintenance->get_result()->num_rows) {
                        $updateItem = $conn->prepare("UPDATE inventory_items SET status = 'Under Repair' WHERE id = ?");
                    } else {
                        $updateItem = $conn->prepare("UPDATE inventory_items SET condition_status = 'Good', status = 'Available' WHERE id = ?");
                    }
                    $updateItem->bind_param('i', $item['id']);
                    $updateItem->execute();
                }
            }

            if ($request['status'] === 'approved') {
                $updateRequest = $conn->prepare("UPDATE equipment_requests SET status = 'returned' WHERE id = ? AND status = 'approved'");
                $updateRequest->bind_param('i', $requestId);
                $updateRequest->execute();
            }
            log_action('RETURN EQUIPMENT', 'BR-' . str_pad((string) $requestId, 5, '0', STR_PAD_LEFT) . ' · ' . $item['asset_tag'] . ' · ' . $quantity . ' unit(s) · ' . $condition);
            $conn->commit();
            flash('Equipment return recorded.');
        }
    } catch (DomainException $error) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        flash($error->getMessage(), 'error');
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
        }
        error_log('Staff equipment transaction failed: ' . $error->getMessage());
        flash('Could not complete the equipment transaction. Please refresh and try again.', 'error');
    }
    redirect('staff/release_returns.php' . ($action === 'return' ? '#return' : '#release'));
}

$releaseRequests = $conn->query("SELECT r.id, r.quantity, r.expected_return_date, r.created_at, borrower.full_name AS borrower_name, i.asset_tag, i.item_name, i.location, i.status AS item_status, i.condition_status FROM equipment_requests r JOIN users borrower ON borrower.id = r.requester_id JOIN inventory_items i ON i.id = r.inventory_item_id WHERE r.status = 'approved' AND NOT EXISTS (SELECT 1 FROM equipment_transactions t WHERE t.request_id = r.id AND t.action = 'released') ORDER BY r.reviewed_at, r.id LIMIT 100");
$returnRequests = $conn->query("SELECT r.id, r.quantity, r.expected_return_date, borrower.full_name AS borrower_name, i.asset_tag, i.item_name, i.location, releaser.full_name AS released_by, released.created_at AS released_at, released.quantity AS released_quantity FROM equipment_transactions released JOIN equipment_requests r ON r.id = released.request_id JOIN users borrower ON borrower.id = r.requester_id JOIN users releaser ON releaser.id = released.user_id JOIN inventory_items i ON i.id = released.inventory_item_id WHERE released.action = 'released' AND r.status IN ('approved', 'returned') AND NOT EXISTS (SELECT 1 FROM equipment_transactions returned WHERE returned.request_id = r.id AND returned.action = 'returned') ORDER BY r.expected_return_date, released.created_at LIMIT 100");
include __DIR__ . '/../includes/header.php';
?>
<div class="approval-page staff-release-page">
    <header class="approval-page-heading">
        <div>
            <p class="dashboard-eyebrow">EQUIPMENT TRANSACTIONS</p>
            <h1>Release &amp; Returns</h1>
            <p class="dashboard-subtitle">Verify each item by QR before recording an equipment handoff.</p>
        </div>
    </header>

    <section class="panel staff-qr-panel">
        <div>
            <span class="staff-qr-icon"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></span>
            <div><h2>QR verification</h2><p>Scan the asset tag from a release or return form, or enter the tag manually.</p></div>
        </div>
        <div class="staff-qr-reader-wrap" id="staff-qr-reader-wrap" hidden>
            <div id="staff-qr-reader"></div>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="stop-staff-scanner">Stop scanner</button>
            <span id="staff-qr-message" role="status">Starting camera...</span>
        </div>
    </section>

    <section class="panel staff-transaction-section" id="release">
        <div class="approval-section-heading">
            <div><h2>Equipment Release</h2><p>Admin-approved requests that have not yet been released.</p></div>
            <span><?= number_format($releaseRequests->num_rows) ?> awaiting release</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle approval-table staff-transaction-table">
                <thead><tr><th>Request</th><th>Borrower</th><th>Equipment</th><th>Approved qty</th><th>Expected return</th><th>Release</th></tr></thead>
                <tbody>
                    <?php if ($releaseRequests->num_rows): ?>
                        <?php while ($request = $releaseRequests->fetch_assoc()): ?>
                            <tr>
                                <td class="inventory-tag">BR-<?= str_pad((string) $request['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td><?= e($request['borrower_name']) ?></td>
                                <td><strong><?= e($request['item_name']) ?></strong><small class="d-block text-secondary"><?= e($request['asset_tag']) ?> · <?= e($request['location']) ?></small></td>
                                <td><?= number_format((int) $request['quantity']) ?></td>
                                <td><?= e(date('M j, Y', strtotime($request['expected_return_date']))) ?></td>
                                <td>
                                    <?php if ($request['item_status'] === 'Available' && !in_array($request['condition_status'], ['Needs Repair', 'Damaged'], true)): ?>
                                        <form method="post" class="staff-transaction-form">
                                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="transaction_action" value="release">
                                            <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                            <label class="visually-hidden" for="release-qty-<?= (int) $request['id'] ?>">Quantity to release</label>
                                            <input class="form-control form-control-sm" id="release-qty-<?= (int) $request['id'] ?>" name="quantity" type="number" min="1" max="<?= (int) $request['quantity'] ?>" value="<?= (int) $request['quantity'] ?>" readonly required>
                                            <label class="visually-hidden" for="release-tag-<?= (int) $request['id'] ?>">Scanned equipment tag</label>
                                            <input class="form-control form-control-sm staff-qr-tag" id="release-tag-<?= (int) $request['id'] ?>" name="asset_tag" placeholder="Scan asset tag" required>
                                            <button class="btn btn-sm btn-outline-primary" type="button" data-scan-target="release-tag-<?= (int) $request['id'] ?>" aria-label="Scan QR for <?= e($request['asset_tag']) ?>"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></button>
                                            <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Release</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="request-status status-rejected">Equipment unavailable</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5"><strong>No approved requests are waiting for release.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel staff-transaction-section" id="return">
        <div class="approval-section-heading">
            <div><h2>Equipment Return</h2><p>Released equipment that has not yet been checked back in.</p></div>
            <span><?= number_format($returnRequests->num_rows) ?> awaiting return</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle approval-table staff-transaction-table">
                <thead><tr><th>Request</th><th>Borrower</th><th>Equipment</th><th>Released</th><th>Released by</th><th>Return</th></tr></thead>
                <tbody>
                    <?php if ($returnRequests->num_rows): ?>
                        <?php while ($request = $returnRequests->fetch_assoc()): ?>
                            <tr>
                                <td class="inventory-tag">BR-<?= str_pad((string) $request['id'], 5, '0', STR_PAD_LEFT) ?></td>
                                <td><?= e($request['borrower_name']) ?></td>
                                <td><strong><?= e($request['item_name']) ?></strong><small class="d-block text-secondary"><?= e($request['asset_tag']) ?> · <?= e($request['location']) ?></small></td>
                                <td><?= e(date('M j, Y g:i A', strtotime($request['released_at']))) ?></td>
                                <td><?= e($request['released_by']) ?></td>
                                <td>
                                    <form method="post" class="staff-transaction-form staff-return-form">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="transaction_action" value="return">
                                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                                        <label class="visually-hidden" for="return-qty-<?= (int) $request['id'] ?>">Quantity to return</label>
                                        <input class="form-control form-control-sm" id="return-qty-<?= (int) $request['id'] ?>" name="quantity" type="number" min="1" max="<?= (int) $request['released_quantity'] ?>" value="<?= (int) $request['released_quantity'] ?>" readonly required>
                                        <label class="visually-hidden" for="return-condition-<?= (int) $request['id'] ?>">Returned equipment condition</label>
                                        <select class="form-select form-select-sm" id="return-condition-<?= (int) $request['id'] ?>" name="condition_status" required>
                                            <option value="Good">Good</option><option value="Needs Repair">Needs repair</option><option value="Damaged">Damaged</option>
                                        </select>
                                        <label class="visually-hidden" for="return-tag-<?= (int) $request['id'] ?>">Scanned equipment tag</label>
                                        <input class="form-control form-control-sm staff-qr-tag" id="return-tag-<?= (int) $request['id'] ?>" name="asset_tag" placeholder="Scan asset tag" required>
                                        <button class="btn btn-sm btn-outline-primary" type="button" data-scan-target="return-tag-<?= (int) $request['id'] ?>" aria-label="Scan QR for <?= e($request['asset_tag']) ?>"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></button>
                                        <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i> Record return</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-5"><strong>No equipment is awaiting return.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
window.addEventListener('load', () => {
    const readerWrap = document.getElementById('staff-qr-reader-wrap');
    const message = document.getElementById('staff-qr-message');
    const scannerButtons = document.querySelectorAll('[data-scan-target]');
    let activeInput = null;
    let scanner = null;

    async function stopScanner() {
        if (!scanner) return;
        try {
            await scanner.stop();
            await scanner.clear();
        } catch (error) {
            console.info('QR scanner stopped.');
        }
        scanner = null;
        readerWrap.hidden = true;
    }

    document.getElementById('stop-staff-scanner').addEventListener('click', stopScanner);
    scannerButtons.forEach((button) => {
        button.addEventListener('click', async () => {
            activeInput = document.getElementById(button.dataset.scanTarget);
            readerWrap.hidden = false;
            message.textContent = 'Starting camera...';
            if (!window.Html5Qrcode) {
                message.textContent = 'Camera scanner unavailable. Enter the asset tag manually.';
                return;
            }
            await stopScanner();
            readerWrap.hidden = false;
            scanner = new Html5Qrcode('staff-qr-reader');
            try {
                await scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 220, height: 220 } },
                    async (decodedText) => {
                        activeInput.value = decodedText.trim();
                        activeInput.dispatchEvent(new Event('input', { bubbles: true }));
                        message.textContent = 'QR scanned. Submit the form to verify the equipment.';
                        await stopScanner();
                        activeInput.focus();
                    },
                    () => {}
                );
                message.textContent = 'Point the camera at the equipment QR label.';
            } catch (error) {
                message.textContent = 'Camera unavailable. Enter the asset tag manually.';
                await stopScanner();
            }
        });
    });
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>