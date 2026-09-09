<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/printing.php';
require_once __DIR__ . '/../includes/certification_print.php';

$pdo = getDB();
seedCertificationPrintTemplates($pdo);

foreach ($pdo->query("SELECT id, certificate_type, form_number, reference_image, paper_width_mm, paper_height_mm FROM print_templates WHERE document_kind='certification' ORDER BY certificate_type") as $row) {
    echo json_encode($row, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}
