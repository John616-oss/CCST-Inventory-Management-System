<?php
require_once __DIR__.'/../includes/functions.php'; require_role(['teacher']);
$uid=(int)current_user()['id']; $t=$conn->prepare("SELECT id,employee_no,first_name,last_name FROM teachers WHERE user_id=? LIMIT 1"); $t->bind_param('i',$uid); $t->execute(); $teacher=$t->get_result()->fetch_assoc();
if(!$teacher){
	$email=current_user()['email'];
	$byEmail=$conn->prepare("SELECT id,user_id FROM teachers WHERE email=? LIMIT 1");
	$byEmail->bind_param('s',$email);$byEmail->execute();$existing=$byEmail->get_result()->fetch_assoc();
	if($existing&&!$existing['user_id']){
		$link=$conn->prepare("UPDATE teachers SET user_id=? WHERE id=? AND user_id IS NULL");
		$link->bind_param('ii',$uid,$existing['id']);$link->execute();
	}elseif(!$existing){
		$parts=preg_split('/\s+/',trim(current_user()['full_name']),2);
		$firstName=$parts[0]??'Teacher';$lastName=$parts[1]??'Teacher';
		$prefix='TCH-'.date('Y').'-';
		$count=$conn->prepare("SELECT COUNT(*)+1 n FROM teachers WHERE employee_no LIKE CONCAT(?, '%')");
		$count->bind_param('s',$prefix);$count->execute();$number=(int)$count->get_result()->fetch_assoc()['n'];
		$employeeNo=$prefix.str_pad((string)$number,4,'0',STR_PAD_LEFT);
		$create=$conn->prepare("INSERT INTO teachers(user_id,employee_no,first_name,last_name,email) VALUES(?,?,?,?,?)");
		$create->bind_param('issss',$uid,$employeeNo,$firstName,$lastName,$email);$create->execute();
	}
	$t->execute();$teacher=$t->get_result()->fetch_assoc();
}
$classes=0; $today=0; if($teacher){$q=$conn->prepare("SELECT COUNT(*) FROM teacher_subjects WHERE teacher_id=?");$q->bind_param('i',$teacher['id']);$q->execute();$classes=(int)$q->get_result()->fetch_row()[0];$q=$conn->prepare("SELECT COUNT(*) FROM attendance WHERE teacher_id=? AND attendance_date=CURDATE()");$q->bind_param('i',$teacher['id']);$q->execute();$today=(int)$q->get_result()->fetch_row()[0];}
include __DIR__.'/../includes/header.php'; ?>
<h3 class="mb-4">Teacher Dashboard</h3>
<?php if(!$teacher): ?><div class="alert alert-warning">Wala pang teacher profile na naka-link sa account mo. Ipa-link sa Registrar ang iyong account sa Teachers module.</div><?php else: ?><p class="text-muted">Welcome, <?=e($teacher['first_name'].' '.$teacher['last_name'])?> · <?=e($teacher['employee_no'])?></p><?php endif; ?>
<div class="row g-3 mb-4"><div class="col-md-4"><div class="stat"><span>Assigned Classes</span><strong><?=$classes?></strong></div></div><div class="col-md-4"><div class="stat"><span>Attendance Today</span><strong><?=$today?></strong></div></div></div>
<div class="panel"><h5>Teacher Actions</h5><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="my_classes.php">View My Classes</a><a class="btn btn-success" href="<?=e(BASE_URL)?>/attendance.php">Open QR Attendance</a><a class="btn btn-outline-secondary" href="<?=e(BASE_URL)?>/reports.php">Attendance Reports</a></div></div>
<?php include __DIR__.'/../includes/footer.php'; ?>