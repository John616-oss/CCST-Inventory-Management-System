<?php
require_once __DIR__.'/includes/functions.php';
ensure_password_resets_table($conn);
$msg='';$messageType='info';$demoCode='';$resetEmail='';$showCodeForm=false;$resetComplete=false;

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $step=$_POST['step']??'request';
    if($step==='reset'){
        $resetEmail=trim($_POST['email']??'');
        $code=trim($_POST['code']??'');
        $password=$_POST['password']??'';
        $confirm=$_POST['confirm_password']??'';
        $showCodeForm=true;

        if(!filter_var($resetEmail,FILTER_VALIDATE_EMAIL)){
            $msg='The code or email is invalid or expired. Request a new code.';$messageType='danger';
        }elseif(!preg_match('/^[0-9]{6}$/',$code)){
            $msg='Enter the six-digit code from your email.';$messageType='danger';
        }elseif(strlen($password)<10){
            $msg='Password must be at least 10 characters.';$messageType='danger';
        }elseif($password!==$confirm){
            $msg='The passwords do not match.';$messageType='danger';
        }else{
            $conn->begin_transaction();
            try{
                $find=$conn->prepare("SELECT pr.id,pr.user_id,pr.token_hash FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE u.email=? AND u.status='active' AND u.is_verified=1 AND pr.used_at IS NULL AND pr.expires_at>NOW() AND pr.attempts<5 ORDER BY pr.id DESC LIMIT 1 FOR UPDATE");
                $find->bind_param('s',$resetEmail);$find->execute();$reset=$find->get_result()->fetch_assoc();
                if($reset&&password_verify($code,$reset['token_hash'])){
                    $uid=(int)$reset['user_id'];$hash=password_hash($password,PASSWORD_DEFAULT);
                    $update=$conn->prepare('UPDATE users SET password=? WHERE id=?');
                    $update->bind_param('si',$hash,$uid);$update->execute();
                    $consume=$conn->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
                    $consume->bind_param('i',$uid);$consume->execute();
                    $conn->commit();
                    $showCodeForm=false;$resetComplete=true;$messageType='success';$msg='Your password has been updated. You can now sign in.';
                }else{
                    if($reset){
                        $attempt=$conn->prepare('UPDATE password_resets SET attempts=attempts+1 WHERE id=? AND attempts<5');
                        $attempt->bind_param('i',$reset['id']);$attempt->execute();
                    }
                    $conn->commit();
                    $msg='The code or email is invalid or expired. Check the code or request a new one.';$messageType='danger';
                }
            }catch(Throwable $e){
                $conn->rollback();
                $msg='Unable to reset the password. Please try again.';$messageType='danger';
            }
        }
    }else{
        $email=trim($_POST['email']??'');
        if(!filter_var($email,FILTER_VALIDATE_EMAIL)){
            $msg='Enter a valid email address.';$messageType='danger';
        }else{
            $resetEmail=$email;$showCodeForm=true;
            $msg='If an active, verified account matches that email, a verification code has been sent. The code expires in 10 minutes.';
            $s=$conn->prepare("SELECT id FROM users WHERE email=? AND status='active' AND is_verified=1 LIMIT 1");
            $s->bind_param('s',$email);$s->execute();$user=$s->get_result()->fetch_assoc();
            if($user){
                $uid=(int)$user['id'];
                $recent=$conn->prepare('SELECT id FROM password_resets WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE) ORDER BY id DESC LIMIT 1');
                $recent->bind_param('i',$uid);$recent->execute();
                if(!$recent->get_result()->fetch_assoc()){
                    $invalidate=$conn->prepare('UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL');
                    $invalidate->bind_param('i',$uid);$invalidate->execute();
                    $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);
                    $codeHash=password_hash($code,PASSWORD_DEFAULT);
                    $expires=date('Y-m-d H:i:s',time()+600);
                    $insert=$conn->prepare('INSERT INTO password_resets(user_id,token_hash,expires_at) VALUES(?,?,?)');
                    $insert->bind_param('iss',$uid,$codeHash,$expires);$insert->execute();
                    $emailBody='<p>A password reset was requested for your CCST account.</p><p>Your six-digit verification code is:</p><p style="font-size:28px;font-weight:bold;letter-spacing:8px">'.e($code).'</p><p>This code expires in 10 minutes. If you did not request this, ignore this email.</p>';
                    $sent=send_smtp_mail($email,'Your CCST password reset code',$emailBody);
                    $localHost=in_array(parse_url(BASE_URL,PHP_URL_HOST),['localhost','127.0.0.1','::1'],true);
                    if(!$sent&&$localHost)$demoCode=$code;
                }
            }
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><link href="assets/auth.css" rel="stylesheet"><title>Forgot Password | CCST</title></head><body class="login-page"><main class="login-shell"><section class="login-brand" aria-label="Clark College of Science and Technology"><div class="brand-topline">CLARK COLLEGE OF SCIENCE AND TECHNOLOGY</div><div class="brand-seal"><img src="assets/725698283_122272196210063653_4714758265439461367_n.jpg" alt="Clark College of Science and Technology seal"></div><div class="brand-copy"><p class="brand-kicker">WELCOME TO</p><h1>CCST</h1><p class="brand-description">Student Management System</p></div><div class="brand-footer"><span>GENOSIS EXOUSIA</span><span>EST. 2005</span></div></section><section class="login-content"><div class="login-form-wrap"><div class="login-heading"><p class="login-kicker">ACCOUNT SUPPORT</p><h2><?= $resetComplete?'Password updated':($showCodeForm?'Enter your code':'Reset your password') ?></h2><p class="muted"><?= $resetComplete?'Your password is ready to use.':($showCodeForm?'Enter the six-digit code from your email and choose a new password.':'Enter your account email to receive a six-digit verification code.') ?></p></div><?php if($msg): ?><div class="alert alert-<?=e($messageType)?> auth-message" role="status"><?=e($msg)?></div><?php endif; ?><?php if($demoCode): ?><div class="alert alert-warning auth-message">Local development code: <strong><?=e($demoCode)?></strong></div><?php endif; ?><?php if($resetComplete): ?><a class="btn btn-primary login-submit" href="login.php">Back to sign in <span aria-hidden="true">&#8594;</span></a><?php elseif($showCodeForm): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="step" value="reset"><label class="form-label" for="email">Email address</label><input class="form-control login-input" id="email" name="email" type="email" value="<?=e($resetEmail)?>" autocomplete="email" readonly required><label class="form-label" for="code">Six-digit code</label><input class="form-control login-input" id="code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required><label class="form-label" for="password">New password</label><input class="form-control login-input" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control login-input" id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required><button class="btn btn-primary login-submit" type="submit">Update password <span aria-hidden="true">&#8594;</span></button></form><div class="login-links"><a href="forgot_password.php">Request a new code</a></div><?php else: ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="step" value="request"><label class="form-label" for="email">Email address</label><input class="form-control login-input" id="email" name="email" type="email" autocomplete="email" required><button class="btn btn-primary login-submit" type="submit">Send verification code <span aria-hidden="true">&#8594;</span></button></form><?php endif; ?><div class="login-links"><a href="login.php">Back to sign in</a></div></div><div class="login-content-footer">Clark College of Science and Technology</div></section></main></body></html>