<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin','staff']);
ensure_inventory_table($conn);
$isAdmin=current_user()['role']==='admin';
$selectedRoom=null;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!$isAdmin){http_response_code(403);exit('Only Admin can manage rooms.');}
    csrf_check();
    $action=$_POST['action']??'';
    if($action==='add_room'){
        $roomName=trim($_POST['room_name']??'');
        $floor=trim($_POST['floor']??'');
        if($roomName===''||$floor===''){
            flash('Enter both the room name and floor.','error');
            redirect('rooms.php#add-room');
        }
        $stmt=$conn->prepare('INSERT INTO inventory_rooms(room_name,floor) VALUES(?,?)');
        $stmt->bind_param('ss',$roomName,$floor);
        try{$stmt->execute();}
        catch(mysqli_sql_exception $e){
            if((int)$e->getCode()===1062)flash('That room name already exists. Edit the existing room floor instead.','error');
            else{error_log('Room creation failed: '.$e->getMessage());flash('The room could not be saved.','error');}
            redirect('rooms.php#add-room');
        }
        log_action('ADD INVENTORY ROOM',$roomName.' · '.$floor);
        flash('Room added. Add its equipment from the room details.');
        redirect('rooms.php?view='.$stmt->insert_id);
    }
    if($action==='update_floor'){
        $roomId=(int)($_POST['room_id']??0);
        $floor=trim($_POST['floor']??'');
        if($roomId<1||$floor===''){flash('Enter a floor for this room.','error');redirect('rooms.php');}
        $stmt=$conn->prepare('UPDATE inventory_rooms SET floor=? WHERE id=?');
        $stmt->bind_param('si',$floor,$roomId);$stmt->execute();
        log_action('UPDATE INVENTORY ROOM FLOOR','#'.$roomId.' · '.$floor);
        flash('Room floor updated.');
        redirect('rooms.php?view='.$roomId);
    }
}

if(isset($_GET['view'])){
    $roomId=(int)$_GET['view'];
    $stmt=$conn->prepare('SELECT * FROM inventory_rooms WHERE id=?');
    $stmt->bind_param('i',$roomId);$stmt->execute();
    $selectedRoom=$stmt->get_result()->fetch_assoc();
    if(!$selectedRoom){flash('Room not found.','error');redirect('rooms.php');}
    $itemStmt=$conn->prepare('SELECT id,asset_tag,item_name,category,quantity,unit,condition_status,status FROM inventory_items WHERE location=? ORDER BY item_name,id');
    $itemStmt->bind_param('s',$selectedRoom['room_name']);$itemStmt->execute();
    $roomItems=$itemStmt->get_result();
}

$rooms=$conn->query("SELECT r.id,r.room_name,r.floor,COUNT(CASE WHEN i.status<>'Disposed' THEN 1 END) AS equipment_types,COALESCE(SUM(CASE WHEN i.status<>'Disposed' THEN i.quantity ELSE 0 END),0) AS equipment_units FROM inventory_rooms r LEFT JOIN inventory_items i ON i.location=r.room_name GROUP BY r.id,r.room_name,r.floor ORDER BY r.floor,r.room_name");
include __DIR__.'/includes/header.php';
?>
<div class="rooms-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">ROOM DIRECTORY</p><h1>Rooms &amp; floors</h1><p class="dashboard-subtitle">Choose a room to see the PCs, TVs, air conditioners, and other equipment assigned there.</p></div><?php if($isAdmin): ?><a class="btn btn-primary" href="#add-room">Add room</a><?php endif; ?></header>

    <?php if($selectedRoom): ?>
    <section class="panel rooms-detail-panel">
        <div class="rooms-detail-heading"><div><a href="rooms.php" class="rooms-back-link">← All rooms</a><h2><?=e($selectedRoom['room_name'])?></h2><p>Floor: <strong><?=e($selectedRoom['floor'])?></strong></p></div><a class="btn btn-primary" href="inventory.php?room=<?=(int)$selectedRoom['id']?>#item-form">Add equipment to this room</a></div>
        <?php if($isAdmin): ?><form method="post" class="rooms-floor-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="update_floor"><input type="hidden" name="room_id" value="<?=(int)$selectedRoom['id']?>"><label for="room-floor">Update floor</label><input class="form-control" id="room-floor" name="floor" value="<?=e($selectedRoom['floor'])?>" required><button class="btn btn-outline-primary" type="submit">Save floor</button></form><?php endif; ?>
        <div class="table-responsive"><table class="table table-hover align-middle rooms-equipment-table"><thead><tr><th>Equipment</th><th>Category</th><th>Quantity</th><th>Condition</th><th>Status</th><th></th></tr></thead><tbody>
        <?php if($roomItems->num_rows): while($item=$roomItems->fetch_assoc()): ?><tr><td><strong><?=e($item['item_name'])?></strong><small class="d-block text-secondary"><?=e($item['asset_tag'])?></small></td><td><?=e($item['category'])?></td><td><?=number_format((int)$item['quantity'])?> <?=e($item['unit'])?></td><td><?=e($item['condition_status'])?></td><td><?=e($item['status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="inventory.php?edit=<?=(int)$item['id']?>#item-form">View item</a></td></tr><?php endwhile; else: ?><tr><td colspan="6" class="rooms-empty-state">Wala pang equipment sa room na ito. Gamitin ang “Add equipment to this room” para maglagay ng PC, TV, aircon, o iba pa.</td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
    <?php endif; ?>

    <section class="rooms-card-grid" aria-label="Room list">
    <?php if($rooms->num_rows): while($room=$rooms->fetch_assoc()): ?>
        <article class="panel rooms-card"><div class="rooms-card-icon">ROOM</div><p class="rooms-floor-label"><?=e($room['floor'])?></p><h2><?=e($room['room_name'])?></h2><p><?=number_format((int)$room['equipment_units'])?> equipment unit(s) · <?=number_format((int)$room['equipment_types'])?> records</p><a class="btn btn-outline-primary" href="rooms.php?view=<?=(int)$room['id']?>">View room contents</a></article>
    <?php endwhile; else: ?><div class="panel rooms-empty-state">No rooms yet. Add a room and its floor to start organizing equipment.</div><?php endif; ?>
    </section>

    <?php if($isAdmin): ?><section class="panel rooms-add-panel" id="add-room"><div><h2>Add a room</h2><p>Each room has one floor. You can add its equipment after saving.</p></div><form method="post" class="rooms-add-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="add_room"><label>Room name<input class="form-control" name="room_name" placeholder="Computer Laboratory 1" maxlength="150" required></label><label>Floor<input class="form-control" name="floor" placeholder="2nd Floor" maxlength="80" required></label><button class="btn btn-primary" type="submit">Save room</button></form></section><?php endif; ?>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>
