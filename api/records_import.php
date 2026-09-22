<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/printing.php';
require_once __DIR__ . '/../includes/record_locks.php';
require_once __DIR__ . '/../includes/civil_record_schema.php';
require_once __DIR__ . '/../includes/cascading_location.php';
require_once __DIR__ . '/../includes/records_form.php';
require_once __DIR__ . '/../includes/records_csv_import.php';

requireStaffLogin();
requirePageAccess('records.php');

while (ob_get_level() > 0) {
    ob_end_clean();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    apiError('Method not allowed.', 405);
}

requireStaffPostCsrf();

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    apiError('Invalid import payload.', 422);
}

$importType = (string) ($payload['import_type'] ?? '');
$headers = $payload['headers'] ?? [];
$rows = $payload['rows'] ?? [];
$startLine = max(1, (int) ($payload['start_line'] ?? 1));
$finalize = !empty($payload['finalize']);
$importedTotal = max(0, (int) ($payload['imported_total'] ?? 0));

if (!in_array($importType, ['birth', 'death', 'marriage'], true)) {
    apiError('Invalid import type.', 422);
}
if (!is_array($headers) || !is_array($rows)) {
    apiError('Invalid import rows.', 422);
}

@set_time_limit(300);

try {
    $pdo = getDB();
    ensurePersonNamePartColumns($pdo);
    ensureCivilRecordTypeTables($pdo);
    ensureCivilRecordPrintSchema($pdo);
    ensurePrintDocumentKindColumn($pdo);

    if ($rows === []) {
        apiError('No rows to import.', 422);
    }

    if ($headers !== []) {
        $headers = normalizeCsvHeaderRow($headers);
    }

    $pdo->beginTransaction();
    $result = importCsvParsedRows($pdo, $importType, $headers, $rows, $startLine);
    if ($pdo->inTransaction()) {
        try {
            $pdo->commit();
        } catch (PDOException $commitErr) {
            // MySQL implicitly commits when DDL runs mid-request; treat as success if rows imported.
            if ((int) ($result['imported'] ?? 0) <= 0) {
                throw $commitErr;
            }
            error_log('ALCROS records import commit skipped: ' . $commitErr->getMessage());
        }
    }

    if ($finalize) {
        $loggedTotal = $importedTotal + (int) ($result['imported'] ?? 0);
        if ($loggedTotal > 0) {
            logActivity(
                staffId(),
                'CSV Import',
                'Imported ' . number_format($loggedTotal) . ' ' . $importType . ' record(s) via bulk upload'
            );
        }
    }

    apiJsonResponse(array_merge(['ok' => true], $result));
} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    apiError($e->getMessage(), 422);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ALCROS records import batch failed: ' . $e->getMessage());
    apiError('Import batch failed. Please try again.', 500);
}
