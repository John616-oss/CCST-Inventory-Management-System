<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin']);
ensure_equipment_requests_table($conn);
$adminId=(int)current_user()['id'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $requestId=(int)($_POST['request_id']??0);
    $action=$_POST['request_action']??'';
    $reviewNote=trim($_POST['review_note']??'');
    if(!in_array($action,['approve','reject','return'],true)||$requestId<1||strlen($reviewNote)>500){
        flash('Invalid request action.','error');
        redirect('approval_requests.php');
    }
    try{
        $conn->begin_transaction();
        $requestQuery=$conn->prepare('SELECT r.id,r.status,r.quantity,r.inventory_item_id,r.requester_id,i.item_name,i.asset_tag,i.quantity AS stock_quantity,i.status AS item_status,i.condition_status FROM equipment_requests r JOIN inventory_items i ON i.id=r.inventory_item_id WHERE r.id=? FOR UPDATE');
        $requestQuery->bind_param('i',$requestId);
        $requestQuery->execute();
        $request=$requestQuery->get_result()->fetch_assoc();
        if(!$request)throw new DomainException('Request was not found.');

        if($action==='approve'){
            if($request['status']!=='pending')throw new DomainException('Only pending requests can be approved.');
            $itemLock=$conn->prepare('SELECT quantity,status,condition_status FROM inventory_items WHERE id=? FOR UPDATE');
            $itemLock->bind_param('i',$request['inventory_item_id']);
            $itemLock->execute();
            $itemState=$itemLock->get_result()->fetch_assoc();
            if(!$itemState)throw new DomainException('The requested equipment no longer exists.');
            $borrowedQuery=$conn->prepare("SELECT COALESCE(SUM(quantity),0) AS borrowed FROM equipment_requests WHERE inventory_item_id=? AND status='approved'");
            $borrowedQuery->bind_param('i',$request['inventory_item_id']);
            $borrowedQuery->execute();
            $borrowed=(int)$borrowedQuery->get_result()->fetch_assoc()['borrowed'];
            $available=(int)$itemState['quantity']-$borrowed;
            if($itemState['status']!=='Available'||in_array($itemState['condition_status'],['Needs Repair','Damaged'],true))throw new DomainException('This equipment is no longer available to borrow.');
            if((int)$request['quantity']>$available)throw new DomainException('Only '.$available.' unit(s) are available; this request is still pending.');
            $update=$conn->prepare("UPDATE equipment_requests SET status='approved',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='pending'");
            $update->bind_param('isi',$adminId,$reviewNote,$requestId);
            $message='Request approved. The borrowed quantity is now reserved.';
            $logAction='APPROVE EQUIPMENT REQUEST';
        }elseif($action==='reject'){
            if($request['status']!=='pending')throw new DomainException('Only pending requests can be rejected.');
            $update=$conn->prepare("UPDATE equipment_requests SET status='rejected',reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE id=? AND status='pending'");
            $update->bind_param('isi',$adminId,$reviewNote,$requestId);
            $message='Request rejected.';
            $logAction='REJECT EQUIPMENT REQUEST';
        }else{
            if($request['status']!=='approved')throw new DomainException('Only approved requests can be marked returned.');
            $update=$conn->prepare("UPDATE equipment_requests SET status='returned',review_note=CASE WHEN ?='' THEN review_note ELSE ? END WHERE id=? AND status='approved'");
            $update->bind_param('ssi',$reviewNote,$reviewNote,$requestId);
            $message='Equipment return recorded; the units are available again.';
            $logAction='RECORD EQUIPMENT RETURN';
        }
        $update->execute();
        if(!$update->affected_rows)throw new DomainException('The request changed. Refresh and review it again.');
        $conn->commit();
        log_action($logAction,'BR-'.str_pad((string)$requestId,5,'0',STR_PAD_LEFT).' · '.$request['asset_tag']);
        flash($message);
    }catch(DomainException $e){
        try{$conn->rollback();}catch(Throwable $ignored){}
        flash($e->getMessage(),'error');
    }catch(Throwable $e){
        try{$conn->rollback();}catch(Throwable $ignored){}
        error_log('Approval request action failed: '.$e->getMessage());
        flash('Could not update the request. Please try again.','error');
    }
    redirect('approval_requests.php');
}

$counts=['pending'=>0,'approved'=>0,'returned'=>0,'rejected'=>0,'cancelled'=>0];
$countRows=$conn->query('SELECT status,COUNT(*) AS total FROM equipment_requests GROUP BY status');
while($countRow=$countRows->fetch_assoc())$counts[$countRow['status']]=(int)$countRow['total'];
$categoryResult=$conn->query('SELECT DISTINCT category FROM inventory_items ORDER BY category');
$categories=[];
while($categoryRow=$categoryResult->fetch_assoc())$categories[]=$categoryRow['category'];

$query=trim($_GET['q']??'');
$categoryFilter=in_array($_GET['category']??'', $categories, true)?$_GET['category']:'';
$statusValues=['pending','approved','returned','rejected','cancelled'];
$statusFilter=in_array($_GET['status']??'', $statusValues, true)?$_GET['status']:'';
$dateFrom=trim($_GET['from']??'');
$dateTo=trim($_GET['to']??'');
foreach(['dateFrom','dateTo'] as $dateVariable){
    $dateValue=$$dateVariable;
    if($dateValue!==''){
        $parsedDate=preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateValue)?DateTimeImmutable::createFromFormat('!Y-m-d',$dateValue):false;
        $dateErrors=DateTimeImmutable::getLastErrors();
        if(!$parsedDate||($dateErrors!==false&&($dateErrors['warning_count']>0||$dateErrors['error_count']>0))||$parsedDate->format('Y-m-d')!==$dateValue)$$dateVariable='';
    }
}
$where=[];$params=[];$types='';
if($query!==''){$where[]='(u.full_name LIKE ? OR u.email LIKE ? OR i.asset_tag LIKE ? OR i.item_name LIKE ? OR r.purpose LIKE ?)';for($index=0;$index<5;$index++){$params[]='%'.$query.'%';$types.='s';}}
if($categoryFilter!==''){$where[]='i.category=?';$params[]=$categoryFilter;$types.='s';}
if($statusFilter!==''){$where[]='r.status=?';$params[]=$statusFilter;$types.='s';}
if($dateFrom!==''){$where[]='r.created_at>=?';$params[]=$dateFrom.' 00:00:00';$types.='s';}
if($dateTo!==''){$where[]='r.created_at<?';$params[]=(new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d').' 00:00:00';$types.='s';}
$listSql="SELECT r.*,u.full_name AS requester_name,u.email AS requester_email,i.asset_tag,i.item_name,i.category,i.location,reviewer.full_name AS reviewer_name FROM equipment_requests r JOIN users u ON u.id=r.requester_id JOIN inventory_items i ON i.id=r.inventory_item_id LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by";
if($where)$listSql.=' WHERE '.implode(' AND ',$where);
$listSql.=" ORDER BY FIELD(r.status,'pending','approved','returned','rejected','cancelled'),r.created_at DESC LIMIT 300";
$listQuery=$conn->prepare($listSql);
if($params)stmt_bind($listQuery,$types,$params);
$listQuery->execute();
$requests=$listQuery->get_result();
include __DIR__.'/includes/header.php';
?>
<div class="approval-page">
    <header class="approval-hero"><div class="approval-hero-copy"><span class="approval-hero-icon"><i class="bi bi-shield-check" aria-hidden="true"></i></span><div><h1>Approval Requests</h1><p>Review and approve equipment borrowing requests from Staff.</p></div></div><div class="approval-hero-art" aria-hidden="true"><i class="bi bi-box-seam-fill"></i><i class="bi bi-qr-code"></i><i class="bi bi-laptop"></i></div></header>
    <section class="approval-metrics" aria-label="Request totals">
        <article class="approval-metric approval-pending"><span class="approval-metric-icon"><i class="bi bi-clock-fill" aria-hidden="true"></i></span><div><span>Pending</span><strong><?=number_format($counts['pending'])?></strong></div></article>
        <article class="approval-metric approval-approved"><span class="approval-metric-icon"><i class="bi bi-check-circle-fill" aria-hidden="true"></i></span><div><span>Approved / Borrowed</span><strong><?=number_format($counts['approved'])?></strong></div></article>
        <article class="approval-metric approval-returned"><span class="approval-metric-icon"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i></span><div><span>Returned</span><strong><?=number_format($counts['returned'])?></strong></div></article>
        <article class="approval-metric approval-rejected"><span class="approval-metric-icon"><i class="bi bi-x-circle-fill" aria-hidden="true"></i></span><div><span>Rejected</span><strong><?=number_format($counts['rejected'])?></strong></div></article>
        <article class="approval-metric approval-cancelled"><span class="approval-metric-icon"><i class="bi bi-slash-circle-fill" aria-hidden="true"></i></span><div><span>Cancelled</span><strong><?=number_format($counts['cancelled'])?></strong></div></article>
    </section>
    <section class="panel approval-list-panel">
        <form class="approval-toolbar" method="get"><label class="approval-search"><i class="bi bi-search" aria-hidden="true"></i><span class="visually-hidden">Search requests</span><input name="q" value="<?=e($query)?>" class="form-control" placeholder="Search requester, equipment, purpose"></label><label><span class="visually-hidden">Filter category</span><select name="category" class="form-select"><option value="">All categories</option><?php foreach($categories as $category): ?><option value="<?=e($category)?>" <?=$categoryFilter===$category?'selected':''?>><?=e($category)?></option><?php endforeach; ?></select></label><label><span class="visually-hidden">Filter status</span><select name="status" class="form-select"><option value="">All statuses</option><?php foreach($statusValues as $statusValue): ?><option value="<?=e($statusValue)?>" <?=$statusFilter===$statusValue?'selected':''?>><?=e(ucfirst($statusValue))?></option><?php endforeach; ?></select></label><label class="approval-date-filter"><span class="visually-hidden">From date</span><input class="form-control" name="from" type="date" value="<?=e($dateFrom)?>" aria-label="From date"></label><label class="approval-date-filter"><span class="visually-hidden">To date</span><input class="form-control" name="to" type="date" value="<?=e($dateTo)?>" aria-label="To date"></label><button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button><a class="btn btn-outline-secondary" href="approval_requests.php" aria-label="Clear filters" title="Clear filters"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></a></form>
        <div class="approval-table-heading"><h2>Borrowing requests</h2><span><?=number_format($requests->num_rows)?> shown, up to 300</span></div>
        <div class="table-responsive"><table class="table table-hover align-middle approval-table"><thead><tr><th>#</th><th>Request ID</th><th>Requester</th><th>Equipment</th><th>Qty</th><th>Purpose</th><th>Request date</th><th>Expected return</th><th>Status</th><th>Actions</th></tr></thead><tbody>
        <?php if($requests->num_rows): while($request=$requests->fetch_assoc()): ?><tr><td><?=number_format((int)$request['id'])?></td><td class="inventory-tag">BR-<?=str_pad((string)$request['id'],5,'0',STR_PAD_LEFT)?></td><td><strong><?=e($request['requester_name'])?></strong><small class="d-block text-secondary"><?=e($request['requester_email'])?></small></td><td><strong><?=e($request['item_name'])?></strong><small class="d-block text-secondary"><?=e($request['asset_tag'])?> · <?=e($request['location'])?></small></td><td><?=number_format((int)$request['quantity'])?></td><td><?=e($request['purpose'])?></td><td><?=e(date('M j, Y g:i A',strtotime($request['created_at'])))?></td><td><?=e(date('M j, Y',strtotime($request['expected_return_date'])))?></td><td><span class="request-status status-<?=e($request['status'])?>"><?=e(ucfirst($request['status']))?></span><?php if($request['reviewer_name']): ?><small class="d-block text-secondary">By <?=e($request['reviewer_name'])?></small><?php endif; ?><?php if($request['review_note']): ?><small class="d-block text-secondary"><?=e($request['review_note'])?></small><?php endif; ?></td><td class="approval-actions">
            <?php if($request['status']==='pending'): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="request_id" value="<?=$request['id']?>"><button class="btn btn-sm btn-success" name="request_action" value="approve" title="Approve request"><i class="bi bi-check-circle" aria-hidden="true"></i><span>Approve</span></button></form><form method="post" class="approval-reject-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="request_id" value="<?=$request['id']?>"><button class="btn btn-sm btn-danger" name="request_action" value="reject" title="Reject request"><i class="bi bi-x-circle" aria-hidden="true"></i><span>Reject</span></button></form>
            <?php elseif($request['status']==='approved'): ?><form method="post" onsubmit="return confirm('Record this equipment as returned?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="request_id" value="<?=$request['id']?>"><button class="btn btn-sm btn-outline-primary" name="request_action" value="return" title="Mark returned"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i><span>Returned</span></button></form><?php else: ?><span class="approval-no-action">—</span><?php endif; ?>
        </td></tr><?php endwhile; else: ?><tr><td colspan="10" class="text-center py-5"><strong>No borrowing requests found</strong><br><span class="muted">New Staff requests will appear here.</span></td></tr><?php endif; ?>
        </tbody></table></div>
        <div class="approval-note"><i class="bi bi-info-circle-fill" aria-hidden="true"></i><span><strong>Approval note:</strong> Approval reserves the requested units. Mark the request Returned when the equipment is received back to restore its availability.</span></div>
    </section>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>