<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/includes/helpers.php';
require_once $root . '/includes/record_locks.php';
require_once $root . '/includes/civil_record_schema.php';
require_once $root . '/includes/api_helpers.php';
require_once $root . '/includes/auth.php';

$errors = [];
$pdo = getDB();
ensureExtendedSchema($pdo);

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$expectedMin = 20;
if (count($tables) < $expectedMin) {
    $errors[] = 'Expected at least ' . $expectedMin . ' tables, found ' . count($tables);
}

foreach (['staff', 'civil_records', 'document_requests', 'appointments', 'queue_tickets', 'activity_logs', 'staff_resource_locks'] as $t) {
    if (!in_array($t, $tables, true)) {
        $errors[] = "Missing table: $t";
    }
}

foreach ([
    'civil_record_edit_locks', 'staff_notifications', 'staff_push_events', 'staff_remember_tokens',
    'staff_password_otps', 'email_logs', 'sms_logs', 'print_calibrations',
] as $gone) {
    if (in_array($gone, $tables, true)) {
        $errors[] = "Legacy table still present: $gone";
    }
}

try {
    $pdo->query('SELECT 1 FROM staff LIMIT 1');
    $pdo->query('SELECT 1 FROM civil_records WHERE deleted_at IS NULL LIMIT 1');
    $pdo->query('SELECT 1 FROM birth_record_details LIMIT 1');
    $pdo->query('SELECT 1 FROM civil_record_updates LIMIT 1');
    $pdo->query('SELECT 1 FROM system_errors LIMIT 1');
    $pdo->query('SELECT 1 FROM delivery_logs LIMIT 1');
    $pdo->query('SELECT 1 FROM print_templates LIMIT 1');
    $pdo->query('SELECT cal_x_offset_mm FROM print_templates LIMIT 1');
} catch (Throwable $e) {
    $errors[] = 'Core SELECT failed: ' . $e->getMessage();
}

$civilCount = (int) $pdo->query('SELECT COUNT(*) FROM civil_records WHERE deleted_at IS NULL')->fetchColumn();
if ($civilCount < 1) {
    $errors[] = 'No civil records found (unexpected for this environment)';
}

$staff = $pdo->query('SELECT staff_id, first_name, last_name FROM staff LIMIT 1')->fetch(PDO::FETCH_ASSOC);
if (!$staff) {
    $errors[] = 'No staff account for lock test';
} else {
    $recordId = (int) $pdo->query('SELECT id FROM civil_records WHERE deleted_at IS NULL LIMIT 1')->fetchColumn();
    if ($recordId > 0) {
        $name = trim(($staff['first_name'] ?? '') . ' ' . ($staff['last_name'] ?? ''));
        $acq = acquireCivilRecordEditLock($pdo, $recordId, (string) $staff['staff_id'], $name, 120);
        if (!($acq['ok'] ?? false)) {
            $errors[] = 'acquireCivilRecordEditLock failed';
        }
        $row = $pdo->prepare('SELECT resource_type FROM staff_resource_locks WHERE resource_type = ? AND resource_id = ?');
        $row->execute([STAFF_LOCK_TYPE_CIVIL_RECORD, $recordId]);
        if ($row->fetchColumn() !== STAFF_LOCK_TYPE_CIVIL_RECORD) {
            $errors[] = 'Lock not stored in staff_resource_locks';
        }
        releaseCivilRecordEditLock($pdo, $recordId, (string) $staff['staff_id']);
    }
}

try {
    fetchNotifications($pdo, 5);
} catch (Throwable $e) {
    $errors[] = 'fetchNotifications failed: ' . $e->getMessage();
}

$activityCount = (int) $pdo->query('SELECT COUNT(*) FROM activity_logs')->fetchColumn();
if ($activityCount < 1) {
    $errors[] = 'activity_logs empty (history may be missing)';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "OK: database feature checks passed (tables=" . count($tables) . ", civil_records=$civilCount, activity_logs=$activityCount)\n";
