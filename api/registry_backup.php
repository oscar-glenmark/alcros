<?php
/**
 * Scheduled registry backup (SmarterASP.NET Schedule Task — HTTP GET).
 *
 * Scope matches the Duplicati bundle: civil records, staff, print calibration, related files.
 * Output: storage/backups/registry/alcros-registry-YYYY-mm-dd-His.zip (not web-accessible).
 *
 * Example URL (token from System Settings → Admin Tools → Backup):
 *   https://YOUR-DOMAIN.com/api/registry_backup.php?token=SECRET
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/registry_backup.php';

requireRegistryBackupCronToken();

try {
    $pdo = getDB();
    $result = runAlcrosRegistryHostedBackup($pdo, true);
    if (!$result['ok']) {
        apiJsonResponse($result, 400);
    }

    apiJsonResponse([
        'ok'       => true,
        'filename' => $result['filename'] ?? '',
        'bytes'    => $result['bytes'] ?? 0,
        'pruned'   => $result['pruned'] ?? 0,
        'message'  => $result['message'] ?? '',
    ]);
} catch (Throwable $e) {
    apiError('Registry backup failed.', 500);
}
