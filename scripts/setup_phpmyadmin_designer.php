<?php
/**
 * Seed phpMyAdmin Designer with two pages for alcros_db: "ALCROS Core" and "ALCROS Print".
 *
 * Usage (from project root, MySQL running):
 *   C:\xampp\php\php.exe scripts/setup_phpmyadmin_designer.php
 *
 * Does not modify ALCROS application data — only phpMyAdmin storage tables in `phpmyadmin`.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

const PMA_STORAGE_DB = 'phpmyadmin';
const ALCROS_DB = 'alcros_db';

$coreTables = [
    'staff',
    'system_settings',
    'staff_password_otps',
    'document_requests',
    'appointments',
    'queue_tickets',
    'queue_announcements',
    'civil_records',
    'civil_record_edit_locks',
    'birth_record_details',
    'death_record_details',
    'marriage_record_details',
    'activity_logs',
    'delivery_logs',
    'system_errors',
];

$printTables = [
    'print_templates',
    'print_fields',
    'print_calibrations',
    'print_jobs',
];

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . PHP_EOL);
    exit($code);
}

if (!mysqlServerUp()) {
    fail('MySQL is not running. Start it in the XAMPP Control Panel.');
}

if (!databaseIsInstalled()) {
    fail('alcros_db is not installed yet. Run install.php or import alcros.sql + alcros_print.sql first.');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail('Could not connect to MySQL: ' . $e->getMessage());
}

$storageExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = " . $pdo->quote(PMA_STORAGE_DB)
)->fetchColumn();
if ($storageExists === 0) {
    fail(
        'phpMyAdmin configuration storage database `' . PMA_STORAGE_DB . '` was not found.' . PHP_EOL
        . 'In phpMyAdmin, open Home → create/configure storage (or import phpMyAdmin/sql/create_tables.sql).'
    );
}

foreach (['pma__pdf_pages', 'pma__table_coords'] as $table) {
    $exists = (int) $pdo->query(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = ' . $pdo->quote(PMA_STORAGE_DB) . ' AND table_name = ' . $pdo->quote($table)
    )->fetchColumn();
    if ($exists === 0) {
        fail('Missing table `' . PMA_STORAGE_DB . '.' . $table . '`. Enable phpMyAdmin configuration storage.');
    }
}

$pdo->exec('USE `' . ALCROS_DB . '`');
$existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missingCore = array_diff($coreTables, $existing);
$missingPrint = array_diff($printTables, $existing);
if ($missingCore !== [] || $missingPrint !== []) {
    fail(
        'alcros_db is missing tables: '
        . implode(', ', array_merge($missingCore, $missingPrint))
        . '. Import alcros_print.sql if print tables are missing.'
    );
}

$sqlFile = __DIR__ . '/../database/phpmyadmin_designer_layout.sql';
if (!is_file($sqlFile)) {
    fail('SQL file not found: database/phpmyadmin_designer_layout.sql');
}

$sql = file_get_contents($sqlFile);
if ($sql === false || trim($sql) === '') {
    fail('Designer layout SQL file is empty.');
}

$pdo->exec('USE `' . PMA_STORAGE_DB . '`');

try {
    $pdo->exec($sql);
} catch (PDOException $e) {
    fail('Failed to apply designer layout: ' . $e->getMessage());
}

$pages = $pdo->query(
    "SELECT page_nr, page_descr FROM pma__pdf_pages WHERE db_name = " . $pdo->quote(ALCROS_DB) . ' ORDER BY page_nr'
)->fetchAll(PDO::FETCH_ASSOC);

echo 'phpMyAdmin Designer pages for `' . ALCROS_DB . '`:' . PHP_EOL;
foreach ($pages as $page) {
    $count = (int) $pdo->query(
        'SELECT COUNT(*) FROM pma__table_coords WHERE db_name = '
        . $pdo->quote(ALCROS_DB)
        . ' AND pdf_page_number = '
        . (int) $page['page_nr']
    )->fetchColumn();
    echo '  - Page ' . $page['page_nr'] . ': ' . $page['page_descr'] . ' (' . $count . " tables)\n";
}

echo PHP_EOL;
echo 'Open phpMyAdmin → alcros_db → Designer → pick page from the page selector (top).' . PHP_EOL;
echo 'Use "Save" in Designer after dragging tables if you adjust layout.' . PHP_EOL;
