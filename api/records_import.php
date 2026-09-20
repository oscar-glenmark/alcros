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

    if ($finalize && $rows === []) {
        if ($importedTotal > 0) {
            logActivity(
                staffId(),
                'CSV Import',
                'Imported ' . number_format($importedTotal) . ' ' . $importType . ' record(s) via bulk upload'
            );
        }
        apiJsonResponse([
            'ok'              => true,
            'imported'        => 0,
            'skipped'         => 0,
            'sample_skipped'  => 0,
            'errors'          => [],
        ]);
    }

    if ($rows === []) {
        apiError('No rows to import.', 422);
    }

    if ($headers !== []) {
        $headers = normalizeCsvHeaderRow($headers);
    }

    $pdo->beginTransaction();
    $result = importCsvParsedRows($pdo, $importType, $headers, $rows, $startLine);
    $pdo->commit();

    if ($finalize && $importedTotal > 0) {
        logActivity(
            staffId(),
            'CSV Import',
            'Imported ' . number_format($importedTotal) . ' ' . $importType . ' record(s) via bulk upload'
        );
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
