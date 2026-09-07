<?php
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/printing.php';

$pdo = getDB();
ensurePrintTables($pdo);

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    printBuildFieldValues(printCalibrationSampleRecord('birth'), 'birth', ['keep_empty' => true]);
}
$elapsed = microtime(true) - $start;
echo '100x printBuildFieldValues: ' . round($elapsed, 3) . 's (' . round($elapsed / 100 * 1000, 2) . 'ms each)' . PHP_EOL;

$start = microtime(true);
for ($i = 0; $i < 100; $i++) {
    getSetting('print_province', 'Misamis Occidental');
}
$elapsed = microtime(true) - $start;
echo '100x getSetting: ' . round($elapsed, 3) . 's (' . round($elapsed / 100 * 1000, 2) . 'ms each)' . PHP_EOL;
