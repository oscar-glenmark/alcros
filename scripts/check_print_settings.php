<?php
require dirname(__DIR__) . '/config/database.php';

$pdo = getDB();
$keys = [
    'print_form_svg_version',
    'print_paper_size_version',
    'print_field_catalog_version',
    'civil_record_flat_columns_dropped',
    'civil_record_type_tables_migrated',
    'print_field_font_size_version',
    'print_field_alignment_left',
    'print_field_position_restore_v1',
];

foreach ($keys as $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    echo $key . '=' . var_export($value, true) . PHP_EOL;
}

$count = $pdo->query('SELECT COUNT(*) FROM print_fields')->fetchColumn();
echo 'print_fields count=' . $count . PHP_EOL;
