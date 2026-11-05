<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);
include __DIR__ . '/../includes/header.php';
?>
<div class="scan-page">
    <header class="dashboard-titlebar">
        <div>
            <p class="dashboard-eyebrow">FACILITIES SERVICE</p>
            <h1>Maintenance Workbench</h1>
            <p class="dashboard-subtitle">Scan the QR code on the equipment or room assigned for repair.</p>
        </div>
    </header>
    <section class="panel scan-panel">
        <div class="maintenance-scan-mark"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></div>
        <h2>Start with a scan</h2>
        <p class="muted">Only the scanned equipment or the repair items in the scanned room will be shown.</p>
        <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-camera" aria-hidden="true"></i> Open QR scanner</a>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>