<?php
require_once __DIR__ . '/db.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function redirect(string $to): never {
    if (!preg_match('~^(https?://|/)~i', $to)) $to = rtrim(BASE_URL, '/') . '/' . ltrim($to, '/');
    header('Location: ' . $to); exit;
}
function role_home(?string $role=null): string {
    $role = $role ?? (current_user()['role'] ?? '');
    return match ($role) {
        'registrar' => 'registrar/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/dashboard.php',
        default => 'login.php'
    };
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
function require_login(): void { if (!current_user()) redirect('login.php'); }
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
function make_student_code(mysqli $conn): string {
    $year=date('Y'); $prefix='CCST-'.$year.'-';
    $stmt=$conn->prepare("SELECT COUNT(*)+1 n FROM students WHERE student_code LIKE CONCAT(?, '%')");
    $stmt->bind_param('s',$prefix); $stmt->execute(); $n=(int)$stmt->get_result()->fetch_assoc()['n'];
    return $prefix.str_pad((string)$n,4,'0',STR_PAD_LEFT);
}
