<?php
require_once __DIR__.'/../includes/functions.php'; require_role(['student']);
$uid=(int)current_user()['id']; $q=$conn->prepare("SELECT s.*,c.code course_code,se.section_name FROM students s LEFT JOIN courses c ON c.id=s.course_id LEFT JOIN sections se ON se.id=s.section_id WHERE s.user_id=? LIMIT 1");$q->bind_param('i',$uid);$q->execute();$st=$q->get_result()->fetch_assoc();
if(!$st){
	$email=current_user()['email'];
	$s=$conn->prepare("SELECT id FROM students WHERE email=? AND user_id IS NULL LIMIT 1");
	$s->bind_param('s',$email);$s->execute();$existing=$s->get_result()->fetch_assoc();
	if($existing){
		$link=$conn->prepare("UPDATE students SET user_id=? WHERE id=? AND user_id IS NULL");
		$link->bind_param('ii',$uid,$existing['id']);$link->execute();
	}else{
		$parts=preg_split('/\s+/',trim(current_user()['full_name']),2);
		$firstName=$parts[0]??'Student';$lastName=$parts[1]??'Student';
		$studentCode=make_student_code($conn);
		$create=$conn->prepare("INSERT INTO students(user_id,student_code,first_name,last_name,email) VALUES(?,?,?,?,?)");
		$create->bind_param('issss',$uid,$studentCode,$firstName,$lastName,$email);$create->execute();
	}
	$q->execute();$st=$q->get_result()->fetch_assoc();
}
include __DIR__.'/../includes/header.php'; ?><h3 class="mb-4">Student Dashboard</h3>
<?php if(!$st): ?><div class="alert alert-warning">Hindi pa naka-link ang account mo sa student record. Makipag-ugnayan sa Registrar para mai-link ang iyong student profile.</div><?php else: ?><div class="panel mb-3"><h5>Hello, <?=e($st['first_name'].' '.$st['last_name'])?>!</h5><p class="mb-1">Student Number: <strong><?=e($st['student_code'])?></strong></p><p class="mb-1">Course: <?=e($st['course_code']??'Not assigned')?></p><p class="mb-0">Section: <?=e($st['section_name']??'Not assigned')?> · Year <?=e($st['year_level']??'—')?></p></div><?php endif; ?>
<div class="panel"><h5>My Student Services</h5><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="profile.php">My Profile</a><a class="btn btn-outline-primary" href="<?=e(BASE_URL)?>/my_id.php">View / Print Student ID</a><a class="btn btn-outline-secondary" href="attendance.php">My Attendance</a></div></div>
<?php include __DIR__.'/../includes/footer.php'; ?>