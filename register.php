<?php
require_once __DIR__.'/includes/functions.php';
$msg='';
$accountRole='staff';

if($_SERVER['REQUEST_METHOD']==='POST'){
    csrf_check();
    $name=trim($_POST['name']??'');
    $email=trim($_POST['email']??'');
    $pass=$_POST['password']??'';
    if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10){
        $msg='Check your details. Password must be at least 10 characters.';
    }else{
        try{
            $hash=password_hash($pass,PASSWORD_DEFAULT);
            $stmt=$conn->prepare('INSERT INTO users(full_name,email,password,role,is_verified) VALUES(?,?,?, ?,0)');
            $stmt->bind_param('ssss',$name,$email,$hash,$accountRole);
            $stmt->execute();
            $userId=$conn->insert_id;
            $token=bin2hex(random_bytes(32));
            $tokenHash=hash('sha256',$token);
            $expires=date('Y-m-d H:i:s',time()+86400);
            $verify=$conn->prepare('INSERT INTO email_verifications(user_id,token_hash,expires_at) VALUES(?,?,?)');
            $verify->bind_param('iss',$userId,$tokenHash,$expires);
            $verify->execute();
            $link=rtrim(BASE_URL,'/').'/verify.php?token='.$token;
            $sent=send_smtp_mail($email,'Verify your CCST inventory account','<p>Welcome to the CCST School Inventory System.</p><p><a href="'.$link.'">Verify your email</a></p><p>This link expires in 24 hours.</p>');
            if(!$sent){
                $invalidate=$conn->prepare('UPDATE email_verifications SET used_at=NOW() WHERE token_hash=? AND used_at IS NULL');
                $invalidate->bind_param('s',$tokenHash);$invalidate->execute();
            }
            $msg=$sent?'Registration successful. Check your email for the verification link.':'Account created, but the verification email could not be sent. Check SMTP configuration, then use Resend verification email.';
        }catch(Throwable $e){$msg='Registration failed. Email may already be registered.';}
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css?v=navy-3" rel="stylesheet">
    <link href="assets/auth.css?v=navy-3" rel="stylesheet">
    <title>Inventory Account Registration | CCST</title>
</head>
<body class="login-page">
<main class="login-shell">
    <section class="login-brand" aria-label="Clark College of Science and Technology">
        <div class="brand-topline">CLARK COLLEGE OF SCIENCE AND TECHNOLOGY</div>
        <div class="brand-seal"><img src="assets/725698283_122272196210063653_4714758265439461367_n.jpg" alt="Clark College of Science and Technology seal"></div>
        <div class="brand-copy"><p class="brand-kicker">CCST FACILITIES</p><h1>School Inventory</h1><p class="brand-description">Equipment and asset tracking</p></div>
        <div class="brand-footer"><span>GENOSIS EXOUSIA</span><span>EST. 2005</span></div>
    </section>
    <section class="login-content">
        <div class="login-form-wrap">
            <div class="login-heading"><p class="login-kicker">ASSET MANAGEMENT</p><h2>Create Staff account</h2><p class="muted">Maintenance accounts are created by an Administrator.</p></div>
            <?php if($msg): ?><div class="alert alert-info auth-message"><?=e($msg)?></div><?php endif; ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
                <label class="form-label" for="name">Full name</label>
                <input class="form-control login-input" id="name" name="name" autocomplete="name" required>
                <label class="form-label" for="email">Email address</label>
                <input class="form-control login-input" id="email" type="email" name="email" autocomplete="email" required>
                <label class="form-label" for="password">Password</label>
                <input class="form-control login-input" id="password" type="password" name="password" minlength="10" autocomplete="new-password" required>
                <button class="btn btn-primary login-submit" type="submit">Create account <span aria-hidden="true">&#8594;</span></button>
            </form>
            <div class="login-links"><p>Already registered? <a href="login.php">Sign in</a></p></div>
        </div>
        <div class="login-content-footer">Clark College of Science and Technology</div>
    </section>
</main>
</body>
</html>