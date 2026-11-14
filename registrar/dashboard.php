<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['admin']);

function stat_count(string $sql): int
{
    global $conn;
    return (int)$conn->query($sql)->fetch_row()[0];
}

$students = stat_count("SELECT COUNT(*) FROM students WHERE status='active'");
$newThisMonth = stat_count("SELECT COUNT(*) FROM students WHERE status='active' AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$teachers = stat_count('SELECT COUNT(*) FROM teachers');
$courses = stat_count('SELECT COUNT(*) FROM courses');
$sections = stat_count('SELECT COUNT(*) FROM sections');
$checkedInToday = stat_count("SELECT COUNT(DISTINCT a.student_id) FROM attendance a INNER JOIN students s ON s.id=a.student_id WHERE a.attendance_date=CURDATE() AND s.status='active' AND a.status IN ('present','late')");
$notCheckedInToday = max(0, $students - $checkedInToday);
$attendancePercent = $students > 0 ? min(100, (int)round($checkedInToday / $students * 100)) : 0;
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
$recentStudents = $conn->query("SELECT s.student_code,s.first_name,s.last_name,s.status,c.code AS course_code FROM students s LEFT JOIN courses c ON c.id=s.course_id ORDER BY s.id DESC LIMIT 5");

include __DIR__ . '/../includes/header.php';
?>
<div class="registrar-dashboard">
    <header class="dashboard-titlebar">
        <div>
            <p class="dashboard-eyebrow">ADMIN OVERVIEW</p>
            <h1><?= e($greeting) ?>, Admin</h1>
            <p class="dashboard-subtitle">Here's what's happening at your school today.</p>
        </div>
        <div class="dashboard-date"><i class="bi bi-clock-history" aria-hidden="true"></i><span><?= e(date('F j, Y')) ?></span></div>
    </header>

    <section class="dashboard-stats" aria-label="School statistics">
        <article class="stat dashboard-stat">
            <div class="dashboard-stat-top"><span>Total Students</span><span class="dashboard-stat-icon icon-teal"><i class="bi bi-people" aria-hidden="true"></i></span></div>
            <strong><?= number_format($students) ?></strong>
            <small><span class="stat-accent">+<?= number_format($newThisMonth) ?></span> this month</small>
        </article>
        <article class="stat dashboard-stat">
            <div class="dashboard-stat-top"><span>Teachers</span><span class="dashboard-stat-icon icon-blue"><i class="bi bi-person-badge" aria-hidden="true"></i></span></div>
            <strong><?= number_format($teachers) ?></strong>
            <small>Faculty records</small>
        </article>
        <article class="stat dashboard-stat">
            <div class="dashboard-stat-top"><span>Courses</span><span class="dashboard-stat-icon icon-orange"><i class="bi bi-journal-bookmark" aria-hidden="true"></i></span></div>
            <strong><?= number_format($courses) ?></strong>
            <small>Across <?= number_format($sections) ?> sections</small>
        </article>
        <article class="stat dashboard-stat">
            <div class="dashboard-stat-top"><span>Today's Attendance</span><span class="dashboard-stat-icon icon-sky"><i class="bi bi-clock" aria-hidden="true"></i></span></div>
            <strong><?= number_format($checkedInToday) ?></strong>
            <small><span class="stat-accent">Checked in</span> today</small>
        </article>
    </section>

    <section class="panel dashboard-quick-actions" aria-labelledby="quick-actions-title">
        <div class="dashboard-section-heading">
            <div>
                <h2 id="quick-actions-title">Quick actions</h2>
                <p>Common tasks, one click away.</p>
            </div>
        </div>
        <div class="quick-action-list">
            <a class="btn btn-primary" href="<?= e(BASE_URL) ?>/module.php?m=students"><i class="bi bi-plus-lg" aria-hidden="true"></i>Add student</a>
            <a class="btn quick-action-teal" href="<?= e(BASE_URL) ?>/attendance.php"><i class="bi bi-qr-code-scan" aria-hidden="true"></i>QR attendance</a>
            <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/module.php?m=teachers"><i class="bi bi-person-plus" aria-hidden="true"></i>Add teacher</a>
            <a class="btn btn-outline-secondary" href="<?= e(BASE_URL) ?>/reports.php"><i class="bi bi-bar-chart-line" aria-hidden="true"></i>View reports</a>
        </div>
    </section>

    <div class="dashboard-lower-grid">
        <section class="panel recent-students-panel" aria-labelledby="recent-students-title">
            <div class="dashboard-section-heading">
                <div>
                    <h2 id="recent-students-title">Recently added students</h2>
                    <p>Latest student registrations</p>
                </div>
                <a class="dashboard-view-all" href="<?= e(BASE_URL) ?>/module.php?m=students">View all <span aria-hidden="true">&#8594;</span></a>
            </div>
            <div class="table-responsive dashboard-recent-table">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Student ID</th>
                            <th>Course</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($recentStudents->num_rows): while ($student = $recentStudents->fetch_assoc()):
                                $initials = strtoupper(substr($student['first_name'], 0, 1) . substr($student['last_name'], 0, 1));
                                $statusClass = match ($student['status']) {
                                    'active' => 'status-active',
                                    'inactive' => 'status-inactive',
                                    default => 'status-graduated'
                                };
                        ?>
                                <tr>
                                    <td>
                                        <div class="dashboard-student"><span class="student-avatar"><?= e($initials) ?></span><span><strong><?= e($student['first_name'] . ' ' . $student['last_name']) ?></strong><small>Student record</small></span></div>
                                    </td>
                                    <td><?= e($student['student_code']) ?></td>
                                    <td><?= e($student['course_code'] ?? 'Unassigned') ?></td>
                                    <td><span class="student-status <?= e($statusClass) ?>"><i aria-hidden="true"></i><?= e(ucfirst($student['status'])) ?></span></td>
                                </tr>
                            <?php endwhile;
                        else: ?>
                            <tr>
                                <td class="dashboard-empty-state" colspan="4">No student records yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel attendance-panel" aria-labelledby="attendance-title">
            <div class="dashboard-section-heading">
                <div>
                    <h2 id="attendance-title">Attendance today</h2>
                    <p>Student check-in overview</p>
                </div>
            </div>
            <div class="attendance-ring" role="img" aria-label="<?= $checkedInToday ?> of <?= $students ?> active students checked in today" style="--attendance-progress:<?= $attendancePercent ?>%">
                <div class="attendance-ring-center"><strong><?= number_format($checkedInToday) ?></strong><span>checked in</span></div>
            </div>
            <p class="attendance-rate"><?= $attendancePercent ?>% of active students</p>
            <div class="attendance-legend">
                <div><span class="legend-label"><i class="legend-dot dot-present"></i>Checked in</span><strong><?= number_format($checkedInToday) ?></strong></div>
                <div><span class="legend-label"><i class="legend-dot dot-pending"></i>Not yet checked in</span><strong><?= number_format($notCheckedInToday) ?></strong></div>
            </div>
        </section>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>