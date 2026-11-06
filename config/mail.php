<?php
$host = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$port = (int)(getenv('SMTP_PORT') ?: 587);
$username = getenv('SMTP_USERNAME') ?: '';
$password = getenv('SMTP_PASSWORD') ?: '';
$fromEmail = getenv('SMTP_FROM_EMAIL') ?: $username;
$encryption = getenv('SMTP_ENCRYPTION') ?: 'tls';

return [
    'enabled' => $username !== '' && $password !== '',
    'host' => $host,
    'port' => $port,
    'username' => $username,
    'password' => $password,
    'from_email' => $fromEmail,
    'from_name' => getenv('SMTP_FROM_NAME') ?: 'CCST Registrar',
    'encryption' => $encryption
];
