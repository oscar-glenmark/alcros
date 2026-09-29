<?php
declare(strict_types=1);

require __DIR__ . '/../includes/security.php';
require __DIR__ . '/../includes/helpers.php';
require __DIR__ . '/../includes/auth.php';

$path = 'uploads/ids/front_3f75f7de39d85d0f.png';
$norm = normalizeUploadRelativePath($path);
$full = __DIR__ . '/../' . str_replace('/', DIRECTORY_SEPARATOR, $path);

echo "normalized: " . var_export($norm, true) . PHP_EOL;
echo "exists: " . (is_file($full) ? 'yes ' . filesize($full) : 'no') . PHP_EOL;

$staff = ['staff_id' => 'TEST', 'name' => 'Test', 'role' => 'Admin'];
$token = createStaffAuthToken($staff);
echo "token length: " . strlen($token) . PHP_EOL;

$ch = curl_init('http://127.0.0.1/alcros/file.php?f=' . rawurlencode($path) . '&raw=1&alcros_auth=' . rawurlencode($token));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_TIMEOUT => 10,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

echo "HTTP: $code type: $ctype" . PHP_EOL;
if (is_string($resp)) {
    $parts = explode("\r\n\r\n", $resp, 2);
    $body = $parts[1] ?? '';
    echo "body len: " . strlen($body) . PHP_EOL;
    echo "body start: " . substr($body, 0, 20) . PHP_EOL;
}
