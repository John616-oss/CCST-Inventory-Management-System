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
    http_response_code(405);
    exit('Use the maintenance request page to record repairs.');
}

if($tag!==''){
    if($isRoomScan){
        $roomQuery=$conn->prepare("SELECT id,asset_tag,item_name,category,location,quantity,unit,condition_status,status,updated_at FROM inventory_items WHERE location=? AND status<>'Disposed' ORDER BY item_name");
        $roomQuery->bind_param('s',$roomLocation);
        $roomQuery->execute();
        $roomItems=$roomQuery->get_result();
    }else{
        $itemQuery=$conn->prepare("SELECT * FROM inventory_items WHERE asset_tag=? AND status<>'Disposed' LIMIT 1");
        $itemQuery->bind_param('s',$tag);
        $itemQuery->execute();
        $item=$itemQuery->get_result()->fetch_assoc();
    }
}
$itemHistory=null;
if($isMaintenance&&$item){
    $itemHistoryQuery=$conn->prepare('SELECT h.action,h.work_description,h.created_at,u.full_name FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=? ORDER BY h.id DESC LIMIT 5');
    $itemHistoryQuery->bind_param('i',$item['id']);
    $itemHistoryQuery->execute();
    $itemHistory=$itemHistoryQuery->get_result();
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
            <?php if($roomItems&&$roomItems->num_rows): ?><p class="muted mt-2"><?=$isMaintenance?'Equipment in this room':'Equipment recorded in this room'?></p><div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Asset tag</th><th>Equipment</th><th>Quantity</th><th>Condition</th><th>Status</th><?php if($isMaintenance): ?><th>Action</th><?php endif; ?></tr></thead><tbody>
                <?php while($roomItem=$roomItems->fetch_assoc()): ?><tr><td class="inventory-tag"><?=e($roomItem['asset_tag'])?></td><td><?=e($roomItem['item_name'])?></td><td><?=number_format((int)$roomItem['quantity'])?> <?=e($roomItem['unit'])?></td><td><?=e($roomItem['condition_status'])?></td><td><?=e($roomItem['status'])?></td><?php if($isMaintenance): ?><td><a class="btn btn-sm btn-outline-primary" href="<?=e(BASE_URL)?>/maintenance/requests.php?equipment_id=<?=(int)$roomItem['id']?>#report"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Report</a></td><?php endif; ?></tr><?php endwhile; ?>
            </tbody></table></div><?php else: ?><p class="scan-empty-message">No equipment was found in this room.</p><?php endif; ?>
        </section>
    <?php elseif($tag!==''&&!$item): ?>
        <div class="alert error mt-3"><?=$isMaintenance?'No active repair task matches this QR code. Check with Staff if the item needs repair.':'No equipment or room found for this code.'?></div>
    <?php elseif($item): ?>
        <section class="panel scan-result"><div><p class="dashboard-eyebrow"><?=$isMaintenance?'EQUIPMENT DETAILS':'ASSET FOUND'?></p><h2><?=e($item['item_name'])?></h2><p class="inventory-tag"><?=e($item['asset_tag'])?></p></div><dl class="scan-details"><div><dt>Category</dt><dd><?=e($item['category'])?></dd></div><div><dt>Location</dt><dd><?=e($item['location'])?></dd></div><div><dt>Quantity</dt><dd><?=number_format((int)$item['quantity'])?> <?=e($item['unit'])?></dd></div><div><dt>Condition</dt><dd><?=e($item['condition_status'])?></dd></div><div><dt>Availability status</dt><dd><?=e($item['status'])?></dd></div><?php if(!$isMaintenance&&$item['custodian']): ?><div><dt>Assigned to</dt><dd><?=e($item['custodian'])?></dd></div><?php endif; ?></dl>
            <?php if($isMaintenance): ?><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="<?=e(BASE_URL)?>/maintenance/requests.php?equipment_id=<?=(int)$item['id']?>#report"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Report damage</a><a class="btn btn-outline-primary" href="<?=e(BASE_URL)?>/maintenance_history.php?id=<?=(int)$item['id']?>"><i class="bi bi-clock-history" aria-hidden="true"></i> View repair history</a></div><div class="maintenance-scan-history mt-4"><h3>Recent maintenance history</h3><?php if($itemHistory&&$itemHistory->num_rows): ?><ul><?php while($historyItem=$itemHistory->fetch_assoc()): ?><li><strong><?=e(match($historyItem['action']){'reported'=>'Damage reported','started'=>'Repair started','completed'=>'Repair completed',default=>'Cannot be repaired'})?></strong><span><?=e($historyItem['work_description']?:'No details recorded')?></span><small><?=e($historyItem['full_name'])?> · <?=e(date('M j, Y g:i A',strtotime($historyItem['created_at'])))?></small></li><?php endwhile; ?></ul><?php else: ?><p class="muted">No maintenance activity has been recorded.</p><?php endif; ?></div><?php elseif($isAdmin): ?><a class="btn btn-outline-primary" href="inventory.php?edit=<?=$item['id']?>#item-form"><i class="bi bi-pencil" aria-hidden="true"></i> Edit asset</a><?php else: ?><span class="muted">View equipment in inventory.</span><?php endif; ?>
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