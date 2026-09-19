<?php
/**
 * Hostinger / live-site scheduled backup endpoint.
 *
 * hPanel → Cron Jobs → every hour:
 *   curl -s "https://YOUR-DOMAIN.com/api/cloud-backup.php?token=YOUR_TOKEN"
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/cloud_backup.php';

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
if (!alcrosValidateCloudBackupCronToken($token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('Forbidden.');
}

@ini_set('memory_limit', '512M');
@set_time_limit(300);

$pdo = getDB();
$result = runAlcrosLiveCloudBackup($pdo);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'upload' => $result['upload'] ?? null,
    'generated_at' => date('c'),
], JSON_UNESCAPED_SLASHES);

exit($result['ok'] ? 0 : 1);
