<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/printing.php';

$pdo = getDB();
ensurePrintTables($pdo);

$written = regeneratePrintFormReferenceFiles();
syncPrintTemplateReferenceImages($pdo);
setSetting('print_form_svg_version', '6');

echo "Regenerated " . count($written) . " SVG form templates from official LGU references.\n";
foreach ($written as $path) {
    echo '  - ' . basename($path) . "\n";
}
