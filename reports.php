<?php
require_once __DIR__.'/staff/functions.php';
require_role(['admin','staff']);
ensure_staff_equipment_transactions_table($conn);

$statuses=['Available','In Use','Under Repair','Disposed'];
$parseDate=static function(string $value):?string{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$value))return null;
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
    $errors=DateTimeImmutable::getLastErrors();
    return $date&&($errors===false||($errors['warning_count']===0&&$errors['error_count']===0))&&$date->format('Y-m-d')===$value?$value:null;
};
$from=$parseDate(trim($_GET['from']??''))??date('Y-m-01');
$to=$parseDate(trim($_GET['to']??''))??date('Y-m-d');
if($from>$to){[$from,$to]=[$to,$from];}
$categoryRows=$conn->query('SELECT DISTINCT category FROM inventory_items ORDER BY category');
$categories=[];
while($categoryRow=$categoryRows->fetch_assoc())$categories[]=$categoryRow['category'];
$categoryFilter=in_array($_GET['category']??'', $categories, true)?$_GET['category']:'';
$statusFilter=in_array($_GET['status']??'', $statuses, true)?$_GET['status']:'';
$where=['DATE(i.updated_at)>=?','DATE(i.updated_at)<=?'];
$inventoryParams=[$from,$to];
$inventoryTypes='ss';
if($categoryFilter!==''){$where[]='i.category=?';$inventoryParams[]=$categoryFilter;$inventoryTypes.='s';}
if($statusFilter!==''){$where[]='i.status=?';$inventoryParams[]=$statusFilter;$inventoryTypes.='s';}
$whereSql=implode(' AND ',$where);
$borrowedSubquery=staff_borrowed_units_subquery();
$borrowedJoin="LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id";
$inventorySelect="SELECT i.id,i.asset_tag,i.item_name,i.category,i.quantity,i.unit,i.location,i.condition_status,i.status,i.updated_at,CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN GREATEST(i.quantity-COALESCE(b.borrowed,0),0) ELSE 0 END AS available_units,(SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=i.id AND h.action='completed' ORDER BY h.id DESC LIMIT 1) AS last_repaired_by FROM inventory_items i $borrowedJoin WHERE $whereSql";
$inventoryQuery=$conn->prepare($inventorySelect.' ORDER BY i.updated_at DESC,i.id DESC LIMIT 500');
stmt_bind($inventoryQuery,$inventoryTypes,$inventoryParams);
$inventoryQuery->execute();
$inventoryRows=$inventoryQuery->get_result();

$summaryQuery=$conn->prepare("SELECT COUNT(i.id) AS records,COALESCE(SUM(i.quantity),0) AS total_units,COALESCE(SUM(CASE WHEN i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') THEN GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) ELSE 0 END),0) AS available_units,COALESCE(SUM(GREATEST(COALESCE(b.borrowed,0),0)),0) AS in_use_units,COALESCE(SUM(CASE WHEN i.status<>'Disposed' AND i.condition_status='Damaged' THEN GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) ELSE 0 END),0) AS damaged_units,COALESCE(SUM(CASE WHEN i.status<>'Disposed' AND i.condition_status<>'Damaged' AND (i.status='Under Repair' OR i.condition_status='Needs Repair') THEN GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) ELSE 0 END),0) AS maintenance_units FROM inventory_items i $borrowedJoin WHERE $whereSql");
stmt_bind($summaryQuery,$inventoryTypes,$inventoryParams);
$summaryQuery->execute();
$summary=$summaryQuery->get_result()->fetch_assoc();

$categoryQuery=$conn->prepare("SELECT i.category,SUM(i.quantity) AS units FROM inventory_items i WHERE $whereSql GROUP BY i.category ORDER BY units DESC LIMIT 8");
stmt_bind($categoryQuery,$inventoryTypes,$inventoryParams);
$categoryQuery->execute();
$categoryReport=$categoryQuery->get_result();
$categoryLabels=[];$categoryValues=[];
while($category=$categoryReport->fetch_assoc()){$categoryLabels[]=$category['category'];$categoryValues[]=(int)$category['units'];}

$requestCounts=['pending'=>0,'approved'=>0,'returned'=>0,'rejected'=>0,'cancelled'=>0];
$requestCountQuery=$conn->prepare('SELECT status,COUNT(*) AS total FROM equipment_requests WHERE DATE(created_at)>=? AND DATE(created_at)<=? GROUP BY status');
$requestCountQuery->bind_param('ss',$from,$to);
$requestCountQuery->execute();
$requestCountRows=$requestCountQuery->get_result();
while($countRow=$requestCountRows->fetch_assoc())$requestCounts[$countRow['status']]=(int)$countRow['total'];
$requestQuery=$conn->prepare('SELECT r.id,r.quantity,r.purpose,r.expected_return_date,r.status,r.created_at,u.full_name AS requester_name,i.asset_tag,i.item_name,i.category,reviewer.full_name AS reviewer_name FROM equipment_requests r JOIN users u ON u.id=r.requester_id JOIN inventory_items i ON i.id=r.inventory_item_id LEFT JOIN users reviewer ON reviewer.id=r.reviewed_by WHERE DATE(r.created_at)>=? AND DATE(r.created_at)<=? ORDER BY r.created_at DESC,r.id DESC LIMIT 200');
$requestQuery->bind_param('ss',$from,$to);
$requestQuery->execute();
$requestRows=$requestQuery->get_result();

$transactionTypeOptions=['released','returned','damaged_reported'];
$transactionTypeFilter=in_array($_GET['transaction_type']??'', $transactionTypeOptions, true)?$_GET['transaction_type']:'';
$transactionConditions=['DATE(t.created_at)>=?','DATE(t.created_at)<=?'];
$transactionParams=[$from,$to];
$transactionTypes='ss';
if($categoryFilter!==''){$transactionConditions[]='i.category=?';$transactionParams[]=$categoryFilter;$transactionTypes.='s';}
if($transactionTypeFilter!==''){$transactionConditions[]='t.action=?';$transactionParams[]=$transactionTypeFilter;$transactionTypes.='s';}
$transactionWhere=implode(' AND ',$transactionConditions);
$transactionSummaryQuery=$conn->prepare("SELECT COALESCE(SUM(CASE WHEN t.action='released' THEN t.quantity ELSE 0 END),0) AS released_units,COALESCE(SUM(CASE WHEN t.action='returned' THEN t.quantity ELSE 0 END),0) AS returned_units,COALESCE(SUM(CASE WHEN t.action='damaged_reported' THEN t.quantity ELSE 0 END),0) AS reported_damaged_units,COUNT(*) AS total_transactions FROM equipment_transactions t JOIN inventory_items i ON i.id=t.inventory_item_id WHERE $transactionWhere");
stmt_bind($transactionSummaryQuery,$transactionTypes,$transactionParams);
$transactionSummaryQuery->execute();
$transactionSummary=$transactionSummaryQuery->get_result()->fetch_assoc();
$transactionQuery=$conn->prepare("SELECT t.action,t.quantity,t.condition_status,t.details,t.created_at,u.full_name AS staff_name,borrower.full_name AS borrower_name,i.asset_tag,i.item_name,i.category FROM equipment_transactions t JOIN users u ON u.id=t.user_id JOIN inventory_items i ON i.id=t.inventory_item_id LEFT JOIN equipment_requests r ON r.id=t.request_id LEFT JOIN users borrower ON borrower.id=r.requester_id WHERE $transactionWhere ORDER BY t.created_at DESC,t.id DESC LIMIT 300");
stmt_bind($transactionQuery,$transactionTypes,$transactionParams);
$transactionQuery->execute();
$transactionRows=$transactionQuery->get_result();
$overdueQuery=$conn->prepare("SELECT COALESCE(SUM(GREATEST(released.quantity-COALESCE(returned.quantity,0),0)),0) FROM equipment_transactions released JOIN equipment_requests r ON r.id=released.request_id JOIN inventory_items i ON i.id=released.inventory_item_id LEFT JOIN equipment_transactions returned ON returned.request_id=r.id AND returned.action='returned' WHERE released.action='released' AND r.expected_return_date<CURDATE() AND DATE(released.created_at)>=? AND DATE(released.created_at)<=?".($categoryFilter!==''?' AND i.category=?':''));
if($categoryFilter!=='')$overdueQuery->bind_param('sss',$from,$to,$categoryFilter);else$overdueQuery->bind_param('ss',$from,$to);
$overdueQuery->execute();
$overdueUnits=(int)$overdueQuery->get_result()->fetch_row()[0];
$activityQuery=$conn->prepare('SELECT l.action,l.details,l.created_at,u.full_name,u.role FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE DATE(l.created_at)>=? AND DATE(l.created_at)<=? ORDER BY l.id DESC LIMIT 300');
$activityQuery->bind_param('ss',$from,$to);
$activityQuery->execute();
$activityRows=$activityQuery->get_result();

$export=$_GET['export']??'';
$csvCell=static function($value):string{$value=(string)$value;return preg_match('/^\s*[=+\-@]/u',$value)?"'".$value:$value;};
if(in_array($export,['inventory','borrowing','transactions'],true)){
    $filename=match($export){'inventory'=>'ccst-inventory-report.csv','borrowing'=>'ccst-borrowing-report.csv',default=>'ccst-transactions-report.csv'};
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    $output=fopen('php://output','w');
    fwrite($output,"\xEF\xBB\xBF");
    if($export==='inventory'){
        fputcsv($output,['Asset Tag','Equipment','Category','Quantity','Available Units','Location','Condition','Status','Last Repaired By','Last Updated']);
        while($row=$inventoryRows->fetch_assoc())fputcsv($output,array_map($csvCell,[$row['asset_tag'],$row['item_name'],$row['category'],$row['quantity'].' '.$row['unit'],$row['available_units'],$row['location'],$row['condition_status'],$row['status'],$row['last_repaired_by']??'',$row['updated_at']]));
    }elseif($export==='borrowing'){
        fputcsv($output,['Request ID','Requester','Asset Tag','Equipment','Category','Quantity','Purpose','Request Date','Expected Return','Status','Reviewed By']);
        while($row=$requestRows->fetch_assoc())fputcsv($output,array_map($csvCell,['BR-'.str_pad((string)$row['id'],5,'0',STR_PAD_LEFT),$row['requester_name'],$row['asset_tag'],$row['item_name'],$row['category'],$row['quantity'],$row['purpose'],$row['created_at'],$row['expected_return_date'],$row['status'],$row['reviewer_name']??'']));
    }else{
        fputcsv($output,['Transaction','Equipment','Asset Tag','Category','Quantity','Borrower','Staff','Condition','Details','Date and Time']);
        while($row=$transactionRows->fetch_assoc())fputcsv($output,array_map($csvCell,[$row['action'],$row['item_name'],$row['asset_tag'],$row['category'],$row['quantity'],$row['borrower_name']??'',$row['staff_name'],$row['condition_status']??'',$row['details']??'',$row['created_at']]));
    }
    fclose($output);
    exit;
}
$inventoryExportUrl='reports.php?'.http_build_query(['from'=>$from,'to'=>$to,'category'=>$categoryFilter,'status'=>$statusFilter,'export'=>'inventory']);
$borrowingExportUrl='reports.php?'.http_build_query(['from'=>$from,'to'=>$to,'export'=>'borrowing']);
$transactionExportUrl='reports.php?'.http_build_query(['from'=>$from,'to'=>$to,'category'=>$categoryFilter,'transaction_type'=>$transactionTypeFilter,'export'=>'transactions']);
include __DIR__.'/includes/header.php';
?>
<div class="reports-page">
    <header class="reports-page-heading"><div><p class="dashboard-eyebrow">CCST FACILITIES</p><h1>Inventory Reports</h1><p class="dashboard-subtitle">Equipment status, availability, repair, and borrowing activity.</p></div><div class="reports-heading-actions"><button class="btn btn-outline-secondary no-print" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print</button><a class="btn btn-primary no-print" href="<?=e($inventoryExportUrl)?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a></div></header>
    <section class="panel reports-filter-panel no-print"><form class="reports-filter-form" method="get"><label><span>From</span><input class="form-control" type="date" name="from" value="<?=e($from)?>" required></label><label><span>To</span><input class="form-control" type="date" name="to" value="<?=e($to)?>" required></label><label><span>Category</span><select class="form-select" name="category"><option value="">All categories</option><?php foreach($categories as $category): ?><option value="<?=e($category)?>" <?=$categoryFilter===$category?'selected':''?>><?=e($category)?></option><?php endforeach; ?></select></label><label><span>Asset status</span><select class="form-select" name="status"><option value="">All statuses</option><?php foreach($statuses as $status): ?><option value="<?=e($status)?>" <?=$statusFilter===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select></label><label><span>Transaction type</span><select class="form-select" name="transaction_type"><option value="">All transactions</option><?php foreach($transactionTypeOptions as $transactionType): ?><option value="<?=e($transactionType)?>" <?=$transactionTypeFilter===$transactionType?'selected':''?>><?=e(ucwords(str_replace('_',' ',$transactionType)))?></option><?php endforeach; ?></select></label><button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button><a class="btn btn-outline-secondary" href="reports.php">Reset</a></form></section>
    <section class="reports-metric-grid" aria-label="Inventory report totals"><article><span class="report-metric-icon report-blue"><i class="bi bi-box-seam" aria-hidden="true"></i></span><div><span>Asset records</span><strong><?=number_format((int)$summary['records'])?></strong></div></article><article><span class="report-metric-icon report-purple"><i class="bi bi-stack" aria-hidden="true"></i></span><div><span>Total units</span><strong><?=number_format((int)$summary['total_units'])?></strong></div></article><article><span class="report-metric-icon report-green"><i class="bi bi-check-circle" aria-hidden="true"></i></span><div><span>Available</span><strong><?=number_format((int)$summary['available_units'])?></strong></div></article><article><span class="report-metric-icon report-amber"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><div><span>In use / borrowed</span><strong><?=number_format((int)$summary['in_use_units'])?></strong></div></article><article><span class="report-metric-icon report-red"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><div><span>Under maintenance</span><strong><?=number_format((int)$summary['maintenance_units'])?></strong></div></article></section>
    <section class="reports-metric-grid reports-inventory-metrics" aria-label="Inventory report totals"><article><span class="report-metric-icon report-blue"><i class="bi bi-box-seam" aria-hidden="true"></i></span><div><span>Asset records</span><strong><?=number_format((int)$summary['records'])?></strong></div></article><article><span class="report-metric-icon report-purple"><i class="bi bi-stack" aria-hidden="true"></i></span><div><span>Total units</span><strong><?=number_format((int)$summary['total_units'])?></strong></div></article><article><span class="report-metric-icon report-green"><i class="bi bi-check-circle" aria-hidden="true"></i></span><div><span>Available</span><strong><?=number_format((int)$summary['available_units'])?></strong></div></article><article><span class="report-metric-icon report-amber"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></span><div><span>In use / released</span><strong><?=number_format((int)$summary['in_use_units'])?></strong></div></article><article><span class="report-metric-icon report-red"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i></span><div><span>Under maintenance</span><strong><?=number_format((int)$summary['maintenance_units'])?></strong></div></article><article><span class="report-metric-icon report-red"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i></span><div><span>Damaged units</span><strong><?=number_format((int)$summary['damaged_units'])?></strong></div></article></section>
    <section class="reports-metric-grid reports-transaction-metrics" aria-label="Transaction report totals"><article><span class="report-metric-icon report-blue"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></span><div><span>Released units</span><strong><?=number_format((int)$transactionSummary['released_units'])?></strong></div></article><article><span class="report-metric-icon report-green"><i class="bi bi-box-arrow-in-left" aria-hidden="true"></i></span><div><span>Returned units</span><strong><?=number_format((int)$transactionSummary['returned_units'])?></strong></div></article><article><span class="report-metric-icon report-red"><i class="bi bi-clock-history" aria-hidden="true"></i></span><div><span>Overdue units</span><strong><?=number_format($overdueUnits)?></strong></div></article><article><span class="report-metric-icon report-amber"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i></span><div><span>Damage reports</span><strong><?=number_format((int)$transactionSummary['reported_damaged_units'])?></strong></div></article></section>
    <section class="reports-grid">
        <article class="panel report-chart-panel"><div class="report-section-heading"><div><h2>Units by category</h2><p><?=e(date('M j, Y',strtotime($from)))?> – <?=e(date('M j, Y',strtotime($to)))?></p></div></div><?php if($categoryLabels): ?><div class="report-category-chart"><canvas id="report-category-chart" aria-label="Inventory units by category"></canvas></div><?php else: ?><div class="report-empty-state">No inventory records match these filters.</div><?php endif; ?></article>
        <article class="panel report-borrowing-summary"><div class="report-section-heading"><div><h2>Borrowing activity</h2><p>Requests created during this period</p></div><a href="<?=e($borrowingExportUrl)?>" class="btn btn-sm btn-outline-primary no-print" title="Export borrowing report" aria-label="Export borrowing report"><i class="bi bi-download" aria-hidden="true"></i></a></div><div class="report-request-counts"><div><span class="request-status status-pending">Pending</span><strong><?=number_format($requestCounts['pending'])?></strong></div><div><span class="request-status status-approved">Approved</span><strong><?=number_format($requestCounts['approved'])?></strong></div><div><span class="request-status status-returned">Returned</span><strong><?=number_format($requestCounts['returned'])?></strong></div><div><span class="request-status status-rejected">Rejected</span><strong><?=number_format($requestCounts['rejected'])?></strong></div></div></article>
    </section>
    <section class="panel reports-table-panel"><div class="report-section-heading"><div><h2>Equipment detail</h2><p><?=number_format($inventoryRows->num_rows)?> records shown, up to 500</p></div><a class="btn btn-sm btn-outline-primary no-print" href="<?=e($inventoryExportUrl)?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a></div><div class="table-responsive"><table class="table table-hover align-middle reports-table"><thead><tr><th>Asset tag</th><th>Equipment</th><th>Category</th><th>Qty</th><th>Available</th><th>Location</th><th>Condition</th><th>Status</th><th>Last repaired by</th><th>Updated</th></tr></thead><tbody>
        <?php if($inventoryRows->num_rows): while($row=$inventoryRows->fetch_assoc()): ?><tr><td class="inventory-tag"><?=e($row['asset_tag'])?></td><td><strong><?=e($row['item_name'])?></strong></td><td><?=e($row['category'])?></td><td><?=number_format((int)$row['quantity'])?> <?=e($row['unit'])?></td><td><?=number_format((int)$row['available_units'])?></td><td><?=e($row['location'])?></td><td><?=e($row['condition_status'])?></td><td><span class="inventory-status"><?=e($row['status'])?></span></td><td><?=e($row['last_repaired_by']??'—')?></td><td><?=e(date('M j, Y',strtotime($row['updated_at'])))?></td></tr><?php endwhile; else: ?><tr><td colspan="10" class="text-center py-5"><strong>No equipment found for this report.</strong></td></tr><?php endif; ?>
        </tbody></table></div></section>
    <section class="panel reports-table-panel"><div class="report-section-heading"><div><h2>Borrowing detail</h2><p><?=number_format($requestRows->num_rows)?> requests shown, up to 200</p></div><a class="btn btn-sm btn-outline-primary no-print" href="<?=e($borrowingExportUrl)?>"><i class="bi bi-download" aria-hidden="true"></i> Export CSV</a></div><div class="table-responsive"><table class="table table-hover align-middle reports-table borrowing-report-table"><thead><tr><th>Request ID</th><th>Requester</th><th>Equipment</th><th>Category</th><th>Qty</th><th>Purpose</th><th>Request date</th><th>Expected return</th><th>Status</th><th>Reviewed by</th></tr></thead><tbody>
        <?php if($requestRows->num_rows): while($row=$requestRows->fetch_assoc()): ?><tr><td class="inventory-tag">BR-<?=str_pad((string)$row['id'],5,'0',STR_PAD_LEFT)?></td><td><?=e($row['requester_name'])?></td><td><?=e($row['item_name'])?><small class="d-block text-secondary"><?=e($row['asset_tag'])?></small></td><td><?=e($row['category'])?></td><td><?=number_format((int)$row['quantity'])?></td><td><?=e($row['purpose'])?></td><td><?=e(date('M j, Y',strtotime($row['created_at'])))?></td><td><?=e(date('M j, Y',strtotime($row['expected_return_date'])))?></td><td><span class="request-status status-<?=e($row['status'])?>"><?=e(ucfirst($row['status']))?></span></td><td><?=e($row['reviewer_name']??'—')?></td></tr><?php endwhile; else: ?><tr><td colspan="10" class="text-center py-5"><strong>No borrowing requests found for this period.</strong></td></tr><?php endif; ?>
        </tbody></table></div></section>
    <section class="panel reports-table-panel"><div class="report-section-heading"><div><h2>Equipment transactions</h2><p><?=number_format($transactionRows->num_rows)?> events shown, up to 300</p></div><a class="btn btn-sm btn-outline-primary no-print" href="<?=e($transactionExportUrl)?>"><i class="bi bi-download" aria-hidden="true"></i> Export transactions</a></div><div class="table-responsive"><table class="table table-hover align-middle reports-table borrowing-report-table"><thead><tr><th>Date and time</th><th>Transaction</th><th>Equipment</th><th>Category</th><th>Quantity</th><th>Borrower</th><th>Staff</th><th>Condition / details</th></tr></thead><tbody>
        <?php if($transactionRows->num_rows): while($row=$transactionRows->fetch_assoc()): ?><tr><td><?=e(date('M j, Y g:i A',strtotime($row['created_at'])))?></td><td><?=e(match($row['action']){'released'=>'Released','returned'=>'Returned','damaged_reported'=>'Damaged reported',default=>'Updated'})?></td><td><strong><?=e($row['item_name'])?></strong><small class="d-block text-secondary"><?=e($row['asset_tag'])?></small></td><td><?=e($row['category'])?></td><td><?=number_format((int)$row['quantity'])?></td><td><?=e($row['borrower_name']??'—')?></td><td><?=e($row['staff_name'])?></td><td><?=e(trim(($row['condition_status']??'').' '.($row['details']??''))?:'—')?></td></tr><?php endwhile; else: ?><tr><td colspan="8" class="text-center py-5"><strong>No equipment transactions found for this period.</strong></td></tr><?php endif; ?>
        </tbody></table></div></section>
    <section class="panel reports-table-panel"><div class="report-section-heading"><div><h2>User activity</h2><p><?=number_format($activityRows->num_rows)?> events shown, up to 300</p></div></div><div class="table-responsive"><table class="table table-hover align-middle reports-table"><thead><tr><th>Date and time</th><th>User</th><th>Role</th><th>Action</th><th>Description</th></tr></thead><tbody>
        <?php if($activityRows->num_rows): while($row=$activityRows->fetch_assoc()): ?><tr><td><?=e(date('M j, Y g:i A',strtotime($row['created_at'])))?></td><td><?=e($row['full_name']??'Deleted user')?></td><td><?=e(ucfirst($row['role']??'Unknown'))?></td><td><?=e($row['action'])?></td><td class="text-break"><?=e($row['details']?:'—')?></td></tr><?php endwhile; else: ?><tr><td colspan="5" class="text-center py-5"><strong>No user activity found for this period.</strong></td></tr><?php endif; ?>
        </tbody></table></div></section>
</div>
<?php if($categoryLabels): ?><script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script><script>new Chart(document.getElementById('report-category-chart'),{type:'bar',data:{labels:<?=json_encode($categoryLabels)?>,datasets:[{label:'Units',data:<?=json_encode($categoryValues)?>,backgroundColor:['#1674d1','#22a36a','#f0a11a','#7f67d8','#31a5bb','#d85a61','#4c8fbb','#7cae56'],borderRadius:4,maxBarThickness:34}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0},grid:{color:'#e6edf7'}},y:{grid:{display:false}}}}});</script><?php endif; ?>
<?php include __DIR__.'/includes/footer.php'; ?>