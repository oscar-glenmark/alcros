<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/records_form.php';

try {
    requireStaffLogin();
    requirePageAccess('documents.php');
    requirePageAccess('records.php');

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        apiError('Method not allowed.', 405);
    }

    requireStaffPostCsrf();

    $action = (string) ($_POST['action'] ?? '');
    if ($action !== 'create_record') {
        apiError('Unknown action.', 404);
    }

    $recordType = (string) ($_POST['record_type'] ?? '');
    $printFillRaw = $_POST['print_fill'] ?? '';
    $printFill = is_array($printFillRaw)
        ? $printFillRaw
        : json_decode((string) $printFillRaw, true);

    if (!is_array($printFill)) {
        apiError('Invalid fill-in data.', 422);
    }

    $pdo = getDB();
    ensurePersonNamePartColumns($pdo);
    ensureCivilRecordTypeTables($pdo);
    ensureCivilRecordPrintSchema($pdo);
    syncCivilRecordDerivedFields($pdo);

    try {
        $pdo->query('SELECT deleted_at FROM civil_records LIMIT 1');
    } catch (PDOException $e) {
        $pdo->exec('ALTER TABLE civil_records ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER notes');
    }

    $result = createCivilRecordFromPrintFill($pdo, $recordType, $printFill);

    logActivity(
        staffId(),
        'Record Created',
        'New ' . $result['record_type'] . ' record from Documents: ' . $result['display_name']
    );

    apiJsonResponse([
        'record_id'    => $result['id'],
        'record_type'  => $result['record_type'],
        'display_name' => $result['display_name'],
        'records_url'  => buildAuthUrl('records.php', [
            'type' => $result['record_type'],
            'edit' => $result['id'],
        ]),
    ]);
} catch (InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (PDOException $e) {
    error_log('ALCROS documents create record DB error: ' . $e->getMessage());
    apiError('Could not save the record. Check required fields and try again.', 500);
} catch (Throwable $e) {
    error_log('ALCROS documents create record failed: ' . $e->getMessage());
    apiError('Could not save the record. Please try again.', 500);
}
