<?php
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/civil_record_schema.php';

$pdo = getDB();
$start = microtime(true);
ensureCivilRecordTypeTables($pdo);
echo 'ensureCivilRecordTypeTables: ' . round(microtime(true) - $start, 3) . 's' . PHP_EOL;

$start = microtime(true);
cleanupCivilRecordLegacyColumns($pdo);
echo 'cleanupCivilRecordLegacyColumns: ' . round(microtime(true) - $start, 3) . 's' . PHP_EOL;
