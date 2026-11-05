<?php
require_once __DIR__.'/functions.php';
require_login();
$u=current_user();
$roleLabel=match($u['role']){'admin'=>'Administrator','maintenance'=>'Maintenance','staff'=>'Staff',default=>'Inventory User'};
$initials=strtoupper(substr($u['full_name'],0,1).substr(strrchr(' '.$u['full_name'],' '),1,1));
$dashboardShell=in_array($u['role'],['admin','staff'],true);
$isOverview=($u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='index.php')||($u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='dashboard.php'&&str_contains(str_replace('\\','/',$_SERVER['SCRIPT_NAME']??''),'/staff/'));
$isAdminRequests=$u['role']==='admin'&&basename($_SERVER['PHP_SELF'])==='approval_requests.php';
$isStaffRequests=$u['role']==='staff'&&basename($_SERVER['PHP_SELF'])==='requests.php';
$isReports=in_array($u['role'],['admin','staff'],true)&&basename($_SERVER['PHP_SELF'])==='reports.php';
$pendingRequestCount=0;
if(in_array($u['role'],['admin','staff'],true)){
	ensure_equipment_requests_table($conn);
	if($u['role']==='admin')$pendingRequestCount=(int)$conn->query("SELECT COUNT(*) FROM equipment_requests WHERE status='pending'")->fetch_row()[0];
	else{$pendingQuery=$conn->prepare("SELECT COUNT(*) FROM equipment_requests WHERE requester_id=? AND status='pending'");$pendingQuery->bind_param('i',$u['id']);$pendingQuery->execute();$pendingRequestCount=(int)$pendingQuery->get_result()->fetch_row()[0];}
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=e(APP_NAME)?></title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?=e(BASE_URL)?>/assets/style.css?v=readable-2" rel="stylesheet">
<link href="<?=e(BASE_URL)?>/assets/system.css?v=readable-2" rel="stylesheet">
<?php if(basename($_SERVER['PHP_SELF'])==='my_id.php'): ?><link href="<?=e(BASE_URL)?>/assets/id-card.css" rel="stylesheet"><?php endif; ?></head><body class="<?=$dashboardShell?'admin-shell '.($u['role']==='staff'?'staff-shell':''):''?>">
<nav class="navbar topbar px-3">
<?php if($dashboardShell): ?>
	<div class="admin-topbar-copy"><strong>Welcome, <?=e($u['full_name'])?>!</strong><span>CCST Inventory Management System · <?=e($roleLabel)?></span></div>
	<div class="admin-topbar-actions"><span class="admin-topbar-date"><i class="bi bi-calendar3" aria-hidden="true"></i><?=e(date('F j, Y'))?></span><span class="admin-topbar-role"><i class="bi bi-person-gear" aria-hidden="true"></i><?=e($roleLabel)?></span></div>
<?php else: ?>
	<a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="<?=e(BASE_URL)?>/<?=e(role_home())?>"><img class="brand-logo" src="<?=e(BASE_URL)?>/assets/725698283_122272196210063653_4714758265439461367_n.jpg" alt="Clark College of Science and Technology logo" width="32" height="32" style="width:32px;height:32px;max-width:none">CCST <span class="brand-system-name">School Inventory</span></a><div class="text-white small"><?=e($roleLabel)?> <a class="btn btn-sm btn-light ms-2" href="<?=e(BASE_URL)?>/logout.php">Logout</a></div>
<?php endif; ?>
</nav>
<div class="app"><aside class="sidebar">
<?php if($dashboardShell): ?>
<a class="admin-sidebar-brand" href="<?=e(BASE_URL)?>/index.php"><img src="<?=e(BASE_URL)?>/assets/725698283_122272196210063653_4714758265439461367_n.jpg" alt="CCST logo"><span><strong>CCST</strong><small>Inventory<br>Management System</small></span></a>
<?php endif; ?>
<a class="<?=$isOverview?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/<?=e(role_home())?>"><i class="bi bi-grid-1x2-fill nav-icon" aria-hidden="true"></i>Dashboard</a>
<?php if($u['role']!=='maintenance'): ?>
<a href="<?=e(BASE_URL)?>/inventory.php"><i class="bi bi-box-seam nav-icon" aria-hidden="true"></i>Equipment</a>
<?php endif; ?>
<a href="<?=e(BASE_URL)?>/scan.php"><i class="bi bi-qr-code-scan nav-icon" aria-hidden="true"></i>QR Scanner</a>
<?php if($u['role']==='maintenance'): ?>
<a href="<?=e(BASE_URL)?>/maintenance/profile.php"><i class="bi bi-person-circle nav-icon" aria-hidden="true"></i>My Profile</a>
<?php endif; ?>
<?php if($u['role']==='admin'): ?>
<a class="<?=$isAdminRequests?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/approval_requests.php"><i class="bi bi-shield-check nav-icon" aria-hidden="true"></i>Approval Requests<?php if($pendingRequestCount>0): ?><span class="nav-count"><?=number_format($pendingRequestCount)?></span><?php endif; ?></a>
<a class="<?=$isReports?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/reports.php"><i class="bi bi-bar-chart-line nav-icon" aria-hidden="true"></i>Reports</a>
<a href="<?=e(BASE_URL)?>/logs.php"><i class="bi bi-activity nav-icon" aria-hidden="true"></i>Activity Logs</a>
<?php elseif($u['role']==='staff'): ?>
<a class="<?=$isStaffRequests?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/requests.php"><i class="bi bi-clipboard-check nav-icon" aria-hidden="true"></i>My Requests<?php if($pendingRequestCount>0): ?><span class="nav-count"><?=number_format($pendingRequestCount)?></span><?php endif; ?></a>
<a class="<?=$isReports?'admin-nav-active':''?>" href="<?=e(BASE_URL)?>/reports.php"><i class="bi bi-bar-chart-line nav-icon" aria-hidden="true"></i>Reports</a>
<?php endif; ?>
<?php if($dashboardShell): ?>
<div class="admin-sidebar-account"><span class="admin-user-avatar"><?=e($initials)?></span><span class="admin-user-copy"><strong><?=e($u['full_name'])?></strong><small><?=e($roleLabel)?></small></span><a class="admin-logout" href="<?=e(BASE_URL)?>/logout.php" aria-label="Logout" title="Logout"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></a></div>
<?php endif; ?>
</aside><main class="content"><?php show_flash(); ?>
