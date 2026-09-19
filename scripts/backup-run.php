<?php
/**
 * CLI entry point for Duplicati / Task Scheduler.
 * Usage: php scripts/backup-run.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/duplicati_backup.php';

require_once $root . '/includes/cloud_backup.php';

$upload = in_array('--upload', $argv ?? [], true) || in_array('--cloud', $argv ?? [], true);

$pdo = getDB();
$result = $upload ? runAlcrosLiveCloudBackup($pdo) : runAlcrosDuplicatiBackup($pdo);

$manifest = $result['manifest'] ?? null;
if ($result['ok']) {
    echo ($upload ? 'ALCROS cloud backup OK' : 'ALCROS backup OK') . PHP_EOL;
    if (is_array($manifest)) {
        echo 'Directory: ' . ($manifest['backup_dir'] ?? alcrosDuplicatiBackupDir()) . PHP_EOL;
        echo 'SQL size: ' . alcrosFormatBytes((int) ($manifest['sql_bytes'] ?? 0)) . PHP_EOL;
        echo 'Files copied: ' . (int) ($manifest['files_copied'] ?? 0) . PHP_EOL;
        echo 'Method: ' . ($manifest['dump_method'] ?? 'unknown') . PHP_EOL;
    }
    if (!empty($result['upload']['object'])) {
        echo 'Cloud object: ' . $result['upload']['object'] . PHP_EOL;
    }
} else {
    fwrite(STDERR, 'ALCROS backup FAILED: ' . ($result['message'] ?? 'Unknown error') . PHP_EOL);
}

exit($result['ok'] ? 0 : 1);
