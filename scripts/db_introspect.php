<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/database.php';

$pdo = getDB();
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
sort($tables);
echo 'TABLE_COUNT=' . count($tables) . PHP_EOL;
foreach ($tables as $t) {
    $c = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    echo "$t\t$c" . PHP_EOL;
}
