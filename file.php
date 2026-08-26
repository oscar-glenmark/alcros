<?php
/**
 * Authenticated file delivery for sensitive uploads (IDs, staff photos).
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

requireStaffLogin();

$relative = normalizeUploadRelativePath((string) ($_GET['f'] ?? ''));
if ($relative === null) {
    http_response_code(404);
    exit('File not found.');
}

$full = __DIR__ . '/' . $relative;
if (!is_file($full)) {
    http_response_code(404);
    exit('File not found.');
}

$mime = mime_content_type($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($full) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($full);
exit;
