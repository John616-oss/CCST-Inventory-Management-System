<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
ensure_inventory_table($conn);
$user = current_user();
$userId = (int) $user['id'];
$selectedEquipmentId = max(0, (int) ($_GET['equipment_id'] ?? 0));
$privateUploadDir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ccst_private_uploads' . DIRECTORY_SEPARATOR . 'maintenance';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'report') {
        $itemId = filter_var($_POST['inventory_item_id'] ?? null, FILTER_VALIDATE_INT);
        $category = $_POST['problem_category'] ?? '';
        $priority = $_POST['priority'] ?? '';
        $descriptionInput = $_POST['issue_description'] ?? '';
        $description = is_string($descriptionInput) ? trim($descriptionInput) : '';
        $categories = ['hardware', 'physical', 'electrical', 'software', 'other'];
        $priorities = ['low', 'medium', 'high', 'critical'];
        $photoPath = null;
        $storedPhoto = null;

        if (!$itemId || !in_array($category, $categories, true) || !in_array($priority, $priorities, true) || $description === '' || strlen($description) > 10000) {
            flash('Check the equipment, category, priority, and issue description.', 'error');
            redirect('maintenance/requests.php');
        }

        if (isset($_FILES['damage_photo']) && $_FILES['damage_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['damage_photo'];
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 2 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
                flash('The photo must be a valid image under 2 MB.', 'error');
                redirect('maintenance/requests.php');
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                flash('Use a JPG, PNG, or WebP damage photo.', 'error');
                redirect('maintenance/requests.php');
            }
            if (!is_dir($privateUploadDir) && !mkdir($privateUploadDir, 0700, true) && !is_dir($privateUploadDir)) {
                flash('Photo storage is unavailable. The report was not saved.', 'error');
                redirect('maintenance/requests.php');
            }
            $storedPhoto = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($upload['tmp_name'], $privateUploadDir . DIRECTORY_SEPARATOR . $storedPhoto)) {
                flash('The photo could not be stored. The report was not saved.', 'error');
                redirect('maintenance/requests.php');
            }
            $photoPath = $storedPhoto;
        }

        try {
            $conn->begin_transaction();
            $itemQuery = $conn->prepare("SELECT id,item_name,status FROM inventory_items WHERE id=? AND status<>'Disposed' FOR UPDATE");
            $itemQuery->bind_param('i', $itemId);
            $itemQuery->execute();
            $item = $itemQuery->get_result()->fetch_assoc();
            if (!$item) {
                throw new RuntimeException('Equipment not found or disposed.');
            }
            $activeQuery = $conn->prepare("SELECT id FROM maintenance_requests WHERE inventory_item_id=? AND status IN ('Pending','In Progress') LIMIT 1");
            $activeQuery->bind_param('i', $itemId);
            $activeQuery->execute();
            if ($activeQuery->get_result()->fetch_assoc()) {
                throw new RuntimeException('This equipment already has an open maintenance request.');
            }
            $insert = $conn->prepare('INSERT INTO maintenance_requests (inventory_item_id,reported_by,assigned_to,problem_category,issue_description,priority,photo_path) VALUES (?,?,?,?,?,?,?)');
            $insert->bind_param('iiissss', $itemId, $userId, $userId, $category, $description, $priority, $photoPath);
            $insert->execute();
            $requestId = (int) $conn->insert_id;
            $itemCondition = $category === 'physical' ? 'Damaged' : 'Needs Repair';
            $itemStatus = $item['status'] === 'Available' ? 'Under Repair' : $item['status'];
            $updateItem = $conn->prepare('UPDATE inventory_items SET condition_status=?,status=? WHERE id=?');
            $updateItem->bind_param('ssi', $itemCondition, $itemStatus, $itemId);
            $updateItem->execute();
            $history = $conn->prepare("INSERT INTO maintenance_history (inventory_item_id,request_id,user_id,action,work_description) VALUES (?,?,?,'reported',?)");
            $history->bind_param('iiis', $itemId, $requestId, $userId, $description);
            $history->execute();
            $conn->commit();
            log_action('REPORT EQUIPMENT DAMAGE', $item['item_name'] . ' (#' . $itemId . ')');
            flash('Maintenance request submitted.');
        } catch (Throwable $exception) {
            $conn->rollback();
            if ($storedPhoto !== null && is_file($privateUploadDir . DIRECTORY_SEPARATOR . $storedPhoto)) {
                unlink($privateUploadDir . DIRECTORY_SEPARATOR . $storedPhoto);
            }
            flash($exception instanceof RuntimeException ? $exception->getMessage() : 'The maintenance request could not be saved.', 'error');
        }
        redirect('maintenance/requests.php');
    }

    if ($action === 'update') {
        $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
        $newStatus = $_POST['status'] ?? '';
        $repairInput = $_POST['repair_description'] ?? '';
        $partsInput = $_POST['parts_replaced'] ?? '';
        $repairDescription = is_string($repairInput) ? trim($repairInput) : '';
        $partsReplaced = is_string($partsInput) ? trim($partsInput) : '';
        if (!$requestId || !in_array($newStatus, ['In Progress', 'Completed', 'Cannot Be Repaired'], true) || $repairDescription === '' || strlen($repairDescription) > 10000 || strlen($partsReplaced) > 10000) {
            flash('Provide a valid repair status and describe the work performed.', 'error');
            redirect('maintenance/requests.php');
        }

        try {
            $conn->begin_transaction();
            $requestQuery = $conn->prepare('SELECT r.*,i.item_name,i.status AS item_status FROM maintenance_requests r JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.id=? FOR UPDATE');
            $requestQuery->bind_param('i', $requestId);
            $requestQuery->execute();
            $request = $requestQuery->get_result()->fetch_assoc();
            if (!$request || ((int) $request['assigned_to'] !== $userId && $request['assigned_to'] !== null) || !in_array($request['status'], ['Pending', 'In Progress'], true)) {
                throw new RuntimeException('This request is not assigned to you or is already closed.');
            }
            if ($newStatus === $request['status'] && $repairDescription === (string) ($request['repair_description'] ?? '') && $partsReplaced === (string) ($request['parts_replaced'] ?? '')) {
                throw new RuntimeException('No changes to save.');
            }
            $isClosed = in_array($newStatus, ['Completed', 'Cannot Be Repaired'], true);
            $requestUpdate = $conn->prepare('UPDATE maintenance_requests SET status=?,repair_description=?,parts_replaced=?,completed_at=? WHERE id=?');
            $completedAt = $isClosed ? date('Y-m-d H:i:s') : null;
            $partsValue = $partsReplaced !== '' ? $partsReplaced : null;
            $requestUpdate->bind_param('ssssi', $newStatus, $repairDescription, $partsValue, $completedAt, $requestId);
            $requestUpdate->execute();

            if ($newStatus === 'In Progress') {
                $itemUpdate = $conn->prepare("UPDATE inventory_items SET status=IF(status='Available','Under Repair',status),condition_status=IF(condition_status IN ('Good','New'),'Needs Repair',condition_status) WHERE id=?");
                $historyAction = 'started';
            } elseif ($newStatus === 'Completed') {
                $openQuery = $conn->prepare("SELECT COUNT(*) FROM maintenance_requests WHERE inventory_item_id=? AND id<>? AND status IN ('Pending','In Progress')");
                $openQuery->bind_param('ii', $request['inventory_item_id'], $requestId);
                $openQuery->execute();
                $hasOtherOpen = (int) $openQuery->get_result()->fetch_row()[0] > 0;
                if ($hasOtherOpen) {
                    $itemUpdate = $conn->prepare("UPDATE inventory_items SET status=IF(status='Available','Under Repair',status) WHERE id=?");
                } else {
                    $itemUpdate = $conn->prepare("UPDATE inventory_items SET condition_status='Good',status=IF(status='Under Repair','Available',status) WHERE id=?");
                }
                $historyAction = 'completed';
            } else {
                $itemUpdate = $conn->prepare("UPDATE inventory_items SET condition_status='Damaged',status=IF(status IN ('Available','Under Repair'),'Under Repair',status) WHERE id=?");
                $historyAction = 'cannot_be_repaired';
            }
            $itemId = (int) $request['inventory_item_id'];
            $itemUpdate->bind_param('i', $itemId);
            $itemUpdate->execute();
            $history = $conn->prepare('INSERT INTO maintenance_history (inventory_item_id,request_id,user_id,action,work_description,parts_replaced) VALUES (?,?,?,?,?,?)');
            $history->bind_param('iiisss', $itemId, $requestId, $userId, $historyAction, $repairDescription, $partsValue);
            $history->execute();
            $conn->commit();
            log_action('UPDATE MAINTENANCE REQUEST', $request['item_name'] . ' marked ' . $newStatus);
            flash('Repair status updated.');
        } catch (Throwable $exception) {
            $conn->rollback();
            flash($exception instanceof RuntimeException ? $exception->getMessage() : 'The repair update could not be saved.', 'error');
        }
        redirect('maintenance/requests.php');
    }

    flash('Invalid maintenance action.', 'error');
    redirect('maintenance/requests.php');
}

$search = trim($_GET['q'] ?? '');
$statusFilter = $_GET['status'] ?? '';
$allowedStatuses = ['Pending', 'In Progress', 'Completed', 'Cannot Be Repaired'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$sql = 'SELECT r.*,i.asset_tag,i.item_name,i.category,i.location,i.quantity,i.unit,u.full_name AS reporter_name,assignee.full_name AS assignee_name FROM maintenance_requests r JOIN inventory_items i ON i.id=r.inventory_item_id JOIN users u ON u.id=r.reported_by LEFT JOIN users assignee ON assignee.id=r.assigned_to WHERE (r.assigned_to=? OR (r.assigned_to IS NULL AND r.reported_by=?))';
$types = 'ii';
$values = [$userId, $userId];
if ($search !== '') {
    $sql .= ' AND (i.item_name LIKE ? OR i.asset_tag LIKE ? OR r.issue_description LIKE ?)';
    $pattern = '%' . $search . '%';
    $types .= 'sss';
    array_push($values, $pattern, $pattern, $pattern);
}
if ($statusFilter !== '') {
    $sql .= ' AND r.status=?';
    $types .= 's';
    $values[] = $statusFilter;
}
$sql .= ' ORDER BY FIELD(r.status,"Pending","In Progress","Completed","Cannot Be Repaired"),r.updated_at DESC LIMIT 100';
$requestQuery = $conn->prepare($sql);
stmt_bind($requestQuery, $types, $values);
$requestQuery->execute();
$requests = $requestQuery->get_result();
$equipment = $conn->query("SELECT id,asset_tag,item_name,category,location FROM inventory_items WHERE status<>'Disposed' ORDER BY item_name,asset_tag");
include __DIR__ . '/../includes/header.php';
?>
<div class="maintenance-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">MAINTENANCE WORKSPACE</p><h1>Repair requests</h1><p class="dashboard-subtitle">Report damage and record repair progress.</p></div><a class="btn btn-outline-primary" href="<?= e(BASE_URL) ?>/scan.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i> Scan equipment</a></header>
    <section class="panel maintenance-request-form-panel" id="report">
        <div class="dashboard-section-heading"><div><h2>Report damaged equipment</h2><p>Open one active maintenance request per equipment item.</p></div></div>
        <form method="post" enctype="multipart/form-data" class="maintenance-request-form">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="report">
            <label>Equipment<select class="form-select" name="inventory_item_id" required><option value="">Select equipment</option><?php while ($item = $equipment->fetch_assoc()): ?><option value="<?= (int) $item['id'] ?>" <?= $selectedEquipmentId === (int) $item['id'] ? 'selected' : '' ?>><?= e($item['item_name']) ?> · <?= e($item['asset_tag']) ?> · <?= e($item['location']) ?></option><?php endwhile; ?></select></label>
            <label>Problem category<select class="form-select" name="problem_category" required><option value="hardware">Hardware problem</option><option value="physical">Physical damage</option><option value="electrical">Electrical problem</option><option value="software">Software problem</option><option value="other">Other</option></select></label>
            <label>Priority<select class="form-select" name="priority" required><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option><option value="critical">Critical</option></select></label>
            <label class="maintenance-request-description">Damage description<textarea class="form-control" name="issue_description" rows="3" maxlength="10000" required></textarea></label>
            <label>Photo (optional)<input class="form-control" name="damage_photo" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, or WebP; maximum 2 MB.</small></label>
            <button class="btn btn-primary maintenance-request-submit" type="submit"><i class="bi bi-plus-circle" aria-hidden="true"></i> Submit report</button>
        </form>
    </section>

    <form class="maintenance-records-filters" method="get" role="search"><div class="maintenance-filter-search"><i class="bi bi-search" aria-hidden="true"></i><input name="q" value="<?= e($search) ?>" placeholder="Search equipment or issue" aria-label="Search requests"></div><label class="maintenance-filter-select"><select name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $status): ?><option value="<?= e($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></label><button class="maintenance-filter-submit" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button></form>

    <section class="panel maintenance-request-list"><div class="dashboard-section-heading"><div><h2>Assigned repair requests</h2><p>Latest 100 requests assigned to your account</p></div></div>
        <?php if ($requests->num_rows): while ($request = $requests->fetch_assoc()): ?>
            <article class="maintenance-request-row">
                <div class="maintenance-request-copy"><div class="maintenance-request-title"><div><h3><?= e($request['item_name']) ?></h3><span><?= e($request['asset_tag']) ?> · <?= e($request['category']) ?> · <?= e($request['location']) ?></span></div><span class="maintenance-record-status <?= $request['status'] === 'Completed' ? 'status-completed' : ($request['status'] === 'Pending' ? 'status-pending' : 'status-in-progress') ?>"><?= e($request['status']) ?></span></div>
                    <p class="maintenance-request-issue"><strong><?= e(ucfirst($request['problem_category'])) ?> · <?= e(ucfirst($request['priority'])) ?> priority</strong><br><?= nl2br(e($request['issue_description'])) ?></p>
                    <small>Reported <?= e(date('M j, Y g:i A', strtotime($request['created_at']))) ?> by <?= e($request['reporter_name']) ?> · Assigned to <?= e($request['assignee_name'] ?? 'Unassigned') ?></small>
                    <?php if ($request['photo_path']): ?><a href="<?= e(BASE_URL) ?>/maintenance/photo.php?id=<?= (int) $request['id'] ?>" target="_blank" rel="noopener">View damage photo</a><?php endif; ?>
                    <a class="maintenance-record-view" href="<?= e(BASE_URL) ?>/maintenance_history.php?id=<?= (int) $request['inventory_item_id'] ?>"><i class="bi bi-clock-history" aria-hidden="true"></i> Repair history</a>
                </div>
                <?php if (in_array($request['status'], ['Pending', 'In Progress'], true)): ?><form method="post" class="maintenance-request-update"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="update"><input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>"><label>Status<select class="form-select" name="status"><option value="In Progress" <?= $request['status'] === 'In Progress' ? 'selected' : '' ?>>In Progress</option><option value="Completed">Completed</option><option value="Cannot Be Repaired">Cannot Be Repaired</option></select></label><label>Repair action / details<textarea class="form-control" name="repair_description" rows="2" required maxlength="10000" placeholder="Describe the work performed"><?= e($request['repair_description'] ?? '') ?></textarea></label><label>Parts replaced<input class="form-control" name="parts_replaced" maxlength="10000" value="<?= e($request['parts_replaced'] ?? '') ?>"></label><button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-check2" aria-hidden="true"></i> Save update</button></form><?php else: ?><div class="maintenance-request-resolution"><strong>Repair action</strong><p><?= nl2br(e($request['repair_description'] ?: 'No repair details recorded.')) ?></p><small>Parts: <?= e($request['parts_replaced'] ?: 'None recorded') ?></small></div><?php endif; ?>
            </article>
        <?php endwhile; else: ?><p class="maintenance-empty-state">No maintenance requests match these filters.</p><?php endif; ?>
    </section>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
