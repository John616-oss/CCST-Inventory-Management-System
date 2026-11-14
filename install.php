<?php
require_once __DIR__.'/includes/functions.php';
$msg='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 csrf_check();
 $name='System Admin';$email=trim($_POST['email']??'');$pass=$_POST['password']??'';
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<10){$msg='Enter a valid email and a password with at least 10 characters.';}
 else{
  $hash=password_hash($pass,PASSWORD_DEFAULT);
  $stmt=$conn->prepare("INSERT INTO users(full_name,email,password,role,is_verified) VALUES(?,?,?,'admin',1)");
  try{$stmt->bind_param('sss',$name,$email,$hash);$stmt->execute();$msg='Admin account created. Delete install.php now, then log in.';}
  catch(Throwable $e){$msg='Could not create account. It may already exist.';}
 }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><link href="assets/style.css" rel="stylesheet"><title>Install CCST</title></head><body class="bg-light"><div class="container py-5"><div class="card p-4 mx-auto" style="max-width:500px"><h3>CCST Initial Setup</h3><p>Create the first Admin account.</p><?php if($msg)echo '<div class="alert alert-info">'.e($msg).'</div>';?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><label class="form-label">Admin email</label><input class="form-control mb-3" type="email" name="email" required><label class="form-label">Password (minimum 10 characters)</label><input class="form-control mb-3" type="password" name="password" minlength="10" required><button class="btn btn-primary w-100">Create Admin</button></form></div></div></body></html>
