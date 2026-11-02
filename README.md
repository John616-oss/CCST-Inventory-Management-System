# CCST Student Management System

## Stack
PHP 8.1+, MySQL 8 / MariaDB, MySQLi, HTML/CSS/JavaScript, Bootstrap 5.3.
Designed for XAMPP local demonstration.

## Installation
1. Install/start Apache and MySQL in XAMPP.
2. Copy this folder to `C:\xampp\htdocs\ccst_system`.
3. Open phpMyAdmin and import `database.sql`.
4. Edit `config/config.php` if your MySQL host/user/password/database differ.
5. To send verification emails through Gmail SMTP, enable 2-Step Verification on the Gmail account and create a Google App Password. Add these Apache environment variables in XAMPP's Apache configuration, then restart Apache: `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=587`, `SMTP_USERNAME=your Gmail address`, `SMTP_PASSWORD=your 16-character Google App Password`, and `SMTP_FROM_EMAIL=your Gmail address`. The app uses TLS on port 587. Never use your normal Gmail password. For another SMTP provider, replace the host, port, username, password, sender address, and `SMTP_ENCRYPTION` (`tls` or `ssl`) as required. If SMTP is not configured, registration shows a local verification link for the demo.
6. Set `BASE_URL` in config/config.php to your exact local URL.
7. Visit `http://localhost/ccst_system/install.php` and create the first Registrar.
8. **Delete `install.php` after creating the Registrar account.**
9. Sign in at `login.php`.

## Important demo notes
- Initial registrar is created through install.php; there is no default hard-coded password.
- Public registration creates unverified student accounts. SMTP must be configured to receive verification and password-reset emails; locally, registration and password-reset requests show a demo link when delivery is unavailable.
- Registrar creates student profiles and can associate user accounts later by setting `students.user_id` in the database. This starter includes the student ID view for linked accounts.
- QR value is the unique student_code. Camera scanning uses html5-qrcode CDN; camera permission requires localhost or HTTPS. A USB QR/barcode scanner that types into the input works as keyboard input.
- Attendance has a unique constraint for student + subject + date to stop duplicates. For multiple class sessions per day, add a `class_session_id` table and change the unique key.
- This is an educational starter implementation. Test every role and workflow, configure HTTPS before deployment, validate uploaded photos if enabling uploads, and do not expose database credentials.

## Data structure demonstration
See `includes/data_structures.php`: FIFO attendance queue (`SplQueue`), stack, priority queue (`SplPriorityQueue`), course tree, and associative-array student hash lookup. These classes are reusable examples; the live attendance insert currently uses database constraints for duplicate prevention.

## Before final defense
Demonstrate account creation + received email verification, all three roles, CRUD for students/teachers/courses (and other modules), one attendance scan, filtering/printing reports, activity logs, unauthorized access rejection, and explain the database relationships and data structures.


## Role-based pages (updated)
After login, users are redirected by their database role:
- Registrar: `registrar/dashboard.php` (manage records, reports, logs)
- Teacher: `teacher/dashboard.php` and `teacher/my_classes.php`
- Student: `student/dashboard.php`, `student/profile.php`, and `student/attendance.php`
The root `index.php` is now a role-based dispatcher. Each role page uses server-side `require_role()` checks.

**Important account linking:** a teacher account must be linked to a row in `teachers.user_id`, and a student account to a row in `students.user_id`. The starter CRUD does not yet provide a complete account-linking UI; for a demo, link records in phpMyAdmin after creating verified accounts. Never give a user a role by trusting a form field; role is read from the database at login.
