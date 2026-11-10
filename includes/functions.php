<?php
require_once __DIR__ . '/db.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): never {
    if (!preg_match('~^(https?://|/)~i', $to)) $to = rtrim(BASE_URL, '/') . '/' . ltrim($to, '/');
    header('Location: ' . $to); exit;
}
$legacyRoutes=[
    '/attendance.php'=>'scan.php',
    '/module.php'=>'inventory.php',
    '/my_id.php'=>'index.php',
    '/registrar/dashboard.php'=>'index.php',
    '/student/attendance.php'=>'index.php',
    '/student/dashboard.php'=>'index.php',
    '/student/profile.php'=>'index.php',
    '/staff/legacy_dashboard.php'=>'staff/dashboard.php',
    '/staff/my_classes.php'=>'staff/dashboard.php',
    '/staff/students.php'=>'inventory.php'
];
$scriptPath=str_replace('\\','/',$_SERVER['SCRIPT_NAME']??'');
foreach($legacyRoutes as $legacyPath=>$destination){
    if(str_ends_with($scriptPath,$legacyPath))redirect($destination);
}
function role_home(?string $role=null): string {
    $role = $role ?? (current_user()['role'] ?? '');
    return match($role){
        'admin'=>'index.php',
        'staff'=>'staff/dashboard.php',
        'maintenance'=>'maintenance/dashboard.php',
        default=>$role!==''?'index.php':'login.php'
    };
}
function ensure_inventory_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS inventory_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        asset_tag VARCHAR(40) NOT NULL UNIQUE,
        item_name VARCHAR(150) NOT NULL,
        category VARCHAR(100) NOT NULL,
        description TEXT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        unit VARCHAR(30) NOT NULL DEFAULT 'piece',
        location VARCHAR(150) NOT NULL,
        custodian VARCHAR(150) NULL,
        condition_status ENUM('New','Good','Needs Repair','Damaged') NOT NULL DEFAULT 'Good',
        status ENUM('Available','In Use','Under Repair','Disposed') NOT NULL DEFAULT 'Available',
        acquisition_date DATE NULL,
        acquisition_cost DECIMAL(12,2) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_inventory_category(category),
        INDEX idx_inventory_location(location),
        INDEX idx_inventory_status(status)
    ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_history (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        inventory_item_id INT NOT NULL,
        user_id INT NOT NULL,
        action ENUM('started','completed') NOT NULL,
        work_description TEXT NULL,
        parts_replaced TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE RESTRICT,
        INDEX idx_maintenance_history_item(inventory_item_id,created_at),
        INDEX idx_maintenance_history_user(user_id)
    ) ENGINE=InnoDB");
    $historyColumns=$conn->query('SHOW COLUMNS FROM maintenance_history');
    $hasWorkDescription=false;
    $hasPartsReplaced=false;
    while($column=$historyColumns->fetch_assoc()){
        if($column['Field']==='work_description')$hasWorkDescription=true;
        if($column['Field']==='parts_replaced')$hasPartsReplaced=true;
    }
    if(!$hasWorkDescription)$conn->query('ALTER TABLE maintenance_history ADD COLUMN work_description TEXT NULL AFTER action');
    if(!$hasPartsReplaced)$conn->query('ALTER TABLE maintenance_history ADD COLUMN parts_replaced TEXT NULL AFTER work_description');
}
function ensure_equipment_requests_table(mysqli $conn): void {
    ensure_inventory_table($conn);
    $conn->query("CREATE TABLE IF NOT EXISTS equipment_requests (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        requester_id INT NOT NULL,
        inventory_item_id INT NOT NULL,
        quantity INT UNSIGNED NOT NULL DEFAULT 1,
        purpose VARCHAR(255) NOT NULL,
        expected_return_date DATE NOT NULL,
        status ENUM('pending','approved','rejected','returned','cancelled') NOT NULL DEFAULT 'pending',
        reviewed_by INT NULL,
        reviewed_at DATETIME NULL,
        review_note VARCHAR(500) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY(requester_id) REFERENCES users(id) ON DELETE RESTRICT,
        FOREIGN KEY(inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT,
        FOREIGN KEY(reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_equipment_request_status(status,created_at),
        INDEX idx_equipment_request_item(inventory_item_id,status),
        INDEX idx_equipment_request_user(requester_id,created_at)
    ) ENGINE=InnoDB");
}
function ensure_system_settings_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS system_settings (
        setting_key VARCHAR(64) PRIMARY KEY,
        setting_value TEXT NOT NULL,
        updated_by INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY(updated_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB");
}
function ensure_system_backups_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS system_backups (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        file_name VARCHAR(255) NOT NULL UNIQUE,
        file_size BIGINT UNSIGNED NOT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        restored_by INT NULL,
        restored_at DATETIME NULL,
        FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL,
        FOREIGN KEY(restored_by) REFERENCES users(id) ON DELETE SET NULL,
        INDEX idx_system_backups_created(created_at)
    ) ENGINE=InnoDB");
}
function get_system_setting(mysqli $conn, string $key, string $default=''): string {
    static $cache=[];
    if(array_key_exists($key,$cache))return $cache[$key];
    ensure_system_settings_table($conn);
    $query=$conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');
    $query->bind_param('s',$key);
    $query->execute();
    $value=$query->get_result()->fetch_column();
    $cache[$key]=$value===false?$default:(string)$value;
    return $cache[$key];
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
        !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(419); exit('Invalid or expired form token. Please go back and retry.');
    }
}
function stmt_bind(mysqli_stmt $stmt, string $types, array $params): void {
    $refs=[];
    foreach ($params as $k => &$value) $refs[$k]=&$value;
    array_unshift($refs,$types);
    call_user_func_array([$stmt,'bind_param'],$refs);
}
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_login(): void {
    static $validated=false;
    $user=current_user();
    if(!$user)redirect('login.php');
    if($validated)return;
    global $conn;
    $query=$conn->prepare("SELECT full_name,email,role,status FROM users WHERE id=? LIMIT 1");
    $query->bind_param('i',$user['id']);
    $query->execute();
    $account=$query->get_result()->fetch_assoc();
    if(!$account||($account['status']!==null&&$account['status']!=='active')||$account['role']!==$user['role']){
        $_SESSION=[];
        session_destroy();
        redirect('login.php');
    }
    $_SESSION['user']['full_name']=$account['full_name'];
    $_SESSION['user']['email']=$account['email'];
    $validated=true;
}
function require_role(array $roles): void {
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403); exit('403 - You are not authorized to access this page.');
    }
}
function log_action(string $action, string $details=''): void {
    global $conn;
    if (!current_user()) return;
    $uid=(int)current_user()['id'];
    $stmt=$conn->prepare("INSERT INTO activity_logs(user_id,action,details,ip_address) SELECT (SELECT id FROM users WHERE id=?),?,?,?");
    $ip=$_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->bind_param('isss',$uid,$action,$details,$ip); $stmt->execute();
}
function flash(string $msg, string $type='success'): void { $_SESSION['flash']=['msg'=>$msg,'type'=>$type]; }
function show_flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f=$_SESSION['flash']; echo '<div class="alert '.e($f['type']).'">'.e($f['msg']).'</div>';
        unset($_SESSION['flash']);
    }
}
function ensure_password_resets_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash VARCHAR(255) NOT NULL UNIQUE,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");
    $columns=$conn->query('SHOW COLUMNS FROM password_resets');
    $hasAttempts=false;
    $hashType='';
    while($column=$columns->fetch_assoc()){
        if($column['Field']==='attempts')$hasAttempts=true;
        if($column['Field']==='token_hash')$hashType=strtolower($column['Type']);
    }
    if($hashType!=='varchar(255)')$conn->query('ALTER TABLE password_resets MODIFY token_hash VARCHAR(255) NOT NULL');
    if(!$hasAttempts)$conn->query('ALTER TABLE password_resets ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER used_at');
}
function send_smtp_mail(string $to, string $subject, string $html): bool {
    $cfg=require __DIR__.'/../config/mail.php';
    if (empty($cfg['enabled']) || empty($cfg['password']) || empty($cfg['from_email'])) return false;
    $host=$cfg['host']; $port=(int)$cfg['port'];
    $fp=@fsockopen(($cfg['encryption']==='ssl'?'ssl://':'').$host,$port,$errno,$errstr,15);
    if (!$fp) return false;
    stream_set_timeout($fp,15);
    $read=function() use($fp) { $data=''; while (($line=fgets($fp,515))!==false) { $data.=$line; if (isset($line[3]) && $line[3]===' ') break; } return $data; };
    $cmd=function($s) use($fp,$read) { fwrite($fp,$s."\r\n"); return $read(); };
    $read();
    $cmd('EHLO localhost');
    if ($cfg['encryption']==='tls') { $cmd('STARTTLS'); if (!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; } $cmd('EHLO localhost'); }
    $cmd('AUTH LOGIN'); $cmd(base64_encode($cfg['username'])); $auth=$cmd(base64_encode($cfg['password']));
    if (strpos($auth,'235')===false) { fclose($fp); return false; }
    $cmd('MAIL FROM:<'.$cfg['from_email'].'>'); $cmd('RCPT TO:<'.$to.'>'); $cmd('DATA');
    $headers="From: ".$cfg['from_name']." <".$cfg['from_email'].">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n";
    $body=$headers."To: ".$to."\r\nSubject: ".mb_encode_mimeheader($subject)."\r\n\r\n".$html."\r\n.";
    $result=$cmd($body); $cmd('QUIT'); fclose($fp);
    return strpos($result,'250')!==false;
}
