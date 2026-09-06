<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/printing.php';

$pdo = getDB();
ensurePrintTables($pdo);

foreach ($pdo->query('SELECT certificate_type, page_side, reference_image, paper_width_mm, paper_height_mm FROM print_templates ORDER BY certificate_type, page_side') as $row) {
    echo $row['certificate_type'] . ' ' . $row['page_side']
        . ' | ' . $row['reference_image']
        . ' | ' . $row['paper_width_mm'] . 'x' . $row['paper_height_mm'] . PHP_EOL;
}
