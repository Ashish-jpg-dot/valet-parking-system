<?php
require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/helpers.php';

$user = require_role('customer', 'valet', 'admin');
$photoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$photoId) {
    http_response_code(400);
    exit('Invalid photo.');
}

$stmt = db()->prepare(
    'SELECT p.filename, b.customer_id, b.valet_id, rj.valet_id AS return_valet_id
     FROM tbl_inspection_photo p
     JOIN tbl_vehicle_inspection i ON i.inspection_id = p.inspection_id
     JOIN tbl_booking b ON b.booking_id = i.booking_id
     LEFT JOIN tbl_return_job rj ON rj.booking_id = b.booking_id
     WHERE p.photo_id = ? LIMIT 1'
);
$stmt->bind_param('i', $photoId);
$stmt->execute();
$photo = $stmt->get_result()->fetch_assoc();
$stmt->close();

$allowed = $photo && (
    $user['role'] === 'admin'
    || ($user['role'] === 'customer' && (int) $photo['customer_id'] === (int) $user['profile_id'])
    || ($user['role'] === 'valet' && (
        (int) $photo['valet_id'] === (int) $user['profile_id']
        || (int) $photo['return_valet_id'] === (int) $user['profile_id']
    ))
);
if (!$allowed) {
    http_response_code(403);
    exit('Access denied.');
}

$filename = basename((string) $photo['filename']);
$path = INSPECTION_UPLOAD_DIR . '/' . $filename;
if (!is_file($path)) {
    http_response_code(404);
    exit('Photo not found.');
}

$extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
header('Content-Type: ' . ($extension === 'png' ? 'image/png' : 'image/jpeg'));
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
