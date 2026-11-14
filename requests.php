<?php
require_once __DIR__.'/staff/functions.php';
require_role(['staff']);
ensure_staff_equipment_transactions_table($conn);
$userId=(int)current_user()['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $action=$_POST['action']??'submit';
    $requestId=(int)($_POST['request_id']??0);
    if($action==='cancel'){
        $update=$conn->prepare("UPDATE equipment_requests SET status='cancelled' WHERE id=? AND requester_id=? AND status='pending'");
        $update->bind_param('ii',$requestId,$userId);
        $update->execute();
        if($update->affected_rows){log_action('CANCEL EQUIPMENT REQUEST','Request #'.$requestId);flash('Request cancelled.');}
        else flash('Only your pending requests can be cancelled.','error');
        redirect('requests.php');
    }
    if($action==='return'){
        flash('Returns must be processed by Staff through Release & Returns.','error');
        redirect('staff/release_returns.php#return');
    }

    $itemId=(int)($_POST['item_id']??0);
    $quantity=max(1,(int)($_POST['quantity']??1));
    $purpose=trim($_POST['purpose']??'');
    $returnDate=trim($_POST['expected_return_date']??'');
    $parsedReturnDate=$returnDate!==''?DateTimeImmutable::createFromFormat('!Y-m-d',$returnDate):false;
    $dateErrors=DateTimeImmutable::getLastErrors();
    $dateValid=$parsedReturnDate!==false&&($dateErrors===false||($dateErrors['warning_count']===0&&$dateErrors['error_count']===0))&&$parsedReturnDate->format('Y-m-d')===$returnDate&&$returnDate>=date('Y-m-d');
    if($itemId<1||$purpose===''||strlen($purpose)>255||!$dateValid){
        flash('Choose equipment, enter a purpose, and select a valid return date.','error');
        redirect('requests.php');
    }
    try{
        $conn->begin_transaction();
        $itemQuery=$conn->prepare("SELECT quantity,status,condition_status FROM inventory_items WHERE id=? FOR UPDATE");
        $itemQuery->bind_param('i',$itemId);
        $itemQuery->execute();
        $item=$itemQuery->get_result()->fetch_assoc();
        if(!$item||$item['status']!=='Available'||in_array($item['condition_status'],['Needs Repair','Damaged'],true)){
            throw new DomainException('That equipment is unavailable for borrowing.');
        }
        $borrowedQuery=$conn->prepare("SELECT COALESCE(SUM(CASE action WHEN 'released' THEN CAST(quantity AS SIGNED) WHEN 'returned' THEN -CAST(quantity AS SIGNED) ELSE 0 END),0) AS borrowed FROM equipment_transactions WHERE inventory_item_id=? AND action IN ('released','returned')");
        $borrowedQuery->bind_param('i',$itemId);
        $borrowedQuery->execute();
        $borrowed=(int)$borrowedQuery->get_result()->fetch_assoc()['borrowed'];
        $available=(int)$item['quantity']-$borrowed;
        if($quantity>$available)throw new DomainException('Only '.$available.' unit(s) are currently available.');
        $insert=$conn->prepare("INSERT INTO equipment_requests(requester_id,inventory_item_id,quantity,purpose,expected_return_date) VALUES(?,?,?,?,?)");
        $insert->bind_param('iiiss',$userId,$itemId,$quantity,$purpose,$returnDate);
        $insert->execute();
        $requestId=(int)$conn->insert_id;
        $conn->commit();
        log_action('SUBMIT EQUIPMENT REQUEST','Request #'.$requestId.' for item #'.$itemId);
        flash('Borrowing request submitted for Admin approval.');
    }catch(DomainException $e){
        try{$conn->rollback();}catch(Throwable $ignored){}
        flash($e->getMessage(),'error');
    }catch(Throwable $e){
        try{$conn->rollback();}catch(Throwable $ignored){}
        error_log('Equipment request failed: '.$e->getMessage());
        flash('Could not submit the request. Please try again.','error');
    }
    redirect('requests.php');
}

$borrowedSubquery=staff_borrowed_units_subquery();
$availableItems=$conn->query("SELECT i.id,i.asset_tag,i.item_name,i.location,GREATEST(i.quantity-COALESCE(b.borrowed,0),0) AS available_units FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') AND i.quantity>COALESCE(b.borrowed,0) ORDER BY i.item_name");
$requestsQuery=$conn->prepare('SELECT r.*,i.asset_tag,i.item_name,i.location,u.full_name AS reviewed_by_name FROM equipment_requests r JOIN inventory_items i ON i.id=r.inventory_item_id LEFT JOIN users u ON u.id=r.reviewed_by WHERE r.requester_id=? ORDER BY r.id DESC LIMIT 100');
$requestsQuery->bind_param('i',$userId);
$requestsQuery->execute();
$requests=$requestsQuery->get_result();
include __DIR__.'/includes/header.php';
?>
<div class="approval-page staff-requests-page">
    <header class="approval-page-heading"><div><p class="dashboard-eyebrow">EQUIPMENT BORROWING</p><h1>My Requests</h1><p class="dashboard-subtitle">Request equipment for school activities and track its approval status.</p></div></header>
    <section class="panel request-form-panel"><div class="approval-section-heading"><div><h2>New borrowing request</h2><p>Requests are subject to Admin approval.</p></div></div>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="submit">
            <div class="col-lg-5"><label class="form-label" for="item_id">Equipment</label><select class="form-select" id="item_id" name="item_id" required><option value="">Select available equipment</option><?php while($item=$availableItems->fetch_assoc()): ?><option value="<?=$item['id']?>" data-available="<?=$item['available_units']?>"><?=e($item['asset_tag'].' · '.$item['item_name'].' · '.$item['location'].' ('.$item['available_units'].' available)')?></option><?php endwhile; ?></select></div>
            <div class="col-lg-2 col-6"><label class="form-label" for="quantity">Quantity</label><input class="form-control" id="quantity" name="quantity" type="number" min="1" value="1" required></div>
            <div class="col-lg-2 col-6"><label class="form-label" for="expected_return_date">Expected return</label><input class="form-control" id="expected_return_date" name="expected_return_date" type="date" min="<?=e(date('Y-m-d'))?>" required></div>
            <div class="col-lg-3"><label class="form-label" for="purpose">Purpose</label><input class="form-control" id="purpose" name="purpose" maxlength="255" placeholder="Classroom presentation" required></div>
            <div class="col-12"><button class="btn btn-primary" <?=$availableItems->num_rows===0?'disabled':''?>><i class="bi bi-send" aria-hidden="true"></i> Submit request</button><?php if($availableItems->num_rows===0): ?><span class="request-form-hint">No borrowable equipment is currently available.</span><?php endif; ?></div>
        </form>
    </section>
    <section class="panel approval-list-panel"><div class="approval-section-heading"><div><h2>Request history</h2><p><?=number_format($requests->num_rows)?> requests shown</p></div></div>
        <div class="table-responsive"><table class="table table-hover align-middle approval-table"><thead><tr><th>Request</th><th>Equipment</th><th>Qty</th><th>Purpose</th><th>Return date</th><th>Status</th><th>Admin note</th><th></th></tr></thead><tbody>
        <?php if($requests->num_rows): while($request=$requests->fetch_assoc()): ?><tr><td class="inventory-tag">BR-<?=str_pad((string)$request['id'],5,'0',STR_PAD_LEFT)?></td><td><strong><?=e($request['item_name'])?></strong><small class="d-block text-secondary"><?=e($request['asset_tag'])?> · <?=e($request['location'])?></small></td><td><?=number_format((int)$request['quantity'])?></td><td><?=e($request['purpose'])?></td><td><?=e(date('M j, Y',strtotime($request['expected_return_date'])))?></td><td><span class="request-status status-<?=e($request['status'])?>"><?=e(ucfirst($request['status']))?></span></td><td><?=e($request['review_note']?:'—')?><?php if($request['reviewed_by_name']): ?><small class="d-block text-secondary">By <?=e($request['reviewed_by_name'])?></small><?php endif; ?></td><td><?php if($request['status']==='pending'): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?=$request['id']?>"><button class="btn btn-sm btn-outline-secondary" type="submit" title="Cancel request" aria-label="Cancel request BR-<?=str_pad((string)$request['id'],5,'0',STR_PAD_LEFT)?>"><i class="bi bi-x-lg" aria-hidden="true"></i></button></form><?php elseif($request['status']==='approved'): ?><a class="btn btn-sm btn-outline-primary" href="staff/release_returns.php#release">Prepare release</a><?php endif; ?></td></tr><?php endwhile; else: ?><tr><td colspan="8" class="text-center py-5"><strong>No borrowing requests yet</strong><br><span class="muted">Submit a request above to get started.</span></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<script>
const requestItem=document.getElementById('item_id');
const requestQuantity=document.getElementById('quantity');
function updateRequestQuantity(){const available=Number(requestItem.selectedOptions[0]?.dataset.available||1);requestQuantity.max=String(available);if(Number(requestQuantity.value)>available)requestQuantity.value=String(available);}
requestItem.addEventListener('change',updateRequestQuantity);
</script>
<?php include __DIR__.'/includes/footer.php'; ?>