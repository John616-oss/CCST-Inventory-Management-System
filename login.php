<?php
require_once __DIR__.'/includes/functions.php';
if(current_user())redirect(role_home());
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $email=trim($_POST['email']??'');
    $pass=$_POST['password']??'';
    $stmt=$conn->prepare("SELECT * FROM users WHERE email=? AND status='active' LIMIT 1");
    $stmt->bind_param('s',$email);
    $stmt->execute();
    $user=$stmt->get_result()->fetch_assoc();
    if($user&&password_verify($pass,$user['password'])){
        if(!$user['is_verified'])$err='Please verify your email before logging in.';
        else{
            session_regenerate_id(true);
            $_SESSION['user']=['id'=>$user['id'],'full_name'=>$user['full_name'],'email'=>$user['email'],'role'=>$user['role']];
            log_action('LOGIN','Successful login');
            redirect(role_home($user['role']));
        }
    }else $err='Invalid email or password.';
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><link href="assets/auth.css" rel="stylesheet"><title>CCST School Inventory</title></head><body class="login-page"><main class="login-shell"><section class="login-brand" aria-label="Clark College of Science and Technology"><div class="brand-topline">CLARK COLLEGE OF SCIENCE AND TECHNOLOGY</div><div class="brand-seal"><img src="assets/725698283_122272196210063653_4714758265439461367_n.jpg" alt="Clark College of Science and Technology seal"></div><div class="brand-copy"><p class="brand-kicker">CCST FACILITIES</p><h1>School Inventory</h1><p class="brand-description">Equipment and asset tracking</p></div><div class="brand-footer"><span>GENOSIS EXOUSIA</span><span>EST. 2005</span></div></section><section class="login-content"><div class="login-form-wrap"><div class="login-heading"><p class="login-kicker">ASSET MANAGEMENT</p><h2>Welcome back</h2><p class="muted">Sign in to manage school equipment.</p></div><?php if($err): ?><div class="alert error"><?=e($err)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="form-label" for="email">Email address</label><input class="form-control login-input" id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com" required><div class="password-label"><label class="form-label" for="password">Password</label><a href="forgot_password.php">Forgot password?</a></div><input class="form-control login-input" id="password" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" required><button class="btn btn-primary login-submit" type="submit">Sign in <span aria-hidden="true">&#8594;</span></button></form><div class="login-links"><p>Need an account? <a href="register.php">Register as inventory staff</a></p><a href="resend.php">Resend verification email</a></div></div><div class="login-content-footer">Clark College of Science and Technology</div></section></main></body></html>