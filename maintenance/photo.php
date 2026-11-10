<?php
require_once __DIR__ . '/../includes/functions.php';
require_role(['maintenance']);
$requestId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$requestId) {
    http_response_code(404);
    exit;
}
$userId = (int) current_user()['id'];
$query = $conn->prepare('SELECT photo_path FROM maintenance_requests WHERE id=? AND (assigned_to=? OR reported_by=?) LIMIT 1');
$query->bind_param('iii', $requestId, $userId, $userId);
$query->execute();
$photo = $query->get_result()->fetch_assoc();
if (!$photo || !preg_match('/\A[a-f0-9]{48}\.(jpg|png|webp)\z/', $photo['photo_path'] ?? '')) {
    http_response_code(404);
    exit;
}
$path = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'ccst_private_uploads' . DIRECTORY_SEPARATOR . 'maintenance' . DIRECTORY_SEPARATOR . $photo['photo_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
$allowed = ['image/jpeg', 'image/png', 'image/webp'];
if (!in_array($mime, $allowed, true)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: inline; filename="damage-photo.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
readfile($path);
