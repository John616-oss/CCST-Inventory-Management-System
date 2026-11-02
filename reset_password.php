<?php
require_once __DIR__.'/includes/functions.php';
ensure_password_resets_table($conn);
$token=$_POST['token']??$_GET['token']??'';$msg='This reset link is invalid or expired. Request a new one.';$valid=false;$tokenHash='';

if(preg_match('/^[a-f0-9]{64}$/',$token)){
    $tokenHash=hash('sha256',$token);
    $s=$conn->prepare("SELECT pr.id FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.status='active' AND u.is_verified=1 LIMIT 1");
    $s->bind_param('s',$tokenHash);$s->execute();$valid=(bool)$s->get_result()->fetch_assoc();
    if($valid)$msg='';
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $password=$_POST['password']??'';$confirm=$_POST['confirm_password']??'';
    if(strlen($password)<10)$msg='Password must be at least 10 characters.';
    elseif($password!==$confirm)$msg='The passwords do not match.';
    elseif(!$valid)$msg='This reset link is invalid or expired. Request a new one.';
    else{
        $conn->begin_transaction();
        try{
            $s=$conn->prepare("SELECT pr.id,pr.user_id FROM password_resets pr JOIN users u ON u.id=pr.user_id WHERE pr.token_hash=? AND pr.used_at IS NULL AND pr.expires_at>NOW() AND u.status='active' AND u.is_verified=1 LIMIT 1 FOR UPDATE");
            $s->bind_param('s',$tokenHash);$s->execute();$reset=$s->get_result()->fetch_assoc();
            if(!$reset){
                $conn->rollback();$valid=false;$msg='This reset link is invalid or expired. Request a new one.';
            }else{
                $uid=(int)$reset['user_id'];$hash=password_hash($password,PASSWORD_DEFAULT);
                $update=$conn->prepare("UPDATE users SET password=? WHERE id=?");
                $update->bind_param('si',$hash,$uid);$update->execute();
                $consume=$conn->prepare("UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL");
                $consume->bind_param('i',$uid);$consume->execute();
                $conn->commit();$valid=false;$msg='Password updated. You can now sign in.';
            }
        }catch(Throwable $e){
            $conn->rollback();$msg='Unable to reset the password. Please try again.';
        }
    }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><title>Reset Password</title></head><body class="d-flex align-items-center justify-content-center min-vh-100"><main class="panel p-4" style="width:min(420px,94vw)"><h3>Reset password</h3><?php if($msg)echo '<div class="alert '.($valid?'error':'info').'">'.e($msg).'</div>';if($valid):?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="token" value="<?=e($token)?>"><label class="form-label" for="password">New password</label><input class="form-control mb-3" id="password" name="password" type="password" minlength="10" autocomplete="new-password" required><label class="form-label" for="confirm_password">Confirm new password</label><input class="form-control mb-3" id="confirm_password" name="confirm_password" type="password" minlength="10" autocomplete="new-password" required><button class="btn btn-primary w-100">Update password</button></form><?php else: ?><a class="btn btn-primary w-100" href="login.php">Go to login</a><?php endif; ?></main></body></html>