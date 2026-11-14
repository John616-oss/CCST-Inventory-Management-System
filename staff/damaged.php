<?php
require_once __DIR__ . '/functions.php';
require_role(['staff']);
ensure_staff_equipment_transactions_table($conn);
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $assetTag = trim($_POST['asset_tag'] ?? '');
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
    $details = trim($_POST['details'] ?? '');

    if ($assetTag === '' || strlen($assetTag) > 40 || $quantity === false || $quantity < 1 || $details === '' || strlen($details) > 500) {
        flash('Enter a valid asset tag, quantity, and damage description.', 'error');
        redirect('staff/damaged.php');
    }

    try {
        $conn->begin_transaction();
        $itemQuery = $conn->prepare('SELECT id, asset_tag, item_name, quantity, status FROM inventory_items WHERE asset_tag = ? FOR UPDATE');
        $itemQuery->bind_param('s', $assetTag);
        $itemQuery->execute();
        $item = $itemQuery->get_result()->fetch_assoc();
        if (!$item || $item['status'] === 'Disposed') {
            throw new DomainException('That active equipment item was not found.');
        }
        if ($quantity > (int) $item['quantity']) {
            throw new DomainException('The reported quantity cannot exceed the recorded equipment quantity.');
        }

        $insert = $conn->prepare("INSERT INTO equipment_transactions(request_id, inventory_item_id, user_id, action, quantity, condition_status, details) VALUES(NULL, ?, ?, 'damaged_reported', ?, 'Damaged', ?)");
        $insert->bind_param('iiis', $item['id'], $user['id'], $quantity, $details);
        $insert->execute();

        if ($item['status'] === 'In Use') {
            $update = $conn->prepare("UPDATE inventory_items SET condition_status = 'Damaged' WHERE id = ?");
        } else {
            $update = $conn->prepare("UPDATE inventory_items SET condition_status = 'Damaged', status = 'Under Repair' WHERE id = ?");
        }
        $update->bind_param('i', $item['id']);
        $update->execute();
        log_action('REPORT DAMAGED EQUIPMENT', $item['asset_tag'] . ' · ' . $quantity . ' unit(s) · ' . $details);
        $conn->commit();
        flash('Damage report recorded. The equipment is no longer available for borrowing.');
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
        error_log('Damaged equipment report failed: ' . $error->getMessage());
        flash('Could not save the damage report. Please try again.', 'error');
    }
    redirect('staff/damaged.php');
}

$recentReports = $conn->query("SELECT t.quantity, t.details, t.created_at, u.full_name AS staff_name, i.item_name, i.asset_tag FROM equipment_transactions t JOIN users u ON u.id = t.user_id JOIN inventory_items i ON i.id = t.inventory_item_id WHERE t.action = 'damaged_reported' ORDER BY t.id DESC LIMIT 20");
include __DIR__ . '/../includes/header.php';
?>
<div class="approval-page staff-damaged-page">
    <header class="approval-page-heading">
        <div>
            <p class="dashboard-eyebrow">INVENTORY ALERT</p>
            <h1>Report Damaged Equipment</h1>
            <p class="dashboard-subtitle">Record damage so the equipment is excluded from borrowing until it is cleared for use.</p>
        </div>
    </header>

    <section class="panel staff-damage-form-panel">
        <div class="approval-section-heading"><div><h2>Damage report</h2><p>Scan the asset QR label or enter its tag manually.</p></div></div>
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <div class="col-lg-5">
                <label class="form-label" for="damaged-asset-tag">Equipment asset tag</label>
                <div class="input-group">
                    <input class="form-control" id="damaged-asset-tag" name="asset_tag" maxlength="40" placeholder="CCST-INV-2026-0001" required>
                    <button class="btn btn-outline-primary" type="button" id="start-damage-scanner" aria-label="Scan equipment QR code"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="col-lg-2 col-6">
                <label class="form-label" for="damaged-quantity">Affected quantity</label>
                <input class="form-control" id="damaged-quantity" name="quantity" type="number" min="1" value="1" required>
            </div>
            <div class="col-lg-5">
                <label class="form-label" for="damage-details">Damage description</label>
                <input class="form-control" id="damage-details" name="details" maxlength="500" placeholder="Describe the issue" required>
            </div>
            <div class="col-12">
                <div class="staff-damage-scanner" id="damage-scanner-wrap" hidden>
                    <div id="damage-qr-reader"></div>
                    <button class="btn btn-sm btn-outline-secondary" type="button" id="stop-damage-scanner">Stop scanner</button>
                    <span id="damage-scan-message" role="status">Starting camera...</span>
                </div>
                <button class="btn btn-danger" type="submit"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Save damage report</button>
            </div>
        </form>
    </section>

    <section class="panel approval-list-panel">
        <div class="approval-section-heading"><div><h2>Recent damage reports</h2><p><?= number_format($recentReports->num_rows) ?> reports shown</p></div></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle approval-table">
                <thead><tr><th>Date and time</th><th>Equipment</th><th>Quantity</th><th>Reported by</th><th>Damage description</th></tr></thead>
                <tbody>
                    <?php if ($recentReports->num_rows): ?>
                        <?php while ($report = $recentReports->fetch_assoc()): ?>
                            <tr>
                                <td><?= e(date('M j, Y g:i A', strtotime($report['created_at']))) ?></td>
                                <td><strong><?= e($report['item_name']) ?></strong><small class="d-block text-secondary"><?= e($report['asset_tag']) ?></small></td>
                                <td><?= number_format((int) $report['quantity']) ?></td>
                                <td><?= e($report['staff_name']) ?></td>
                                <td><?= e($report['details']) ?></td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center py-5"><strong>No damage reports recorded.</strong></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
window.addEventListener('load', () => {
    const tagInput = document.getElementById('damaged-asset-tag');
    const readerWrap = document.getElementById('damage-scanner-wrap');
    const message = document.getElementById('damage-scan-message');
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

    document.getElementById('stop-damage-scanner').addEventListener('click', stopScanner);
    document.getElementById('start-damage-scanner').addEventListener('click', async () => {
        readerWrap.hidden = false;
        if (!window.Html5Qrcode) {
            message.textContent = 'Camera scanner unavailable. Enter the asset tag manually.';
            return;
        }
        scanner = new Html5Qrcode('damage-qr-reader');
        try {
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                async (decodedText) => {
                    tagInput.value = decodedText.trim();
                    message.textContent = 'QR scanned. Submit the report to verify the equipment.';
                    await stopScanner();
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
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>