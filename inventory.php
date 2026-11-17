<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin','staff']);
ensure_inventory_table($conn);
$roomOptions=$conn->query('SELECT id,room_name,floor FROM inventory_rooms ORDER BY floor,room_name');
$rooms=[];while($roomOption=$roomOptions->fetch_assoc())$rooms[]=$roomOption;
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
        $historyCheck=$conn->prepare('SELECT COUNT(*) FROM maintenance_history WHERE inventory_item_id=?');
        $historyCheck->bind_param('i',$id);
        $historyCheck->execute();
        if((int)$historyCheck->get_result()->fetch_row()[0]>0){
            flash('This equipment has maintenance history and cannot be deleted. Set its status to Disposed to preserve its records.','error');
            redirect('inventory.php');
        }
        $stmt=$conn->prepare('DELETE FROM inventory_items WHERE id=?');
        $stmt->bind_param('i',$id);
        try{
            $stmt->execute();
        }catch(mysqli_sql_exception $e){
            $errorCode=(int)$e->getCode();
            error_log('Inventory item deletion failed for #'.$id.': '.$e->getMessage());
            if($errorCode===1451){
                flash('This equipment has linked request, transaction, or maintenance history and cannot be deleted. Set its status to Disposed to preserve its history.','error');
            }else{
                flash('The equipment record could not be deleted. Please try again or contact an administrator.','error');
            }
            redirect('inventory.php');
        }
        if($stmt->affected_rows===0){
            flash('Equipment record not found.','error');
            redirect('inventory.php');
        }
        log_action('DELETE INVENTORY ITEM','Item #'.$id);
        flash('Equipment record deleted.');
        redirect('inventory.php');
    }

    if($id){
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
            redirect('inventory.php?edit='.$id);
        }
        $description=$description===''?null:$description;
        $custodian=$custodian===''?null:$custodian;
        $acquisitionDate=$acquisitionDate===''?null:$acquisitionDate;
        $stmt=$conn->prepare('UPDATE inventory_items SET item_name=?,category=?,description=?,quantity=?,unit=?,location=?,custodian=?,condition_status=?,status=?,acquisition_date=?,acquisition_cost=? WHERE id=?');
        $stmt->bind_param('sssissssssdi',$itemName,$category,$description,$quantity,$unit,$location,$custodian,$condition,$status,$acquisitionDate,$cost,$id);
        $stmt->execute();
        log_action('UPDATE INVENTORY ITEM',$itemName);
        flash('Equipment record updated.');
        redirect('inventory.php');
    }

    $location=trim($_POST['location']??'');
    $submittedItems=$_POST['items']??null;
    if($location===''||!is_array($submittedItems)||!$submittedItems){
        flash('Enter a room and add at least one equipment row.','error');
        redirect('inventory.php#item-form');
    }
    $roomCheck=$conn->prepare('SELECT COUNT(*) FROM inventory_rooms WHERE room_name=?');
    $roomCheck->bind_param('s',$location);$roomCheck->execute();
    if((int)$roomCheck->get_result()->fetch_row()[0]===0){
        flash('Choose a registered room first. Add new rooms from Rooms & Floors.','error');
        redirect('inventory.php#item-form');
    }
    $itemsToCreate=[];
    foreach($submittedItems as $submittedItem){
        if(!is_array($submittedItem)){
            flash('One equipment row is invalid.','error');
            redirect('inventory.php#item-form');
        }
        $itemName=trim($submittedItem['item_name']??'');
        $category=trim($submittedItem['category']??'');
        $quantity=filter_var($submittedItem['quantity']??null,FILTER_VALIDATE_INT);
        $unit=trim($submittedItem['unit']??'');
        if($itemName===''||$category===''||$quantity===false||$quantity<1||$unit===''){
            flash('Each equipment row needs a name, category, quantity of at least 1, and unit.','error');
            redirect('inventory.php#item-form');
        }
        $costRaw=trim($submittedItem['acquisition_cost']??'');
        if($costRaw!==''&&(!is_numeric($costRaw)||(float)$costRaw<0)){
            flash('Acquisition cost must be a non-negative number.','error');
            redirect('inventory.php#item-form');
        }
        $itemsToCreate[]=[
            'item_name'=>$itemName,
            'category'=>$category,
            'description'=>trim($submittedItem['description']??'')?:null,
            'quantity'=>$quantity,
            'unit'=>$unit,
            'custodian'=>trim($submittedItem['custodian']??'')?:null,
            'condition'=>in_array($submittedItem['condition_status']??'', $conditions, true)?$submittedItem['condition_status']:'Good',
            'status'=>in_array($submittedItem['status']??'', $statuses, true)?$submittedItem['status']:'Available',
            'acquisition_date'=>trim($submittedItem['acquisition_date']??'')?:null,
            'cost'=>$costRaw===''?null:(float)$costRaw,
        ];
    }

    $conn->begin_transaction();
    try{
        $year=date('Y');
        $prefix='CCST-INV-'.$year.'-';
        $tagQuery=$conn->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(asset_tag,'-',-1) AS UNSIGNED)),0)+1 AS next_num FROM inventory_items WHERE asset_tag LIKE CONCAT(?, '%')");
        $tagQuery->bind_param('s',$prefix);
        $tagQuery->execute();
        $next=(int)$tagQuery->get_result()->fetch_assoc()['next_num'];
        $insert=$conn->prepare('INSERT INTO inventory_items(asset_tag,item_name,category,description,quantity,unit,location,custodian,condition_status,status,acquisition_date,acquisition_cost) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach($itemsToCreate as $item){
            $assetTag=$prefix.str_pad((string)$next++,4,'0',STR_PAD_LEFT);
            $insert->bind_param('ssssissssssd',$assetTag,$item['item_name'],$item['category'],$item['description'],$item['quantity'],$item['unit'],$location,$item['custodian'],$item['condition'],$item['status'],$item['acquisition_date'],$item['cost']);
            $insert->execute();
            log_action('ADD INVENTORY ITEM',$item['item_name']);
        }
        $conn->commit();
    }catch(Throwable $error){
        $conn->rollback();
        error_log('Bulk inventory save failed: '.$error->getMessage());
        flash('Equipment could not be saved. No rows were added; check the server error log.','error');
        redirect('inventory.php#item-form');
    }
    flash(count($itemsToCreate).' equipment record(s) added to '.$location.'. Each record has its own asset tag and QR label.');
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
$sql="SELECT i.*,r.floor AS room_floor,(SELECT u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=i.id AND h.action='completed' ORDER BY h.id DESC LIMIT 1) AS last_repaired_by FROM inventory_items i LEFT JOIN inventory_rooms r ON r.room_name=i.location";
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
        <div class="inventory-form-heading"><div><h2><?=$edit?'Edit equipment':'Add equipment'?></h2><p><?=$edit?'Asset tags stay fixed so existing QR labels remain valid.':'Add multiple equipment types for one room; each row gets its own asset tag and QR label.'?></p></div><?php if($edit): ?><a class="btn btn-sm btn-outline-secondary" href="inventory.php">Cancel</a><?php endif; ?></div>
        <?php if($edit): ?>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id'])?>">
            <div class="col-md-4"><label class="form-label" for="item_name">Equipment name</label><input class="form-control" id="item_name" name="item_name" value="<?=e($edit['item_name'])?>" required></div>
            <div class="col-md-4"><label class="form-label" for="category">Category</label><input class="form-control" id="category" name="category" list="category-options" value="<?=e($edit['category'])?>" required></div>
            <div class="col-md-4"><label class="form-label" for="location">Room</label><select class="form-select" id="location" name="location" required><?php if(!in_array($edit['location'],array_column($rooms,'room_name'),true)): ?><option value="<?=e($edit['location'])?>" selected><?=e($edit['location'])?> (existing location)</option><?php endif; ?><?php foreach($rooms as $room): ?><option value="<?=e($room['room_name'])?>" <?=$edit['location']===$room['room_name']?'selected':''?>><?=e($room['room_name'])?> · <?=e($room['floor'])?></option><?php endforeach; ?></select><small class="text-secondary">Manage rooms and floors in Rooms &amp; Floors.</small></div>
            <div class="col-md-3"><label class="form-label" for="quantity">Quantity</label><input class="form-control" id="quantity" name="quantity" type="number" min="1" value="<?=e($edit['quantity'])?>" required></div>
            <div class="col-md-3"><label class="form-label" for="unit">Unit</label><input class="form-control" id="unit" name="unit" value="<?=e($edit['unit'])?>" required></div>
            <div class="col-md-3"><label class="form-label" for="condition_status">Condition</label><select class="form-select" id="condition_status" name="condition_status"><?php foreach($conditions as $condition): ?><option <?=$edit['condition_status']===$condition?'selected':''?>><?=e($condition)?></option><?php endforeach; ?></select></div>
            <div class="col-md-3"><label class="form-label" for="status">Status</label><select class="form-select" id="status" name="status"><?php foreach($statuses as $status): ?><option <?=$edit['status']===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="custodian">Assigned to (optional)</label><input class="form-control" id="custodian" name="custodian" value="<?=e($edit['custodian']??'')?>" placeholder="Office or responsible staff"></div>
            <div class="col-md-4"><label class="form-label" for="acquisition_date">Date acquired</label><input class="form-control" id="acquisition_date" name="acquisition_date" type="date" value="<?=e($edit['acquisition_date']??'')?>"></div>
            <div class="col-md-4"><label class="form-label" for="acquisition_cost">Acquisition cost</label><input class="form-control" id="acquisition_cost" name="acquisition_cost" type="number" min="0" step="0.01" value="<?=e($edit['acquisition_cost']??'')?>" placeholder="0.00"></div>
            <div class="col-12"><label class="form-label" for="description">Notes</label><textarea class="form-control" id="description" name="description" rows="2" placeholder="Model, serial number, or other details"><?=e($edit['description']??'')?></textarea></div>
            <div class="col-12"><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Save changes</button></div>
        </form>
        <?php else: ?>
        <form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="0">
            <div class="col-md-4"><label class="form-label" for="location">Room</label><select class="form-select" id="location" name="location" required><option value="">Choose a room</option><?php foreach($rooms as $room): ?><option value="<?=e($room['room_name'])?>" <?=((int)($_GET['room']??0)===(int)$room['id'])?'selected':''?>><?=e($room['room_name'])?> · <?=e($room['floor'])?></option><?php endforeach; ?></select><small class="text-secondary">Need a new room? Add it in <a href="rooms.php">Rooms &amp; Floors</a>.</small></div>
            <datalist id="category-options"><?php foreach($categoryOptions as $categoryOption): ?><option value="<?=e($categoryOption)?>"><?php endforeach; ?></datalist>
            <div class="col-12"><div class="row g-3" data-equipment-list>
                <fieldset class="col-12 border rounded p-3" data-equipment-row>
                    <legend class="float-none w-auto px-2 fs-6">Equipment <span data-row-number>1</span></legend>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Equipment name<input class="form-control" name="items[0][item_name]" placeholder="Desktop computer" required></label></div>
                        <div class="col-md-4"><label class="form-label">Category<input class="form-control" name="items[0][category]" list="category-options" placeholder="Computer equipment" required></label></div>
                        <div class="col-md-2"><label class="form-label">Quantity<input class="form-control" name="items[0][quantity]" type="number" min="1" value="1" required></label></div>
                        <div class="col-md-2"><label class="form-label">Unit<input class="form-control" name="items[0][unit]" value="piece" required></label></div>
                        <div class="col-md-3"><label class="form-label">Condition<select class="form-select" name="items[0][condition_status]"><?php foreach($conditions as $condition): ?><option><?=$condition?></option><?php endforeach; ?></select></label></div>
                        <div class="col-md-3"><label class="form-label">Status<select class="form-select" name="items[0][status]"><?php foreach($statuses as $status): ?><option><?=$status?></option><?php endforeach; ?></select></label></div>
                        <div class="col-md-3"><label class="form-label">Assigned to (optional)<input class="form-control" name="items[0][custodian]" placeholder="Office or responsible staff"></label></div>
                        <div class="col-md-3"><label class="form-label">Date acquired<input class="form-control" name="items[0][acquisition_date]" type="date"></label></div>
                        <div class="col-md-3"><label class="form-label">Acquisition cost<input class="form-control" name="items[0][acquisition_cost]" type="number" min="0" step="0.01" placeholder="0.00"></label></div>
                        <div class="col-md-9"><label class="form-label">Notes<textarea class="form-control" name="items[0][description]" rows="1" placeholder="Model, serial number, or other details"></textarea></label></div>
                        <div class="col-12 text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-row disabled>Remove row</button></div>
                    </div>
                </fieldset>
            </div></div>
            <template id="equipment-row-template">
                <fieldset class="col-12 border rounded p-3" data-equipment-row>
                    <legend class="float-none w-auto px-2 fs-6">Equipment <span data-row-number></span></legend>
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label">Equipment name<input class="form-control" name="items[__INDEX__][item_name]" placeholder="Desktop computer" required></label></div>
                        <div class="col-md-4"><label class="form-label">Category<input class="form-control" name="items[__INDEX__][category]" list="category-options" placeholder="Computer equipment" required></label></div>
                        <div class="col-md-2"><label class="form-label">Quantity<input class="form-control" name="items[__INDEX__][quantity]" type="number" min="1" value="1" required></label></div>
                        <div class="col-md-2"><label class="form-label">Unit<input class="form-control" name="items[__INDEX__][unit]" value="piece" required></label></div>
                        <div class="col-md-3"><label class="form-label">Condition<select class="form-select" name="items[__INDEX__][condition_status]"><?php foreach($conditions as $condition): ?><option><?=$condition?></option><?php endforeach; ?></select></label></div>
                        <div class="col-md-3"><label class="form-label">Status<select class="form-select" name="items[__INDEX__][status]"><?php foreach($statuses as $status): ?><option><?=$status?></option><?php endforeach; ?></select></label></div>
                        <div class="col-md-3"><label class="form-label">Assigned to (optional)<input class="form-control" name="items[__INDEX__][custodian]" placeholder="Office or responsible staff"></label></div>
                        <div class="col-md-3"><label class="form-label">Date acquired<input class="form-control" name="items[__INDEX__][acquisition_date]" type="date"></label></div>
                        <div class="col-md-3"><label class="form-label">Acquisition cost<input class="form-control" name="items[__INDEX__][acquisition_cost]" type="number" min="0" step="0.01" placeholder="0.00"></label></div>
                        <div class="col-md-9"><label class="form-label">Notes<textarea class="form-control" name="items[__INDEX__][description]" rows="1" placeholder="Model, serial number, or other details"></textarea></label></div>
                        <div class="col-12 text-end"><button class="btn btn-sm btn-outline-danger" type="button" data-remove-row>Remove row</button></div>
                    </div>
                </fieldset>
            </template>
            <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-outline-primary" type="button" data-add-equipment><i class="bi bi-plus-lg" aria-hidden="true"></i> Add another equipment</button><button class="btn btn-primary"><i class="bi bi-check2" aria-hidden="true"></i> Save equipment for this room</button></div>
        </form>
        <script>
        (() => {
            const list=document.querySelector('[data-equipment-list]');
            const template=document.getElementById('equipment-row-template');
            const addButton=document.querySelector('[data-add-equipment]');
            if(!list||!template||!addButton)return;
            let nextIndex=1;
            const refreshRows=()=>{
                const rows=[...list.querySelectorAll('[data-equipment-row]')];
                rows.forEach((row,index)=>{
                    row.querySelector('[data-row-number]').textContent=String(index+1);
                    row.querySelector('[data-remove-row]').disabled=rows.length===1;
                });
            };
            addButton.addEventListener('click',()=>{
                const row=template.content.cloneNode(true);
                row.querySelectorAll('[name]').forEach((field)=>{
                    field.name=field.name.replace('__INDEX__',String(nextIndex));
                });
                nextIndex++;
                list.append(row);
                refreshRows();
            });
            list.addEventListener('click',(event)=>{
                const button=event.target.closest('[data-remove-row]');
                if(button){
                    button.closest('[data-equipment-row]').remove();
                    refreshRows();
                }
            });
            refreshRows();
        })();
        </script>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="panel inventory-list-panel" id="inventory-list">
        <div class="inventory-list-heading"><div><h2>All equipment</h2><p><?=number_format($items->num_rows)?> records shown, up to 300</p></div><form class="inventory-filter" method="get"><label class="visually-hidden" for="inventory-search">Search equipment</label><input class="form-control" id="inventory-search" name="q" value="<?=e($query)?>" placeholder="Search tag, name, category, room"><label class="visually-hidden" for="inventory-status">Filter by status</label><select class="form-select" id="inventory-status" name="status"><option value="">All statuses</option><?php foreach($statuses as $status): ?><option value="<?=e($status)?>" <?=$statusFilter===$status?'selected':''?>><?=e($status)?></option><?php endforeach; ?></select><button class="btn btn-outline-secondary" type="submit">Search</button></form></div>
        <div class="table-responsive inventory-table-scroll"><table class="table table-hover align-middle inventory-table"><thead><tr><th>Equipment / asset tag</th><th>Category</th><th>Qty</th><th>Room / floor</th><th>Condition</th><th>Status</th><th>Last repaired by</th><th>Actions</th></tr></thead><tbody>
        <?php if($items->num_rows): while($item=$items->fetch_assoc()): ?><tr><td class="inventory-equipment-cell"><strong><?=e($item['item_name'])?></strong><small class="inventory-tag d-block"><?=e($item['asset_tag'])?></small><?php if($item['custodian']): ?><small class="d-block text-secondary">Assigned: <?=e($item['custodian'])?></small><?php endif; ?></td><td><?=e($item['category'])?></td><td><?=number_format((int)$item['quantity'])?> <?=e($item['unit'])?></td><td class="inventory-location-cell"><span><?=e($item['location'])?><?=!empty($item['room_floor'])?' · '.e($item['room_floor']):''?></span><a class="room-qr-link" href="inventory.php?room_qr=<?=e(rawurlencode($item['location']))?>" title="Print QR label for <?=e($item['location'])?>" aria-label="Print room QR label for <?=e($item['location'])?>">Room QR</a></td><td><?=e($item['condition_status'])?></td><td><span class="inventory-status"><?=e($item['status'])?></span></td><td><?=e($item['last_repaired_by']??'—')?></td><td class="inventory-actions"><a class="btn btn-sm btn-outline-secondary" href="maintenance_history.php?id=<?=$item['id']?>" title="View maintenance history" aria-label="View maintenance history for <?=e($item['asset_tag'])?>">History</a><a class="btn btn-sm btn-outline-secondary" href="inventory.php?qr=<?=$item['id']?>" title="Print QR label" aria-label="Print QR label for <?=e($item['asset_tag'])?>">QR</a><a class="btn btn-sm btn-outline-primary" href="inventory.php?edit=<?=$item['id']?>#item-form" title="Edit equipment" aria-label="Edit <?=e($item['item_name'])?>">Edit</a><form method="post" onsubmit="return confirm('Delete this equipment record?')"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$item['id']?>"><button class="btn btn-sm btn-outline-danger" title="Delete equipment" aria-label="Delete <?=e($item['item_name'])?>">Delete</button></form></td></tr><?php endwhile; else: ?><tr><td colspan="8" class="text-center py-5"><strong>No matching equipment</strong><br><span class="muted">Add an item or change your search.</span></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
