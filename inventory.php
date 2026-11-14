<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin','staff']);
ensure_inventory_table($conn);
$isAdmin=current_user()['role']==='admin';
$configuredCategories=json_decode(get_system_setting($conn,'equipment_categories',''),true);
$configuredCategories=is_array($configuredCategories)?array_filter($configuredCategories,'is_string'):[];
$categoryRows=$conn->query('SELECT DISTINCT category FROM inventory_items ORDER BY category');
$categoryOptions=$configuredCategories;
while($categoryRow=$categoryRows->fetch_assoc())$categoryOptions[]=$categoryRow['category'];
$categoryOptions=array_values(array_unique($categoryOptions));

$conditions=['New','Good','Needs Repair','Damaged'];
$statuses=['Available','In Use','Under Repair','Disposed'];
$edit=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$isAdmin){http_response_code(403);exit('Only Admin can modify inventory records.');}
    csrf_check();
    $action=$_POST['action']??'save';
    $id=(int)($_POST['id']??0);
    if($action==='delete'){
        $stmt=$conn->prepare('DELETE FROM inventory_items WHERE id=?');
        $stmt->bind_param('i',$id);
        $stmt->execute();
        log_action('DELETE INVENTORY ITEM','Item #'.$id);
        flash('Equipment record deleted.');
        redirect('inventory.php');
    }

    $itemName=trim($_POST['item_name']??'');
    $category=trim($_POST['category']??'');
    $description=trim($_POST['description']??'');
    $quantity=max(1,(int)($_POST['quantity']??1));
    $unit=trim($_POST['unit']??'piece');
    $location=trim($_POST['location']??'');
    $custodian=trim($_POST['custodian']??'');
    $condition=in_array($_POST['condition_status']??'', $conditions, true)?$_POST['condition_status']:'Good';
    $status=in_array($_POST['status']??'', $statuses, true)?$_POST['status']:'Available';
    $acquisitionDate=trim($_POST['acquisition_date']??'');
    $costRaw=trim($_POST['acquisition_cost']??'');
    $cost=$costRaw===''?null:(float)$costRaw;

    if($itemName===''||$category===''||$unit===''||$location===''){
        flash('Complete the equipment name, category, unit, and location.','error');
        redirect($id?'inventory.php?edit='.$id:'inventory.php');
    }
    $description=$description===''?null:$description;
    $custodian=$custodian===''?null:$custodian;
    $acquisitionDate=$acquisitionDate===''?null:$acquisitionDate;
    if($id){
        $stmt=$conn->prepare('UPDATE inventory_items SET item_name=?,category=?,description=?,quantity=?,unit=?,location=?,custodian=?,condition_status=?,status=?,acquisition_date=?,acquisition_cost=? WHERE id=?');
        $stmt->bind_param('sssissssssdi',$itemName,$category,$description,$quantity,$unit,$location,$custodian,$condition,$status,$acquisitionDate,$cost,$id);
    }else{
        $year=date('Y');
        $prefix='CCST-INV-'.$year.'-';
        $tagQuery=$conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(asset_tag,'-',-1) AS UNSIGNED)),0)+1 AS next_num FROM inventory_items WHERE asset_tag LIKE CONCAT(?, '%')");
        $tagQuery->bind_param('s',$prefix);
        $tagQuery->execute();
        $next=(int)$tagQuery->get_result()->fetch_assoc()['next_num'];
        $assetTag=$prefix.str_pad((string)$next,4,'0',STR_PAD_LEFT);
        $stmt=$conn->prepare('INSERT INTO inventory_items(asset_tag,item_name,category,description,quantity,unit,location,custodian,condition_status,status,acquisition_date,acquisition_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('ssssissssssd',$assetTag,$itemName,$category,$description,$quantity,$unit,$location,$custodian,$condition,$status,$acquisitionDate,$cost);
    }
    $stmt->execute();
    log_action($id?'UPDATE INVENTORY ITEM':'ADD INVENTORY ITEM',$itemName);
    flash($id?'Equipment record updated.':'Equipment added with QR asset tag '.$assetTag.'.');
    redirect('inventory.php');
}

if(isset($_GET['edit'])){
    if(!$isAdmin){http_response_code(403);exit('Only Admin can edit inventory records.');}
    $id=(int)$_GET['edit'];
    $stmt=$conn->prepare('SELECT * FROM inventory_items WHERE id=?');
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $edit=$stmt->get_result()->fetch_assoc();
    if(!$edit){flash('Equipment record not found.','error');redirect('inventory.php');}
}

if(isset($_GET['qr'])){
    if(!$isAdmin){http_response_code(403);exit('Only Admin can generate equipment QR labels.');}
    $id=(int)$_GET['qr'];
    $stmt=$conn->prepare('SELECT * FROM inventory_items WHERE id=?');
    $stmt->bind_param('i',$id);
    $stmt->execute();
    $qrItem=$stmt->get_result()->fetch_assoc();
    if(!$qrItem){flash('Equipment record not found.','error');redirect('inventory.php');}
    log_action('GENERATE EQUIPMENT QR',$qrItem['asset_tag']);
    include __DIR__.'/includes/header.php';
    ?>
    <section class="qr-label-page">
        <div class="qr-label-tools no-print"><a class="btn btn-outline-secondary" href="inventory.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a><button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print label</button></div>
        <article class="qr-label"><p class="qr-label-brand">CCST · SCHOOL PROPERTY</p><div id="asset-qr" class="qr-label-code" aria-label="QR code for <?=e($qrItem['asset_tag'])?>"></div><h1><?=e($qrItem['item_name'])?></h1><p class="inventory-tag"><?=e($qrItem['asset_tag'])?></p><p><?=e($qrItem['location'])?> · <?=e($qrItem['category'])?></p></article>
    </section>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>new QRCode(document.getElementById('asset-qr'),{text:<?=json_encode($qrItem['asset_tag'])?>,width:220,height:220,correctLevel:QRCode.CorrectLevel.M});</script>
    <?php include __DIR__.'/includes/footer.php';exit;
}

if(isset($_GET['room_qr'])){
    if(!$isAdmin){http_response_code(403);exit('Only Admin can generate room QR labels.');}
    $roomLocation=trim($_GET['room_qr']);
    $roomCheck=$conn->prepare("SELECT COUNT(*) AS item_count FROM inventory_items WHERE location=? AND status<>'Disposed'");
    $roomCheck->bind_param('s',$roomLocation);
    $roomCheck->execute();
    if($roomLocation===''||(int)$roomCheck->get_result()->fetch_assoc()['item_count']===0){flash('No active equipment was found in that room.','error');redirect('inventory.php');}
    log_action('GENERATE ROOM QR LABEL',$roomLocation);
    include __DIR__.'/includes/header.php';
    ?>
    <section class="qr-label-page">
        <div class="qr-label-tools no-print"><a class="btn btn-outline-secondary" href="inventory.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back</a><button class="btn btn-primary" type="button" onclick="window.print()"><i class="bi bi-printer" aria-hidden="true"></i> Print room label</button></div>
        <article class="qr-label"><p class="qr-label-brand">CCST · MAINTENANCE ROOM</p><div id="asset-qr" class="qr-label-code" aria-label="QR code for room <?=e($roomLocation)?>"></div><h1><?=e($roomLocation)?></h1><p>Scan to view repair items in this room</p></article>
    </section>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script><script>new QRCode(document.getElementById('asset-qr'),{text:<?=json_encode('CCST-ROOM:'.$roomLocation)?>,width:220,height:220,correctLevel:QRCode.CorrectLevel.M});</script>
    <?php include __DIR__.'/includes/footer.php';exit;
}

$query=trim($_GET['q']??'');
$statusFilter=in_array($_GET['status']??'', $statuses, true)?$_GET['status']:'';
$sql="SELECT i.*,(SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=i.id AND h.action='completed' ORDER BY h.id DESC LIMIT 1) AS last_repaired_by FROM inventory_items i";
$where=[];$params=[];$types='';
if($query!==''){$where[]='(asset_tag LIKE ? OR item_name LIKE ? OR category LIKE ? OR location LIKE ? OR custodian LIKE ?)';for($i=0;$i<5;$i++){$params[]='%'.$query.'%';$types.='s';}}
if($statusFilter!==''){$where[]='status=?';$params[]=$statusFilter;$types.='s';}
if($where)$sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY updated_at DESC,id DESC LIMIT 300';
$stmt=$conn->prepare($sql);
if($params)stmt_bind($stmt,$types,$params);
$stmt->execute();
$items=$stmt->get_result();
include __DIR__.'/includes/header.php';
?>
<div class="inventory-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">ASSET REGISTER</p><h1>Equipment inventory</h1><p class="dashboard-subtitle">Tag and keep track of school-owned equipment.</p></div><?php if($isAdmin): ?><a class="btn btn-primary" href="inventory.php#item-form"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add equipment</a><?php endif; ?></header>
    <?php if($isAdmin): ?>
    <section class="panel inventory-form-panel" id="item-form">
        <div class="inventory-form-heading"><div><h2><?=$edit?'Edit equipment':'Add equipment'?></h2><p><?=$edit?'Asset tags stay fixed so existing QR labels remain valid.':'A unique asset tag and QR label are created automatically.'?></p></div><?php if($edit): ?><a class="btn btn-sm btn-outline-secondary" href="inventory.php">Cancel</a><?php endif; ?></div>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
            <div class="col-md-4"><label class="form-label" for="item_name">Equipment name</label><input class="form-control" id="item_name" name="item_name" value="<?=e($edit['item_name']??'')?>" placeholder="Desktop computer" required></div>
            <div class="col-md-4"><label class="form-label" for="category">Category</label><input class="form-control" id="category" name="category" list="category-options" value="<?=e($edit['category']??'')?>" placeholder="Computer equipment" required><datalist id="category-options"><?php foreach($categoryOptions as $categoryOption): ?><option value="<?=e($categoryOption)?>"><?php endforeach; ?></datalist></div>
            <div class="col-md-4"><label class="form-label" for="location">Location / room</label><input class="form-control" id="location" name="location" value="<?=e($edit['location']??'')?>" placeholder="Computer Lab 1" required></div>
            <div class="col-md-3"><label class="form-label" for="quantity">Quantity</label><input class="form-control" id="quantity" name="quantity" type="number" min="1" value="<?=e($edit['quantity']??1)?>" required></div>
            <div class="col-md-3"><label class="form-label" for="unit">Unit</label><input class="form-control" id="unit" name="unit" value="<?=e($edit['unit']??'piece')?>" placeholder="piece" required></div>
            <div class="col-md-3"><label class="form-label" for="condition_status">Condition</label><select class="form-select" id="condition_status" name="condition_status"><?php foreach($conditions as $condition): ?><option <?=($edit['condition_status']??'Good')===$condition?'selected':''?>><?=e($condition)?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?php foreach($statuses as $status): ?><option <?=($edit['status']??'Available')===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="custodian">Assigned to (optional)</label><input class="form-control" id="custodian" name="custodian" value="<?=e($edit['custodian']??'')?>" placeholder="Office or responsible staff"></div>
            <div class="col-md-4"><label class="form-label" for="acquisition_date">Date acquired</label><input class="form-control" id="acquisition_date" name="acquisition_date" type="date" value="<?=e($edit['acquisition_date']??'')?>"></div>
            <div class="col-md-4"><label class="form-label" for="acquisition_cost">Acquisition cost</label><input class="form-control" id="acquisition_cost" name="acquisition_cost" type="number" min="0" step="0.01" value="<?=e($edit['acquisition_cost']??'')?>" placeholder="0.00"></div>
            <div class="col-12"><label class="form-label" for="description">Notes</label><textarea class="form-control" id="description" name="description" rows="2" placeholder="Model, serial number, or other details"><?=e($edit['description']??'')?></textarea></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> <?=$edit?'Save changes':'Save equipment'?></button></div>
        </form>
    </section>
    <?php endif; ?>
    <section class="panel inventory-list-panel" id="inventory-list">
        <div class="inventory-list-heading"><div><h2>All equipment</h2><p><?=number_format($items->num_rows)?> records shown, up to 300</p></div><form class="inventory-filter" method="get"><label class="visually-hidden" for="inventory-search">Search equipment</label><input class="form-control" id="inventory-search" name="q" value="<?=e($query)?>" placeholder="Search tag, name, category, room"><label class="visually-hidden" for="inventory-status">Filter by status</label><select class="form-select" id="inventory-status" name="status"><option value="">All statuses</option><?php foreach($statuses as $status): ?><option value="<?=e($status)?>" <?=$statusFilter===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary" title="Apply filters" aria-label="Apply filters"><i class="bi bi-search" aria-hidden="true"></i></button></form></div>
        <div class="table-responsive"><table class="table table-hover align-middle inventory-table"><thead><tr><th>Asset tag</th><th>Equipment</th><th>Category</th><th>Qty</th><th>Location</th><th>Condition</th><th>Status</th><th>Last repaired by</th><th aria-label="Actions"></th></tr></thead><tbody>
        <?php if($items->num_rows): while($item=$items->fetch_assoc()): ?><tr><td class="inventory-tag"><?=e($item['asset_tag'])?></td><td><strong><?=e($item['item_name'])?></strong><?php if($item['custodian']): ?><small class="d-block text-secondary">Assigned: <?=e($item['custodian'])?></small><?php endif; ?></td><td><?=e($item['category'])?></td><td><?=number_format((int)$item['quantity'])?> <?=e($item['unit'])?></td><td><?=e($item['location'])?> <a class="room-qr-link" href="inventory.php?room_qr=<?=e(rawurlencode($item['location']))?>" title="Print QR label for <?=e($item['location'])?>" aria-label="Print room QR label for <?=e($item['location'])?>"><i class="bi bi-qr-code" aria-hidden="true"></i></a></td><td><?=e($item['condition_status'])?></td><td><span class="inventory-status"><?=e($item['status'])?></span></td><td><?=e($item['last_repaired_by']??'—')?></td><td class="inventory-actions"><a class="btn btn-sm btn-outline-secondary" href="maintenance_history.php?id=<?=$item['id']?>" title="View maintenance history" aria-label="View maintenance history for <?=e($item['asset_tag'])?>"><i class="bi bi-clock-history" aria-hidden="true"></i></a><a class="btn btn-sm btn-outline-secondary" href="inventory.php?qr=<?=$item['id']?>" title="Print QR label" aria-label="Print QR label for <?=e($item['asset_tag'])?>"><i class="bi bi-qr-code" aria-hidden="true"></i></a><a class="btn btn-sm btn-outline-primary" href="inventory.php?edit=<?=$item['id']?>#item-form" title="Edit equipment" aria-label="Edit <?=e($item['item_name'])?>"><i class="bi bi-pencil" aria-hidden="true"></i></a><form method="post" onsubmit="return confirm('Delete this equipment record?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-outline-danger" title="Delete equipment" aria-label="Delete <?=e($item['item_name'])?>"><i class="bi bi-trash3" aria-hidden="true"></i></button></form></td></tr><?php endwhile; else: ?><tr><td colspan="9" class="text-center py-5"><strong>No matching equipment</strong><br><span class="muted">Add an item or change your search.</span></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>