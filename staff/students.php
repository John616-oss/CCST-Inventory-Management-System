<?php
require_once __DIR__.'/../includes/functions.php';
require_role(['staff']);

$uid=(int)current_user()['id'];
$search=trim($_GET['q']??'');
$sql="SELECT st.student_code,st.first_name,st.middle_name,st.last_name,c.code course_code,se.year_level,se.section_name,COALESCE(att.attendance_count,0) attendance_count,att.last_attendance
FROM students st
JOIN sections se ON se.id=st.section_id
LEFT JOIN courses c ON c.id=se.course_id
LEFT JOIN (SELECT student_id,COUNT(*) attendance_count,MAX(attendance_date) last_attendance FROM attendance GROUP BY student_id) att ON att.student_id=st.id
WHERE st.status='active' AND EXISTS (
 SELECT 1 FROM teachers t JOIN teacher_subjects ts ON ts.teacher_id=t.id
 WHERE t.user_id=? AND ts.section_id=st.section_id
)";
$params=[$uid];$types='i';
if($search!==''){
    $sql.=" AND (st.student_code LIKE ? OR CONCAT_WS(' ',st.first_name,st.middle_name,st.last_name) LIKE ? OR st.email LIKE ?)";
    $like='%'.$search.'%';array_push($params,$like,$like,$like);$types.='sss';
}
$sql.=' ORDER BY c.code,se.year_level,se.section_name,st.last_name,st.first_name';
$stmt=$conn->prepare($sql);stmt_bind($stmt,$types,$params);$stmt->execute();$students=$stmt->get_result();

include __DIR__.'/../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h3>My Students</h3>
</div>
<div class="panel">
    <form method="get" class="d-flex gap-2 mb-3">
        <input class="form-control" name="q" value="<?=e($search)?>" placeholder="Search student number, name, or email">
        <button class="btn btn-primary">Search</button>
    </form>
    <div class="table-responsive">
        <table class="table table-hover">
            <thead><tr><th>Student No.</th><th>Name</th><th>Course</th><th>Year</th><th>Section</th><th>Attendance</th><th>Last Attendance</th></tr></thead>
            <tbody>
            <?php if(!$students->num_rows): ?>
                <tr><td colspan="7" class="text-center muted">No students found in your assigned sections.</td></tr>
            <?php else: while($student=$students->fetch_assoc()): $middle=trim($student['middle_name']??'');$name=trim($student['first_name'].' '.($middle!==''?$middle.' ':'').$student['last_name']); ?>
                <tr>
                    <td><?=e($student['student_code'])?></td>
                    <td><?=e($name)?></td>
                    <td><?=e($student['course_code']??'—')?></td>
                    <td><?=e($student['year_level']??'—')?></td>
                    <td><?=e($student['section_name'])?></td>
                    <td><?=e($student['attendance_count'])?></td>
                    <td><?=e($student['last_attendance']??'—')?></td>
                </tr>
            <?php endwhile; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__.'/../includes/footer.php'; ?>