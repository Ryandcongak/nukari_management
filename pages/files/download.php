<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/upload_helper.php';

$path = (string)($_GET['path'] ?? '');
$file = upload_resolve_file($path);
if (!$file) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
$inlineMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
$disposition = in_array($mime, $inlineMimes, true) ? 'inline' : 'attachment';
$downloadName = basename($file);

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($file));
header('Content-Disposition: ' . $disposition . '; filename="' . addcslashes($downloadName, '"\\') . '"');
header('Cache-Control: private, max-age=300, no-store');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;

