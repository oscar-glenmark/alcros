<?php
require dirname(__DIR__) . '/config/database.php';
require dirname(__DIR__) . '/includes/helpers.php';
require dirname(__DIR__) . '/includes/printing.php';

$pdo = getDB();
ensurePrintTables($pdo);

$limit = (int) ($argv[1] ?? 500);
$stmt = $pdo->query("SELECT cr.* FROM civil_records cr WHERE cr.deleted_at IS NULL ORDER BY cr.id ASC LIMIT {$limit}");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$start = microtime(true);
$hydrated = array_map(static fn (array $row) => hydrateCivilRecordRow($pdo, $row), $rows);
$hydrateTime = microtime(true) - $start;

$start = microtime(true);
foreach ($hydrated as $row) {
    $type = (string) ($row['record_type'] ?? '');
    if (!in_array($type, ['birth', 'death', 'marriage'], true)) {
        continue;
    }
    printBuildFieldValues($row, $type, ['keep_empty' => true]);
}
$buildTime = microtime(true) - $start;

echo "Rows: {$limit}" . PHP_EOL;
echo 'Hydrate: ' . round($hydrateTime, 3) . 's (' . round($hydrateTime / max($limit, 1) * 1000, 2) . 'ms/row)' . PHP_EOL;
echo 'printBuildFieldValues: ' . round($buildTime, 3) . 's (' . round($buildTime / max($limit, 1) * 1000, 2) . 'ms/row)' . PHP_EOL;
echo 'Estimated full export (15316 rows): ' . round(($hydrateTime + $buildTime) / $limit * 15316, 1) . 's' . PHP_EOL;
