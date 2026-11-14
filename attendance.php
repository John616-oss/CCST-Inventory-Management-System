<?php
require_once __DIR__.'/includes/functions.php'; require_role(['admin','staff']); $msg='';$msgType='info';
$role=current_user()['role']; $teacherId=null;
if($role==='staff'){
  $uid=(int)current_user()['id']; $t=$conn->prepare("SELECT id FROM teachers WHERE user_id=? LIMIT 1"); $t->bind_param('i',$uid); $t->execute(); $teacherId=$t->get_result()->fetch_column();
  if(!$teacherId){ http_response_code(403); exit('Teacher profile is not linked. Please contact the Admin.'); }
}
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check(); $code=trim($_POST['student_code']??''); $subid=(int)($_POST['subject_id']??0);
 $s=$conn->prepare("SELECT id,section_id FROM students WHERE student_code=? AND status='active'"); $s->bind_param('s',$code); $s->execute(); $stu=$s->get_result()->fetch_assoc();
 if(!$stu){$msg='Student not found or inactive.';$msgType='error';}
 else {
  try {
  if($role==='staff'){
    if($subid>0){$check=$conn->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND subject_id=? AND section_id=? LIMIT 1");$check->bind_param('iii',$teacherId,$subid,$stu['section_id']);}
    else {$check=$conn->prepare("SELECT id FROM teacher_subjects WHERE teacher_id=? AND section_id=? LIMIT 1");$check->bind_param('ii',$teacherId,$stu['section_id']);}
    $check->execute(); if(!$check->get_result()->num_rows) throw new RuntimeException('not_assigned');
   }
   $date=date('Y-m-d'); $time=date('H:i:s'); $status=(date('H')>=8?'late':'present');
   if($subid===0){$dupe=$conn->prepare("SELECT id FROM attendance WHERE student_id=? AND subject_id IS NULL AND attendance_date=?");$dupe->bind_param('is',$stu['id'],$date);}
   else {$dupe=$conn->prepare("SELECT id FROM attendance WHERE student_id=? AND subject_id=? AND attendance_date=?");$dupe->bind_param('iis',$stu['id'],$subid,$date);}
   $dupe->execute(); if($dupe->get_result()->num_rows) throw new RuntimeException('duplicate');
   $sectionId=$stu['section_id']; $a=$conn->prepare("INSERT INTO attendance(student_id,teacher_id,subject_id,section_id,attendance_date,time_in,status) VALUES(?,?,?,?,?,?,?)");
   $subjectValue=$subid?:null; $a->bind_param('iiiisss',$stu['id'],$teacherId,$subjectValue,$sectionId,$date,$time,$status); $a->execute();
  log_action('ATTENDANCE',"Recorded $code"); $msg="Attendance recorded for $code.";$msgType='success';
  } catch(Throwable $e){ $msg=$e->getMessage()==='not_assigned'?'You are not assigned to this student’s section/subject.':'Attendance already recorded for this student, subject and date, or invalid subject.';$msgType='error'; }
 }
}
if($role==='staff'){$subjects=$conn->prepare("SELECT DISTINCT s.id,s.subject_code,s.subject_name FROM subjects s JOIN teacher_subjects ts ON ts.subject_id=s.id WHERE ts.teacher_id=? ORDER BY s.subject_code");$subjects->bind_param('i',$teacherId);$subjects->execute();$subjectRows=$subjects->get_result();}
else {$subjectRows=$conn->query("SELECT id,subject_code,subject_name FROM subjects ORDER BY subject_code");}
include 'includes/header.php'; ?>
<h3 class="mb-3">QR Scanner - Attendance</h3><?php if($msg): ?><div class="alert <?=e($msgType)?>" role="status"><?=e($msg)?></div><?php endif; ?>
<div class="row g-3"><div class="col-lg-7"><div class="panel"><div class="d-flex gap-2 mb-3"><button class="btn btn-primary" id="startScan">Start Camera Scanner</button><button class="btn btn-outline-secondary" id="stopScan">Stop</button></div><div id="reader" style="max-width:520px"></div><div id="scanSuccess" class="alert success d-none mt-3" role="status" aria-live="polite"></div><form method="post" class="mt-3" id="attForm"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="form-label">Student ID / QR value</label><input class="form-control mb-3" id="studentCode" name="student_code" placeholder="CCST-2026-0001" required><label class="form-label">Subject</label><select class="form-select mb-3" name="subject_id"><option value="0">General attendance</option><?php while($s=$subjectRows->fetch_assoc()): ?><option value="<?=$s['id']?>"><?=e($s['subject_code'].' - '.$s['subject_name'])?></option><?php endwhile; ?></select><button class="btn btn-success w-100">Record Attendance</button></form></div></div>
<div class="col-lg-5"><div class="panel"><h5>How to scan</h5><ol><li>Allow camera permission.</li><li>Choose the subject before scanning.</li><li>Point the camera at a CCST student QR code.</li><li>Attendance is recorded automatically after a successful scan.</li></ol><p class="muted small">Camera scanning requires HTTPS or localhost. USB scanners that type into the input field can still use the Record Attendance button.</p></div></div></div>
<script>let scanner=null;let scanHandled=false;const scanSuccess=document.getElementById('scanSuccess');document.getElementById('startScan').onclick=async()=>{if(!window.Html5Qrcode){alert('Scanner library did not load.');return;}scanner=new Html5Qrcode('reader');scanHandled=false;scanSuccess.classList.add('d-none');try{await scanner.start({facingMode:'environment'},{fps:10,qrbox:240},txt=>{if(scanHandled)return;scanHandled=true;const code=txt.trim();document.getElementById('studentCode').value=code;scanSuccess.textContent='Scan successful: '+code+'. Recording attendance...';scanSuccess.classList.remove('d-none');scanner.stop().catch(()=>{});document.getElementById('attForm').requestSubmit();});}catch(e){alert('Unable to start camera: '+e);}};document.getElementById('stopScan').onclick=()=>{if(scanner)scanner.stop().catch(()=>{});};</script>
<?php include 'includes/footer.php'; ?>