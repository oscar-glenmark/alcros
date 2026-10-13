<?php
/**
 * One-time check: open /db_loader_check.php in the browser, then delete this file.
 */
header('Content-Type: text/plain; charset=UTF-8');
require_once __DIR__ . '/config/database.php';
echo 'ALCROS_DB_LOADER=' . (defined('ALCROS_DB_LOADER') ? (string) ALCROS_DB_LOADER : 'missing') . "\n";
echo 'DB_HOST=' . DB_HOST . "\n";
echo 'DB_NAME=' . DB_NAME . "\n";
echo 'DB_USER=' . DB_USER . "\n";
if (defined('ALCROS_DB_LOADER') && ALCROS_DB_LOADER >= 3) {
    echo "OK: latest database.php is active.\n";
} else {
    echo "FIX: upload config/database.php from your PC (loader v3).\n";
}
