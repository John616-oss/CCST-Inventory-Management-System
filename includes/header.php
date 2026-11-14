<?php
require_once __DIR__.'/functions.php';
require_login();
$u=current_user();
$schoolName=get_system_setting($conn,'school_name','Clark College of Science and Technology');
$systemName=get_system_setting($conn,'system_name',APP_NAME);
$schoolLogoUrl=rtrim(BASE_URL,'/').'/'.ltrim(get_system_setting($conn,'school_logo','assets/725698283_122272196210063653_4714758265439461367_n.jpg'),'/');
$roleLabel=match($u['role']){'admin'=>'Administrator','maintenance'=>'Maintenance','staff'=>'Staff',default=>'Inventory User'};
$initials=strtoupper(substr($u['full_name'],0,1).substr(strrchr(' '.$u['full_name'],' '),1,1));
$dashboardShell=in_array($u['role'],['admin','staff'],true);
$maintenanceShell=$u['role']==='maintenance';
$isOverview=($u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='index.php')||($u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='dashboard.php'&&str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']??''),'/staff/'));
$isMaintenanceDashboard=$maintenanceShell&&basename($_SERVER['PHP_SELF'])==='dashboard.php';
$isMaintenanceRequests=$maintenanceShell&&basename($_SERVER['PHP_SELF'])==='requests.php';
$isMaintenanceEquipment=$maintenanceShell&&basename($_SERVER['PHP_SELF'])==='equipment.php';
$isMaintenanceReports=$maintenanceShell&&basename($_SERVER['PHP_SELF'])==='reports.php';
$isInventory=$u['role']!=='maintenance'&&basename($_SERVER['PHP_SELF'])==='inventory.php'&&!isset($_GET['qr_generator'])&&!isset($_GET['qr'])&&!isset($_GET['room_qr']);
$isQrGenerator=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='inventory.php'&&(isset($_GET['qr_generator'])||isset($_GET['qr'])||isset($_GET['room_qr']));
$isAdminRequests=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='approval_requests.php';
$isMaintenanceRecords=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='maintenance_records.php';
$isAdminSettings=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='settings.php';
$isAdminUsers=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='user_management.php';
$isAdminRoles=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='role_permissions.php';
$isAdminNotifications=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='notifications.php';
$isAdminBackup=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='backup.php';
$isAdminProfile=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='profile.php';
$isStaffBorrowRequests=$u['role']==='staff'&&in_array(basename($_SERVER['PHP_SELF']),['borrow_requests.php','requests.php'],true);
$isStaffReleaseReturns=$u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='release_returns.php';
$isStaffOverdue=$u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='overdue.php';
$isStaffProfile=$u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='profile.php';
$isStaffScanner=$u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='scan.php';
$isReports=in_array($u['role'],['admin','staff'],true)&&basename($_SERVER['PHP_SELF'])==='reports.php';
$pendingRequestCount=0;
$maintenanceNotificationCount=0;
if($maintenanceShell){$maintenanceNotificationCount=(int)$conn->query("SELECT COUNT(*) FROM maintenance_requests WHERE assigned_to=".(int)$u['id']." AND status='Pending'")->fetch_row()[0];}
if(in_array($u['role'],['admin','staff'],true)){
	ensure_equipment_requests_table($conn);
	if($u['role']==='admin')$pendingRequestCount=(int)$conn->query("SELECT COUNT(*) FROM equipment_requests WHERE status='pending'")->fetch_row()[0];
	else{$pendingRequestCount=(int)$conn->query("SELECT COUNT(*) FROM equipment_requests WHERE status='pending'")->fetch_row()[0];}
}
$adminNotificationCounts=['overdue'=>0,'low_stock'=>0,'damaged'=>0,'maintenance'=>0,'repair_completed'=>0,'account'=>0];
$adminNotificationCount=$u['role']==='admin'?$pendingRequestCount:0;
$adminNotificationsCleared=false;
if($u['role']==='admin'){
	$conn->query("CREATE TABLE IF NOT EXISTS notification_dismissals (user_id INT NOT NULL,notification_key VARCHAR(190) NOT NULL,dismissed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,PRIMARY KEY(user_id,notification_key),INDEX idx_notification_dismissals_date(user_id,dismissed_at),CONSTRAINT fk_notification_dismissals_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB");
	$clearMarkerQuery=$conn->prepare("SELECT dismissed_at FROM notification_dismissals WHERE user_id=? AND notification_key='__all__' LIMIT 1");
	$clearMarkerQuery->bind_param('i',$u['id']);
	$clearMarkerQuery->execute();
	$adminNotificationsCleared=$clearMarkerQuery->get_result()->fetch_column()!==false;
	require_once __DIR__.'/../staff/functions.php';
	ensure_staff_equipment_transactions_table($conn);
	$overdueQuery=$conn->query("SELECT COUNT(*) FROM equipment_transactions released JOIN equipment_requests r ON r.id=released.request_id WHERE released.action='released' AND r.expected_return_date<CURDATE() AND r.status IN ('approved','returned') AND NOT EXISTS(SELECT 1 FROM equipment_transactions returned WHERE returned.request_id=r.id AND returned.action='returned')");
	$adminNotificationCounts['overdue']=(int)$overdueQuery->fetch_row()[0];
	$inventoryNotices=$conn->query("SELECT COUNT(CASE WHEN status<>'Disposed' AND condition_status='Damaged' THEN 1 END) AS damaged,COUNT(CASE WHEN status<>'Disposed' AND condition_status<>'Damaged' AND (status='Under Repair' OR condition_status='Needs Repair') THEN 1 END) AS maintenance FROM inventory_items")->fetch_assoc();
	$adminNotificationCounts['damaged']=(int)$inventoryNotices['damaged'];
	$adminNotificationCounts['maintenance']=(int)$inventoryNotices['maintenance'];
	$stockThreshold=max(0,(int)get_system_setting($conn,'low_stock_threshold','2'));
	$borrowedSubquery=staff_borrowed_units_subquery();
	$lowStockQuery=$conn->prepare("SELECT COUNT(*) FROM inventory_items i LEFT JOIN $borrowedSubquery b ON b.inventory_item_id=i.id WHERE i.status='Available' AND i.condition_status NOT IN ('Needs Repair','Damaged') AND GREATEST(i.quantity-GREATEST(COALESCE(b.borrowed,0),0),0) BETWEEN 1 AND ?");
	$lowStockQuery->bind_param('i',$stockThreshold);
	$lowStockQuery->execute();
	$adminNotificationCounts['low_stock']=(int)$lowStockQuery->get_result()->fetch_row()[0];
	$adminNotificationCounts['repair_completed']=(int)$conn->query("SELECT COUNT(*) FROM maintenance_history WHERE action='completed' AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_row()[0];
	$adminNotificationCounts['account']=(int)$conn->query("SELECT COUNT(*) FROM activity_logs WHERE action IN ('CREATE USER','UPDATE USER','RESET USER PASSWORD','UPDATE ADMIN PROFILE','CHANGE ADMIN PASSWORD','LOGIN','LOGOUT') AND created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)")->fetch_row()[0];
	$adminNotificationCount+=$adminNotificationCounts['overdue']+$adminNotificationCounts['low_stock']+$adminNotificationCounts['damaged']+$adminNotificationCounts['maintenance']+$adminNotificationCounts['repair_completed']+$adminNotificationCounts['account'];
}
if($adminNotificationsCleared)$adminNotificationCount=0;
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e($systemName)?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?=e(BASE_URL)?>/assets/style.css?v=readable-2" rel="stylesheet">
<link href="<?=e(BASE_URL)?>/assets/system.css?v=notifications-clear-1" rel="stylesheet">
<?php if(basename($_SERVER['PHP_SELF'])==='my_id.php'): ?><link href="<?=e(BASE_URL)?>/assets/id-card.css" rel="stylesheet"><?php endif; ?></head><body class="<?=$dashboardShell?'admin-shell '.($u['role']==='staff'?'staff-shell':''):($maintenanceShell?'maintenance-shell':'')?>">
<nav class="navbar topbar px-3">
<?php if($dashboardShell||$maintenanceShell): ?>
	<button class="app-nav-toggle" type="button" data-app-nav-toggle aria-controls="app-navigation" aria-expanded="false" aria-label="Open navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
<?php endif; ?>
	<a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?=e(BASE_URL)?>/<?=e(role_home())?>"><img class="brand-logo" src="<?=e($schoolLogoUrl)?>" alt="CCST logo" width="32" height="32" style="width:32px;height:32px;max-width:none"><strong>CCST</strong> <span class="brand-system-name">Inventory Management System</span></a>
<?php if($dashboardShell||$maintenanceShell): ?>
	<div class="app-topbar-actions"><?php if($u['role']==='admin'): ?><details class="admin-notification-dropdown"><summary aria-label="Open notifications"><i class="bi bi-bell" aria-hidden="true"></i><?php if($adminNotificationCount>0): ?><span><?=number_format($adminNotificationCount)?></span><?php endif; ?></summary><div class="admin-notification-menu"><strong>System alerts</strong><a href="<?=e(BASE_URL)?>/notifications.php?type=pending">Pending requests <b><?=number_format($pendingRequestCount)?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=overdue">Overdue items <b><?=number_format($adminNotificationCounts['overdue'])?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=low_stock">Low stock <b><?=number_format($adminNotificationCounts['low_stock'])?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=damaged">Damaged <b><?=number_format($adminNotificationCounts['damaged'])?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=maintenance">Under maintenance <b><?=number_format($adminNotificationCounts['maintenance'])?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=repair_completed">Completed repairs <b><?=number_format($adminNotificationCounts['repair_completed'])?></b></a><a href="<?=e(BASE_URL)?>/notifications.php?type=account">Account activity <b><?=number_format($adminNotificationCounts['account'])?></b></a><a class="admin-notification-view-all" href="<?=e(BASE_URL)?>/notifications.php">View all notifications <i class="bi bi-arrow-right" aria-hidden="true"></i></a></div></details><?php endif; ?><span class="app-topbar-role"><?=e($roleLabel)?></span></div>
<?php else: ?>
	<div class="text-white small"><?=e($roleLabel)?> <a class="btn btn-sm btn-light ms-2" href="<?=e(BASE_URL)?>/logout.php">Logout</a></div>
<?php endif; ?>
</nav>
<div class="app"><?php if($dashboardShell||$maintenanceShell): ?><button class="app-nav-backdrop" type="button" data-app-nav-backdrop aria-label="Close navigation" hidden></button><?php endif; ?><aside class="sidebar"<?=($dashboardShell||$maintenanceShell)?' id="app-navigation"':''?>>
<?php if($u['role']==='staff'): ?><div class="staff-sidebar-section-label">MAIN MENU</div><?php elseif($u['role']==='admin'): ?><div class="admin-sidebar-section-label">MAIN MENU</div><?php endif; ?>
<?php if(!$maintenanceShell): ?><a class="<?=$isOverview?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/<?=e(role_home())?>"><i class="bi bi-grid-1x2-fill nav-icon" aria-hidden="true"></i>Dashboard</a><?php endif; ?>
<?php if($u['role']==='admin'): ?>
<a class="<?=$isAdminUsers?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/user_management.php"><i class="bi bi-people nav-icon" aria-hidden="true"></i>User Management</a>
<a class="<?=$isAdminRoles?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/role_permissions.php"><i class="bi bi-shield-lock nav-icon" aria-hidden="true"></i>Role &amp; Permissions</a>
<div class="admin-sidebar-section-label">INVENTORY MANAGEMENT</div>
<a class="<?=$isInventory?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/inventory.php"><i class="bi bi-box-seam nav-icon" aria-hidden="true"></i>Inventory Items</a>
<a class="<?=$isQrGenerator?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/inventory.php?qr_generator=1#inventory-list"><i class="bi bi-qr-code nav-icon" aria-hidden="true"></i>QR Code Generator</a>
<a href="<?=e(BASE_URL)?>/scan.php"><i class="bi bi-qr-code-scan nav-icon" aria-hidden="true"></i>QR Scanner</a>
<div class="admin-sidebar-section-label">TRANSACTIONS</div>
<a class="<?=$isAdminRequests?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/approval_requests.php?status=pending"><i class="bi bi-shield-check nav-icon" aria-hidden="true"></i>Approval Requests<?php if($pendingRequestCount>0): ?><span class="nav-count"><?=number_format($pendingRequestCount)?></span><?php endif; ?></a>
<a class="<?=$isMaintenanceRecords?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance_records.php"><i class="bi bi-wrench-adjustable nav-icon" aria-hidden="true"></i>Maintenance Records</a>
<div class="admin-sidebar-section-label">REPORTS &amp; MONITORING</div>
<a class="<?=$isReports?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/reports.php"><i class="bi bi-bar-chart-line nav-icon" aria-hidden="true"></i>Reports &amp; Analytics</a>
<a class="<?=basename($_SERVER['PHP_SELF'])==='logs.php'?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/logs.php"><i class="bi bi-activity nav-icon" aria-hidden="true"></i>Activity Logs</a>
<div class="admin-sidebar-section-label">SYSTEM</div>
<a class="<?=$isAdminNotifications?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/notifications.php"><i class="bi bi-bell nav-icon" aria-hidden="true"></i>Notifications</a>
<a class="<?=$isAdminSettings?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/settings.php"><i class="bi bi-gear nav-icon" aria-hidden="true"></i>Settings</a>
<a class="<?=$isAdminBackup?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/backup.php"><i class="bi bi-database-down nav-icon" aria-hidden="true"></i>Backup &amp; Restore</a>
<a class="<?=$isAdminProfile?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/profile.php"><i class="bi bi-person-circle nav-icon" aria-hidden="true"></i>Profile</a>
<a href="<?=e(BASE_URL)?>/logout.php"><i class="bi bi-box-arrow-right nav-icon" aria-hidden="true"></i>Logout</a>
<?php elseif($u['role']==='staff'): ?>
<a class="<?=$isInventory?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/inventory.php"><i class="bi bi-box-seam nav-icon" aria-hidden="true"></i>Equipment</a>
<a class="<?=$isStaffScanner?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/scan.php"><i class="bi bi-qr-code-scan nav-icon" aria-hidden="true"></i>QR Scanner</a>
<div class="staff-sidebar-section-label">TRANSACTIONS</div>
<a class="<?=$isStaffBorrowRequests?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/staff/borrow_requests.php"><i class="bi bi-clipboard-check nav-icon" aria-hidden="true"></i>Borrow Requests<?php if($pendingRequestCount>0): ?><span class="nav-count"><?=number_format($pendingRequestCount)?></span><?php endif; ?></a>
<a class="<?=$isStaffReleaseReturns?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/staff/release_returns.php"><i class="bi bi-arrow-left-right nav-icon" aria-hidden="true"></i>Release &amp; Returns</a>
<a class="<?=$isStaffOverdue?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/staff/overdue.php"><i class="bi bi-clock-history nav-icon" aria-hidden="true"></i>Overdue Items</a>
<div class="staff-sidebar-section-label">REPORTS</div>
<a class="<?=$isReports?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/reports.php"><i class="bi bi-bar-chart-line nav-icon" aria-hidden="true"></i>Reports</a>
<a class="<?=$isStaffProfile?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/staff/profile.php"><i class="bi bi-person-circle nav-icon" aria-hidden="true"></i>Profile</a>
<a href="<?=e(BASE_URL)?>/logout.php"><i class="bi bi-box-arrow-right nav-icon" aria-hidden="true"></i>Logout</a>
<?php elseif($u['role']==='maintenance'): ?>
<div class="maintenance-sidebar-section-label">MAIN MENU</div>
<a class="<?=$isMaintenanceDashboard?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance/dashboard.php"><i class="bi bi-grid-1x2-fill nav-icon" aria-hidden="true"></i>Dashboard</a>
<a class="<?=basename($_SERVER['PHP_SELF'])==='maintenance_records.php'?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance_records.php"><i class="bi bi-journal-check nav-icon" aria-hidden="true"></i>Maintenance Records</a>
<a class="<?=$isMaintenanceRequests?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance/requests.php"><i class="bi bi-clipboard2-pulse nav-icon" aria-hidden="true"></i>Repair Requests<?php if($maintenanceNotificationCount>0): ?><span class="nav-count"><?=number_format($maintenanceNotificationCount)?></span><?php endif; ?></a>
<a class="<?=$isMaintenanceEquipment?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance/equipment.php"><i class="bi bi-box-seam nav-icon" aria-hidden="true"></i>Equipment Status</a>
<a class="<?=basename($_SERVER['PHP_SELF'])==='scan.php'?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/scan.php"><i class="bi bi-qr-code-scan nav-icon" aria-hidden="true"></i>QR Scanner</a>
<div class="maintenance-sidebar-section-label">REPORTS</div>
<a class="<?=$isMaintenanceReports?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance/reports.php"><i class="bi bi-bar-chart-line nav-icon" aria-hidden="true"></i>Maintenance Reports</a>
<a href="<?=e(BASE_URL)?>/maintenance_records.php"><i class="bi bi-clock-history nav-icon" aria-hidden="true"></i>Repair History</a>
<div class="maintenance-sidebar-section-label">ACCOUNT</div>
<a class="<?=basename($_SERVER['PHP_SELF'])==='profile.php'&&str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']??''),'/maintenance/')?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/maintenance/profile.php"><i class="bi bi-person-circle nav-icon" aria-hidden="true"></i>Profile</a>
<a href="<?=e(BASE_URL)?>/maintenance/requests.php?status=Pending"><i class="bi bi-bell nav-icon" aria-hidden="true"></i>Notifications<?php if($maintenanceNotificationCount>0): ?><span class="nav-count"><?=number_format($maintenanceNotificationCount)?></span><?php endif; ?></a>
<a href="<?=e(BASE_URL)?>/logout.php"><i class="bi bi-box-arrow-right nav-icon" aria-hidden="true"></i>Logout</a>
<?php endif; ?>
<?php if($dashboardShell): ?>
<div class="admin-sidebar-account"><span class="admin-user-avatar"><?=e($initials)?></span><span class="admin-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e($roleLabel)?></small></span></div>
<?php endif; ?>
</aside><main class="content"><?php show_flash(); ?>
