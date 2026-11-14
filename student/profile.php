<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
$uid = (int)current_user()['id'];
$q = $conn->prepare("SELECT s.*,c.code course_code,c.name course_name,se.section_name FROM students s LEFT JOIN courses c ON c.id=s.course_id LEFT JOIN sections se ON se.id=s.section_id WHERE s.user_id=? LIMIT 1");
$q->bind_param('i', $uid);
$q->execute();
$st = $q->get_result()->fetch_assoc();
include __DIR__ . '/../includes/header.php'; ?><h3 class="mb-3">My Profile</h3>
<div class="panel"><?php if (!$st): ?><div class="alert alert-warning">Student profile is not linked yet.</div><?php else: ?><dl class="row mb-0"><?php foreach (['student_code' => 'Student Number', 'first_name' => 'First Name', 'middle_name' => 'Middle Name', 'last_name' => 'Last Name', 'email' => 'Email', 'phone' => 'Phone', 'course_code' => 'Course', 'section_name' => 'Section', 'year_level' => 'Year Level', 'status' => 'Status'] as $k => $label): ?><dt class="col-sm-3"><?= e($label) ?></dt>
                <dd class="col-sm-9"><?= e($st[$k] ?? '—') ?></dd><?php endforeach; ?>
        </dl><?php endif; ?></div><?php include __DIR__ . '/../includes/footer.php'; ?>