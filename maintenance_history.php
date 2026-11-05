<?php
require_once __DIR__.'/includes/functions.php';
require_role(['admin','staff']);
ensure_inventory_table($conn);
$itemId=(int)($_GET['id']??0);
$itemQuery=$conn->prepare('SELECT id,asset_tag,item_name,category,location,condition_status,status FROM inventory_items WHERE id=?');
$itemQuery->bind_param('i',$itemId);
$itemQuery->execute();
$item=$itemQuery->get_result()->fetch_assoc();
if(!$item){flash('Equipment record not found.','error');redirect('inventory.php');}
$historyQuery=$conn->prepare('SELECT h.action,h.created_at,u.full_name,u.email FROM maintenance_history h JOIN users u ON u.id=h.user_id WHERE h.inventory_item_id=? ORDER BY h.id DESC');
$historyQuery->bind_param('i',$itemId);
$historyQuery->execute();
$history=$historyQuery->get_result();
include __DIR__.'/includes/header.php';
?>
<div class="inventory-page">
    <header class="dashboard-titlebar"><div><p class="dashboard-eyebrow">MAINTENANCE AUDIT</p><h1>Repair history</h1><p class="dashboard-subtitle"><?=e($item['asset_tag'])?> · <?=e($item['item_name'])?> · <?=e($item['location'])?></p></div><a class="btn btn-outline-secondary" href="inventory.php"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to inventory</a></header>
    <section class="panel inventory-recent-panel"><div class="dashboard-section-heading"><div><h2>Maintenance actions</h2><p><?=number_format($history->num_rows)?> recorded actions</p></div></div>
        <div class="table-responsive"><table class="table table-hover align-middle"><thead><tr><th>Maintenance account</th><th>Email</th><th>Action</th><th>Date and time</th></tr></thead><tbody>
        <?php if($history->num_rows): while($entry=$history->fetch_assoc()): ?><tr><td><strong><?=e($entry['full_name'])?></strong></td><td><?=e($entry['email'])?></td><td><?=e($entry['action']==='completed'?'Repair completed':'Repair started')?></td><td><?=e(date('M j, Y g:i A',strtotime($entry['created_at'])))?></td></tr><?php endwhile; else: ?><tr><td colspan="4" class="text-center py-5"><strong>No maintenance actions recorded yet</strong></td></tr><?php endif; ?>
        </tbody></table></div>
    </section>
</div>
<?php include __DIR__.'/includes/footer.php'; ?>