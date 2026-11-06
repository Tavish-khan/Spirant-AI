<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(false, 'Unsupported request method.', [], 405);
}

if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(false, 'Image upload failed.', [], 400);
}

$file = $_FILES['image'];
$allowed = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!in_array($mime, $allowed, true)) {
    json_response(false, 'Unsupported file type. Please upload JPG, JPEG, PNG, or WEBP.', [], 400);
}

if ($file['size'] > 5 * 1024 * 1024) {
    json_response(false, 'Image file is too large. Maximum size is 5MB.', [], 400);
}

$extension = match ($mime) {
    'image/jpeg', 'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => 'png',
};

$storedName = uniqid('spirant_', true) . '.' . $extension;
$destinationDir = __DIR__ . '/../uploads/chat-images';
$destinationPath = $destinationDir . '/' . $storedName;

if (!is_dir($destinationDir)) {
    mkdir($destinationDir, 0777, true);
}

error_log(sprintf('Spirant upload-image debug: destination=%s exists=%s', $destinationPath, file_exists($destinationPath) ? 'yes' : 'no'));

if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
    error_log(sprintf('Spirant upload-image debug: move_uploaded_file failed for %s', $destinationPath));
    json_response(false, 'Could not store the uploaded image on the server.', [], 500);
}

error_log(sprintf('Spirant upload-image debug: saved=%s file_exists=%s', $destinationPath, file_exists($destinationPath) ? 'yes' : 'no'));

json_response(true, 'Image uploaded successfully.', [
    'filename' => $storedName,
    'path' => spirant_public_path('/uploads/chat-images/' . $storedName),
]);
