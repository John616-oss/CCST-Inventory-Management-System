<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin','staff','maintenance']);
ensure_inventory_table($conn);
$user=current_user();
$isMaintenance=$user['role']==='maintenance';
$isAdmin=$user['role']==='admin';
$tag=trim($_GET['tag']??'');
$isRoomScan=str_starts_with($tag,'CCST-ROOM:');
$roomLocation=$isRoomScan?trim(substr($tag,10)):'';
$item=null;
$roomItems=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    if(!$isMaintenance){http_response_code(403);exit('Repair actions are only available to Maintenance accounts.');}
    $itemId=(int)($_POST['item_id']??0);
    $scanCode=trim($_POST['scan_tag']??'');
    $action=$_POST['repair_action']??'';
    $workDescription=trim($_POST['work_description']??'');
    $partsReplaced=trim($_POST['parts_replaced']??'');
    $roomAction=str_starts_with($scanCode,'CCST-ROOM:');
    $room=trim(substr($scanCode,10));
    if(!in_array($action,['start','complete'],true)||$scanCode===''){
        flash('Invalid repair action. Scan the equipment or room QR again.','error');
        redirect('maintenance/dashboard.php');
    }
    if($action==='complete'&&$workDescription===''){
        flash('Describe the repair before marking it complete.','error');
        redirect('scan.php?tag='.rawurlencode($scanCode));
    }
    if($roomAction){
        $check=$conn->prepare("SELECT id,item_name FROM inventory_items WHERE id=? AND location=? AND status<>'Disposed' AND (condition_status IN ('Needs Repair','Damaged') OR status='Under Repair')");
        $check->bind_param('is',$itemId,$room);
    }else{
        $check=$conn->prepare("SELECT id,item_name FROM inventory_items WHERE id=? AND asset_tag=? AND status<>'Disposed' AND (condition_status IN ('Needs Repair','Damaged') OR status='Under Repair')");
        $check->bind_param('is',$itemId,$scanCode);
    }
    $check->execute();
    $repairItem=$check->get_result()->fetch_assoc();
    if(!$repairItem){
        flash('That equipment is not part of an active repair task. Scan its QR again.','error');
        redirect('maintenance/dashboard.php');
    }
    if($action==='start'){
        $update=$conn->prepare("UPDATE inventory_items SET status='Under Repair' WHERE id=?");
        $message=$repairItem['item_name'].' marked Under Repair.';
        $logAction='START EQUIPMENT REPAIR';
    }else{
        $update=$conn->prepare("UPDATE inventory_items SET condition_status='Good',status='Available' WHERE id=?");
        $message=$repairItem['item_name'].' marked repaired and Available.';
        $logAction='COMPLETE EQUIPMENT REPAIR';
    }
    $update->bind_param('i',$itemId);
    $update->execute();
    $historyAction=$action==='start'?'started':'completed';
    $workDescription=$action==='complete'?$workDescription:null;
    $partsReplaced=$action==='complete'&&$partsReplaced!==''?$partsReplaced:null;
    $history=$conn->prepare('INSERT INTO maintenance_history(inventory_item_id,user_id,action,work_description,parts_replaced) VALUES(?,?,?,?,?)');
    $history->bind_param('iisss',$itemId,$user['id'],$historyAction,$workDescription,$partsReplaced);
    $history->execute();
    log_action($logAction,$repairItem['item_name'].' (#'.$itemId.')');
    flash($message);
    redirect('scan.php?tag='.rawurlencode($scanCode));
}

if($tag!==''){
    if($isRoomScan){
        if($isMaintenance){
            $roomQuery=$conn->prepare("SELECT id,asset_tag,item_name,category,location,condition_status,status,updated_at FROM inventory_items WHERE location=? AND status<>'Disposed' AND (condition_status IN ('Needs Repair','Damaged') OR status='Under Repair') ORDER BY updated_at ASC");
        }else{
            $roomQuery=$conn->prepare("SELECT id,asset_tag,item_name,category,location,condition_status,status,updated_at FROM inventory_items WHERE location=? AND status<>'Disposed' ORDER BY item_name");
        }
        $roomQuery->bind_param('s',$roomLocation);
        $roomQuery->execute();
        $roomItems=$roomQuery->get_result();
    }else{
        if($isMaintenance){
            $itemQuery=$conn->prepare("SELECT * FROM inventory_items WHERE asset_tag=? AND status<>'Disposed' AND (condition_status IN ('Needs Repair','Damaged') OR status='Under Repair') LIMIT 1");
        }else{
            $itemQuery=$conn->prepare("SELECT * FROM inventory_items WHERE asset_tag=? AND status<>'Disposed' LIMIT 1");
        }
        $itemQuery->bind_param('s',$tag);
        $itemQuery->execute();
        $item=$itemQuery->get_result()->fetch_assoc();
    }
}
include __DIR__.'/includes/header.php';
?>
<div class="scan-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow"><?=$isMaintenance?'MAINTENANCE SERVICE':'ASSET LOOKUP'?></p><h1><?=$isMaintenance?'Scan equipment or room':'QR scanner'?></h1><p class="dashboard-subtitle"><?=$isMaintenance?'Only repair items tied to the scanned equipment or room will appear.':'Scan an equipment or room label, or enter its code.'?></p></div></header>
    <section class="panel scan-panel">
        <div id="qr-reader" class="qr-reader"></div>
        <div id="scanner-message" class="scan-message" role="status">Allow camera access to scan an equipment or room QR code.</div>
        <form class="scan-tag-form" method="get"><label class="form-label" for="asset-tag-input">Equipment tag or room QR code</label><div class="input-group"><input class="form-control" id="asset-tag-input" name="tag" value="<?=e($tag)?>" placeholder="CCST-INV-2026-0001 or scan room QR" autocomplete="off" autofocus><button class="btn btn-primary" type="submit"><i class="bi bi-search" aria-hidden="true"></i> Find</button></div></form>
    </section>
    <?php if($tag!==''&&$isRoomScan): ?>
        <section class="panel scan-result"><div><p class="dashboard-eyebrow">ROOM SCANNED</p><h2><?=e($roomLocation)?></h2></div>
            <?php if($roomItems&&$roomItems->num_rows): ?><p class="muted mt-2"><?=$isMaintenance?'Repair items in this room':'Equipment recorded in this room'?></p><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Asset tag</th><th>Equipment</th><th>Condition</th><th>Status</th><?php if($isMaintenance): ?><th>Repair</th><?php endif; ?></tr></thead><tbody>
                <?php while($roomItem=$roomItems->fetch_assoc()): ?><tr><td class="inventory-tag"><?=e($roomItem['asset_tag'])?></td><td><?=e($roomItem['item_name'])?></td><td><?=e($roomItem['condition_status'])?></td><td><?=e($roomItem['status'])?></td><?php if($isMaintenance): ?><td><form method="post" class="repair-action-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$roomItem['id']?>"><input type="hidden" name="scan_tag" value="<?=e($tag)?>"><?php if($roomItem['status']==='Under Repair'): ?><label class="form-label" for="repair-description-<?=$roomItem['id']?>">Repair done</label><textarea class="form-control mb-2" id="repair-description-<?=$roomItem['id']?>" name="work_description" rows="2" required placeholder="Describe what was repaired"></textarea><label class="form-label" for="repair-parts-<?=$roomItem['id']?>">Parts replaced (optional)</label><input class="form-control mb-2" id="repair-parts-<?=$roomItem['id']?>" name="parts_replaced" placeholder="Part name or none"><?php endif; ?><?php if($roomItem['status']==='Under Repair'): ?><button class="btn btn-sm btn-success" name="repair_action" value="complete"><i class="bi bi-check2" aria-hidden="true"></i> Mark fixed</button><?php else: ?><button class="btn btn-sm btn-outline-primary" name="repair_action" value="start"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> Start repair</button><?php endif; ?></form></td><?php endif; ?></tr><?php endwhile; ?>
            </tbody></table></div><?php else: ?><p class="scan-empty-message">No active repair items were found in this room.</p><?php endif; ?>
        </section>
    <?php elseif($tag!==''&&!$item): ?>
        <div class="alert error mt-3"><?=$isMaintenance?'No active repair task matches this QR code. Check with Staff if the item needs repair.':'No equipment or room found for this code.'?></div>
    <?php elseif($item): ?>
        <section class="panel scan-result"><div><p class="dashboard-eyebrow"><?=$isMaintenance?'REPAIR ITEM':'ASSET FOUND'?></p><h2><?=e($item['item_name'])?></h2><p class="inventory-tag"><?=e($item['asset_tag'])?></p></div><dl class="scan-details"><div><dt>Category</dt><dd><?=e($item['category'])?></dd></div><div><dt>Location</dt><dd><?=e($item['location'])?></dd></div><div><dt>Condition</dt><dd><?=e($item['condition_status'])?></dd></div><div><dt>Status</dt><dd><?=e($item['status'])?></dd></div><?php if(!$isMaintenance): ?><div><dt>Quantity</dt><dd><?=number_format((int)$item['quantity'])?> <?=e($item['unit'])?></dd></div><?php if($item['custodian']): ?><div><dt>Assigned to</dt><dd><?=e($item['custodian'])?></dd></div><?php endif; ?><?php endif; ?></dl>
            <?php if($isMaintenance): ?><form method="post" class="repair-action-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="item_id" value="<?=$item['id']?>"><input type="hidden" name="scan_tag" value="<?=e($tag)?>"><?php if($item['status']==='Under Repair'): ?><div class="mb-3"><label class="form-label" for="work-description">Repair done</label><textarea class="form-control" id="work-description" name="work_description" rows="3" required placeholder="Describe what was repaired"></textarea></div><div class="mb-3"><label class="form-label" for="parts-replaced">Parts replaced (optional)</label><input class="form-control" id="parts-replaced" name="parts_replaced" placeholder="Part name or none"></div><button class="btn btn-success" name="repair_action" value="complete"><i class="bi bi-check2" aria-hidden="true"></i> Mark repair complete</button><?php else: ?><button class="btn btn-primary" name="repair_action" value="start"><i class="bi bi-wrench-adjustable" aria-hidden="true"></i> Start repair</button><?php endif; ?></form><?php elseif($isAdmin): ?><a class="btn btn-outline-primary" href="inventory.php?edit=<?=$item['id']?>#item-form"><i class="bi bi-pencil" aria-hidden="true"></i> Edit asset</a><?php else: ?><span class="muted">View equipment in inventory.</span><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<script>
window.addEventListener('load',()=>{
    const scanInput=document.getElementById('asset-tag-input');
    const scanMessage=document.getElementById('scanner-message');
    function submitScannedCode(decodedText){scanInput.value=decodedText.trim();scanInput.form.requestSubmit();}
    if(window.Html5Qrcode){
        const scanner=new Html5Qrcode('qr-reader');
        scanner.start({facingMode:'environment'},{fps:10,qrbox:{width:230,height:230}},submitScannedCode,()=>{}).then(()=>{scanMessage.textContent='Camera is on. Point it at an equipment or room QR label.';}).catch(()=>{scanMessage.textContent='Camera unavailable. Use a USB scanner or enter a code.';});
    }else{scanMessage.textContent='Camera scanner could not load. Use a USB scanner or enter a code.';}
});
</script>
<?php include __DIR__.'/includes/footer.php'; ?>