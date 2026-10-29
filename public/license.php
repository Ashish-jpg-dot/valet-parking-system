<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

require_role('admin');

$valetId = isset($_GET['valet']) && is_numeric($_GET['valet']) ? (int) $_GET['valet'] : 0;
$stmt = db()->prepare('SELECT license_photo FROM tbl_valet WHERE valet_id = ? LIMIT 1');
$stmt->bind_param('i', $valetId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

$photo = $row['license_photo'] ?? '';
$base = realpath(UPLOAD_DIR);
$path = $base !== false && $photo !== '' ? realpath($base . DIRECTORY_SEPARATOR . basename($photo)) : false;

if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('Licence document not found.');
}

$mimeByExtension = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
$extension = detected_upload_extension($path);
$mime = $extension !== null ? ($mimeByExtension[$extension] ?? null) : null;
if ($mime === null) {
    http_response_code(415);
    exit('Unsupported document type.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="licence-' . $valetId . '.' . $extension . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
