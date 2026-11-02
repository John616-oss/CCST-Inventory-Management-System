<?php
declare(strict_types=1);
define('APP_NAME', 'CCST Student Management System');
define('BASE_URL', 'http://localhost/ccst_system');
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'ccst_student_management');
define('UPLOAD_DIR', __DIR__ . '/../uploads/');
define('UPLOAD_URL', 'uploads/');
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>false]);
    session_start();
}
