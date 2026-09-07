<?php
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/helpers.php';

$steps = [];
$mark = static function (string $label) use (&$steps): void {
    $steps[] = [$label, microtime(true)];
};

$start = microtime(true);
$mark('start');

try {
    $pdo = getDB();
    $mark('getDB');

    require dirname(__DIR__) . '/includes/printing.php';
    $mark('load printing.php');

    ensurePrintTables($pdo);
    $mark('ensurePrintTables');

    printOfficeLocationFields();
    $mark('printOfficeLocationFields');
} catch (Throwable $e) {
    echo 'ERROR: ' . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

$prev = $start;
foreach ($steps as [$label, $time]) {
    printf("%-30s %6.2fs (+%6.2fs)\n", $label, $time - $start, $time - $prev);
    $prev = $time;
}
