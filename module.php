<?php
require_once __DIR__.'/includes/functions.php';require_role(['admin']);
$defs=[
'students'=>['title'=>'Students','table'=>'students','fields'=>['first_name'=>'First Name','middle_name'=>'Middle Name','last_name'=>'Last Name','email'=>'Email','phone'=>'Phone','course_id'=>'Course','section_id'=>'Section','year_level'=>'Year Level'],'search'=>'student_code,first_name,last_name,email'],
'teachers'=>['title'=>'Teachers','table'=>'teachers','fields'=>['employee_no'=>'Employee No.','first_name'=>'First Name','last_name'=>'Last Name','email'=>'Email'],'search'=>'employee_no,first_name,last_name,email'],
'teacher_assignments'=>['title'=>'Teacher Assignments','table'=>'teacher_subjects','fields'=>['teacher_id'=>'Teacher','subject_id'=>'Subject','section_id'=>'Section','schedule'=>'Schedule'],'search'=>'schedule'],
'courses'=>['title'=>'Courses','table'=>'courses','fields'=>['code'=>'Course Code','name'=>'Course Name','description'=>'Description'],'search'=>'code,name'],
'sections'=>['title'=>'Sections','table'=>'sections','fields'=>['course_id'=>'Course','year_level'=>'Year Level','section_name'=>'Section Name'],'search'=>'section_name'],
'subjects'=>['title'=>'Subjects','table'=>'subjects','fields'=>['subject_code'=>'Subject Code','subject_name'=>'Subject Name','units'=>'Units'],'search'=>'subject_code,subject_name'],
'enrollments'=>['title'=>'Enrollments','table'=>'enrollments','fields'=>['student_id'=>'Student','section_id'=>'Section','school_year'=>'School Year','semester'=>'Semester'],'search'=>'school_year,semester']
];
$m=$_GET['m']??'students';if(!isset($defs[$m]))exit('Invalid module');$d=$defs[$m];$table=$d['table'];$edit=null;$courseOptions=[];$sectionOptions=[];$courseLabels=[];$sectionLabels=[];$teacherOptions=[];$subjectOptions=[];$assignmentSectionOptions=[];$teacherLabels=[];$subjectLabels=[];$assignmentSectionLabels=[];$enrollmentStudents=[];$enrollmentSections=[];$enrollmentStudentLabels=[];$enrollmentSectionLabels=[];
if($m==='students'||$m==='sections'){
 $courseRows=$conn->query("SELECT id,code,name FROM courses ORDER BY code");while($course=$courseRows->fetch_assoc()){$courseOptions[]=$course;$courseLabels[$course['id']]=$course['code'].' - '.$course['name'];}
}
if($m==='students'){
 $sectionRows=$conn->query("SELECT s.id,s.course_id,s.section_name,s.year_level,c.code FROM sections s JOIN courses c ON c.id=s.course_id ORDER BY c.code,s.year_level,s.section_name");while($section=$sectionRows->fetch_assoc()){$sectionOptions[]=$section;$sectionLabels[$section['id']]=$section['code'].' / '.$section['section_name'].' (Year '.$section['year_level'].')';}
}
if($m==='teacher_assignments'){
 $teacherRows=$conn->query("SELECT id,employee_no,first_name,last_name FROM teachers ORDER BY last_name,first_name");while($teacher=$teacherRows->fetch_assoc()){$teacherOptions[]=$teacher;$teacherLabels[$teacher['id']]=$teacher['employee_no'].' - '.$teacher['first_name'].' '.$teacher['last_name'];}
 $subjectRows=$conn->query("SELECT id,subject_code,subject_name FROM subjects ORDER BY subject_code");while($subject=$subjectRows->fetch_assoc()){$subjectOptions[]=$subject;$subjectLabels[$subject['id']]=$subject['subject_code'].' - '.$subject['subject_name'];}
 $sectionRows=$conn->query("SELECT s.id,s.section_name,s.year_level,c.code FROM sections s JOIN courses c ON c.id=s.course_id ORDER BY c.code,s.year_level,s.section_name");while($section=$sectionRows->fetch_assoc()){$assignmentSectionOptions[]=$section;$assignmentSectionLabels[$section['id']]=$section['code'].' / '.$section['section_name'].' (Year '.$section['year_level'].')';}
}
if($m==='enrollments'){
 $studentRows=$conn->query("SELECT id,student_code,first_name,middle_name,last_name FROM students WHERE status='active' ORDER BY last_name,first_name");while($student=$studentRows->fetch_assoc()){$enrollmentStudents[]=$student;$middle=trim($student['middle_name']??'');$name=trim($student['first_name'].' '.($middle!==''?$middle.' ':'').$student['last_name']);$enrollmentStudentLabels[$student['id']]=$student['student_code'].' - '.$name;}
 $sectionRows=$conn->query("SELECT s.id,s.section_name,s.year_level,c.code FROM sections s JOIN courses c ON c.id=s.course_id ORDER BY c.code,s.year_level,s.section_name");while($section=$sectionRows->fetch_assoc()){$enrollmentSections[]=$section;$enrollmentSectionLabels[$section['id']]=$section['code'].' / '.$section['section_name'].' (Year '.$section['year_level'].')';}
}
if(isset($_GET['delete'])){$id=(int)$_GET['delete'];$s=$conn->prepare("DELETE FROM `$table` WHERE id=?");$s->bind_param('i',$id);try{$s->execute();log_action('DELETE',"$table #$id");flash('Record deleted.');}catch(Throwable $e){flash('Cannot delete record referenced by other records. Archive it or remove dependencies first.','error');}redirect("module.php?m=$m");}
if($_SERVER['REQUEST_METHOD']==='POST'){csrf_check();$id=(int)($_POST['id']??0);$cols=array_keys($d['fields']);$vals=[];foreach($cols as $c){$raw=trim($_POST[$c]??'');$isRelationId=($m==='students'&&in_array($c,['course_id','section_id'],true))||($m==='sections'&&$c==='course_id')||($m==='teacher_assignments'&&in_array($c,['teacher_id','subject_id','section_id'],true))||($m==='enrollments'&&in_array($c,['student_id','section_id'],true));$vals[$c]=$isRelationId?($raw===''?null:(int)$raw):$raw;}
 if($m==='students'&&!$id){$vals['student_code']=make_student_code($conn);$cols=array_merge(['student_code'],$cols);}
 if($m==='students'&&$vals['section_id']!==null){$sectionCheck=$conn->prepare("SELECT id FROM sections WHERE id=? AND course_id=?");$sectionCheck->bind_param('ii',$vals['section_id'],$vals['course_id']);$sectionCheck->execute();if(!$sectionCheck->get_result()->fetch_assoc()){flash('Choose a section belonging to the selected course.','error');redirect("module.php?m=$m");}}
 $safeCols=array_map(fn($c)=>"`$c`",$cols);
 if($id){$sets=implode(',',array_map(fn($c)=>"`$c`=?",array_keys($vals)));$types=str_repeat('s',count($vals)).'i';$params=array_values($vals);$params[]=$id;$stmt=$conn->prepare("UPDATE `$table` SET $sets WHERE id=?");stmt_bind($stmt,$types,$params);}
 else{$marks=implode(',',array_fill(0,count($vals),'?'));$types=str_repeat('s',count($vals));$params=array_values($vals);$stmt=$conn->prepare("INSERT INTO `$table` (".implode(',',$safeCols).") VALUES($marks)");stmt_bind($stmt,$types,$params);}
 try{$stmt->execute();log_action($id?'UPDATE':'CREATE',"$table record");flash('Record saved successfully.');}catch(mysqli_sql_exception $e){error_log("Module save failed for $table: ".$e->getMessage());$code=(int)$e->getCode();$message=match($code){1062=>'A record with the same unique code or name already exists.',1048,1364=>'Complete all required fields before saving.',1452=>'The selected course or section is invalid. Refresh the page and choose an available option.',default=>'Save failed. Check the selected values and try again.'};flash($message,'error');}catch(Throwable $e){error_log("Module save failed for $table: ".$e->getMessage());flash('Save failed. Check the selected values and try again.','error');}
 redirect("module.php?m=$m");
}
if(isset($_GET['edit'])){$id=(int)$_GET['edit'];$s=$conn->prepare("SELECT * FROM `$table` WHERE id=?");$s->bind_param('i',$id);$s->execute();$edit=$s->get_result()->fetch_assoc();}
$q=trim($_GET['q']??'');$where='';if($q!==''){$parts=explode(',',$d['search']);$where=' WHERE '.implode(' OR ',array_map(fn($c)=>"`$c` LIKE ?", $parts));}
$sql="SELECT * FROM `$table`".$where." ORDER BY id DESC LIMIT 250";$stmt=$conn->prepare($sql);if($q!==''){$params=array_fill(0,count(explode(',',$d['search'])),'%'.$q.'%');$types=str_repeat('s',count($params));stmt_bind($stmt,$types,$params);}$stmt->execute();$rows=$stmt->get_result();
include 'includes/header.php';?>
<?php if($m==='students'): ?>
<div class="student-management">
<header class="dashboard-titlebar students-page-heading"><div><p class="dashboard-eyebrow">ADMIN RECORDS</p><h1>Students</h1><p class="dashboard-subtitle">Manage student profiles, course placement, and enrollment details.</p></div><a class="btn btn-primary" href="module.php?m=students"><i class="bi bi-person-plus-fill" aria-hidden="true"></i> Add student</a></header>
<?php else: ?>
<div class="d-flex justify-content-between align-items-center mb-3"><h3><?=e($d['title'])?></h3><a class="btn btn-outline-secondary" href="module.php?m=<?=$m?>">New record</a></div>
<?php endif; ?>
<div class="panel mb-3 <?= $m==='students'?'student-form-panel':'' ?>">
<?php if($m==='students'): ?><div class="student-form-heading"><div><p class="dashboard-eyebrow">STUDENT DETAILS</p><h2><?=$edit?'Edit student':'Add a student'?></h2></div><?php if($edit): ?><a class="student-cancel-link" href="module.php?m=students">Cancel edit</a><?php endif; ?></div><?php else: ?><h5><?=$edit?'Edit':'Add'?> <?=e($d['title'])?> Record</h5><?php endif; ?>
<form method="post" class="row g-3"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="id" value="<?=e($edit['id']??0)?>">
<?php foreach($d['fields'] as $field=>$label): ?>
<div class="col-md-4">
<label class="form-label" for="field_<?=e($field)?>"><?=e($label)?></label>
<?php if(($m==='students'||$m==='sections')&&$field==='course_id'): ?>
<select class="form-select" id="field_course_id" name="course_id" required><option value="">Select course</option>
<?php foreach($courseOptions as $course): ?><option value="<?=e($course['id'])?>" <?=((string)($edit['course_id']??'')===(string)$course['id'])?'selected':''?>><?=e($course['code'].' - '.$course['name'])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='students'&&$field==='section_id'): ?>
<select class="form-select" id="field_section_id" name="section_id"><option value="">No section assigned</option>
<?php foreach($sectionOptions as $section): ?><option value="<?=e($section['id'])?>" data-course="<?=e($section['course_id'])?>" <?=((string)($edit['section_id']??'')===(string)$section['id'])?'selected':''?>><?=e($section['section_name'].' - Year '.$section['year_level'])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='enrollments'&&$field==='student_id'): ?>
<select class="form-select" id="field_student_id" name="student_id" required><option value="">Select student</option>
<?php foreach($enrollmentStudents as $student): ?><option value="<?=e($student['id'])?>" <?=((string)($edit['student_id']??'')===(string)$student['id'])?'selected':''?>><?=e($enrollmentStudentLabels[$student['id']])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='enrollments'&&$field==='section_id'): ?>
<select class="form-select" id="field_section_id" name="section_id" required><option value="">Select section</option>
<?php foreach($enrollmentSections as $section): ?><option value="<?=e($section['id'])?>" <?=((string)($edit['section_id']??'')===(string)$section['id'])?'selected':''?>><?=e($enrollmentSectionLabels[$section['id']])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='teacher_assignments'&&$field==='teacher_id'): ?>
<select class="form-select" id="field_teacher_id" name="teacher_id" required><option value="">Select teacher</option>
<?php foreach($teacherOptions as $teacher): ?><option value="<?=e($teacher['id'])?>" <?=((string)($edit['teacher_id']??'')===(string)$teacher['id'])?'selected':''?>><?=e($teacherLabels[$teacher['id']])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='teacher_assignments'&&$field==='subject_id'): ?>
<select class="form-select" id="field_subject_id" name="subject_id" required><option value="">Select subject</option>
<?php foreach($subjectOptions as $subject): ?><option value="<?=e($subject['id'])?>" <?=((string)($edit['subject_id']??'')===(string)$subject['id'])?'selected':''?>><?=e($subjectLabels[$subject['id']])?></option><?php endforeach; ?>
</select>
<?php elseif($m==='teacher_assignments'&&$field==='section_id'): ?>
<select class="form-select" id="field_section_id" name="section_id" required><option value="">Select section</option>
<?php foreach($assignmentSectionOptions as $section): ?><option value="<?=e($section['id'])?>" <?=((string)($edit['section_id']??'')===(string)$section['id'])?'selected':''?>><?=e($assignmentSectionLabels[$section['id']])?></option><?php endforeach; ?>
</select>
<?php else: ?>
<input class="form-control" id="field_<?=e($field)?>" name="<?=e($field)?>" value="<?=e($edit[$field]??'')?>" required>
<?php endif; ?>
</div>
<?php endforeach; ?>
<div class="col-12 student-form-actions"><button class="btn btn-primary"><?=$m==='students'?($edit?'Update student':'Save student'):'Save Record'?></button></div></form></div>
<?php if($m==='students'):?><script>
const courseSelect=document.getElementById('field_course_id');
const sectionSelect=document.getElementById('field_section_id');
function filterSections(){
	const courseId=courseSelect.value;
	for(const option of sectionSelect.options){
		if(!option.dataset.course)continue;
		option.hidden=option.dataset.course!==courseId;
		option.disabled=option.hidden;
	}
	if(sectionSelect.selectedOptions[0]?.disabled)sectionSelect.value='';
}
courseSelect.addEventListener('change',filterSections);
filterSections();
</script><?php endif;?>
<?php if($m==='students'): ?>
<section class="panel student-directory-panel" aria-labelledby="student-directory-title">
	<div class="student-directory-heading">
		<div><h2 id="student-directory-title">Student directory</h2><p><?=number_format($rows->num_rows)?> records shown</p></div>
		<form class="student-search-form" method="get"><input type="hidden" name="m" value="students"><label class="visually-hidden" for="student-search">Search students</label><div class="student-search-input"><i class="bi bi-search" aria-hidden="true"></i><input id="student-search" class="form-control" name="q" value="<?=e($q)?>" placeholder="Search student number, name, or email"></div><button class="btn btn-primary" type="submit">Search</button><a class="btn btn-outline-secondary" href="module.php?m=students">Clear</a></form>
	</div>
	<div class="table-responsive student-directory-table"><table class="table table-hover"><thead><tr><th>Student ID</th><th>Student</th><th>Email</th><th>Phone</th><th>Course</th><th>Section</th><th>Year</th><th>Status</th><th>Actions</th></tr></thead><tbody>
	<?php if(!$rows->num_rows): ?><tr><td class="student-empty-state" colspan="9">No students found. Try a different search or add a student.</td></tr><?php else: while($r=$rows->fetch_assoc()): $statusClass=match($r['status']){'active'=>'student-status-active','inactive'=>'student-status-inactive',default=>'student-status-graduated'};$initials=strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1)); ?>
		<tr><td class="student-code-cell"><?=e($r['student_code'])?></td><td><div class="student-directory-name"><span class="student-directory-avatar"><?=e($initials)?></span><strong><?=e($r['first_name'].' '.$r['last_name'])?></strong></div></td><td><?=e($r['email']?:'—')?></td><td><?=e($r['phone']?:'—')?></td><td><?=e($courseLabels[$r['course_id']]??'Not assigned')?></td><td><?=e($sectionLabels[$r['section_id']]??'Not assigned')?></td><td><?=e($r['year_level']?:'—')?></td><td><span class="student-directory-status <?=e($statusClass)?>"><?=e(ucfirst($r['status']))?></span></td><td class="student-row-actions"><a class="btn btn-sm btn-outline-primary" href="?m=students&amp;edit=<?=$r['id']?>"><i class="bi bi-pencil" aria-hidden="true"></i><span class="visually-hidden">Edit</span></a><a data-confirm="Delete this record?" class="btn btn-sm btn-outline-danger" href="?m=students&amp;delete=<?=$r['id']?>"><i class="bi bi-trash3" aria-hidden="true"></i><span class="visually-hidden">Delete</span></a></td></tr>
	<?php endwhile; endif; ?>
	</tbody></table></div>
</section>
</div>
<?php else: ?>
<div class="panel"><form class="d-flex gap-2 mb-3"><input type="hidden" name="m" value="<?=$m?>"><input class="form-control" name="q" value="<?=e($q)?>" placeholder="Search records..."><button class="btn btn-primary">Search</button></form><div class="table-responsive"><table class="table table-hover"><thead><tr><th>#</th><?php foreach($d['fields'] as $label):?><th><?=e($label)?></th><?php endforeach;?><th>Actions</th></tr></thead><tbody><?php while($r=$rows->fetch_assoc()):?><tr><td><?=$r['id']?></td><?php foreach($d['fields'] as $f=>$label):$value=$r[$f]??'';if(($m==='students'||$m==='sections')&&$f==='course_id')$value=$courseLabels[$value]??'Not assigned';if($m==='students'&&$f==='section_id')$value=$sectionLabels[$value]??'Not assigned';if($m==='teacher_assignments'&&$f==='teacher_id')$value=$teacherLabels[$value]??'Unknown teacher';if($m==='teacher_assignments'&&$f==='subject_id')$value=$subjectLabels[$value]??'Unknown subject';if($m==='teacher_assignments'&&$f==='section_id')$value=$assignmentSectionLabels[$value]??'Unknown section';if($m==='enrollments'&&$f==='student_id')$value=$enrollmentStudentLabels[$value]??'Unknown student';if($m==='enrollments'&&$f==='section_id')$value=$enrollmentSectionLabels[$value]??'Unknown section';?><td><?=e($value)?></td><?php endforeach;?><td><a class="btn btn-sm btn-outline-primary" href="?m=<?=$m?>&edit=<?=$r['id']?>">Edit</a> <a data-confirm="Delete this record?" class="btn btn-sm btn-outline-danger" href="?m=<?=$m?>&delete=<?=$r['id']?>">Delete</a></td></tr><?php endwhile;?></tbody></table></div></div>
<?php endif; ?>
<?php include 'includes/footer.php';?>
