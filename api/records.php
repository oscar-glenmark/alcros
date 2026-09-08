<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/civil_record_schema.php';
require_once __DIR__ . '/../includes/record_locks.php';

try {
    requireStaffLogin();
    requirePageAccess('records.php');

    $pdo = getDB();
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireStaffPostCsrf();
    }

    switch ($action) {
        case 'lock_status':
            handleRecordLockStatus($pdo);
            break;
        case 'acquire_lock':
            handleAcquireRecordLock($pdo);
            break;
        case 'refresh_lock':
            handleRefreshRecordLock($pdo);
            break;
        case 'release_lock':
            handleReleaseRecordLock($pdo);
            break;
        default:
            apiError('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (Throwable $e) {
    apiError('Record lock request failed. Refresh the page and try again.', 500);
}

function handleRecordLockStatus(PDO $pdo): void
{
    $recordId = (int) ($_GET['record_id'] ?? 0);
    if ($recordId <= 0) {
        apiError('Invalid record.', 422);
    }

    if (!fetchFullCivilRecord($pdo, $recordId)) {
        apiError('Record not found.', 404);
    }

    $lock = fetchCivilRecordEditLock($pdo, $recordId);
    apiJsonResponse([
        'record_id' => $recordId,
        'lock'      => $lock ? formatCivilRecordEditLock($lock, staffId()) : null,
    ]);
}

function handleAcquireRecordLock(PDO $pdo): void
{
    $recordId = (int) ($_POST['record_id'] ?? 0);
    if ($recordId <= 0 || !fetchFullCivilRecord($pdo, $recordId)) {
        apiError('Record not found.', 404);
    }

    $result = acquireCivilRecordEditLock($pdo, $recordId, staffId(), staffName());
    if (!$result['ok']) {
        apiJsonResponse([
            'ok'        => false,
            'error'     => 'This record is being edited by ' . ($result['locked_by'] ?? 'another staff member') . '.',
            'locked_by' => $result['locked_by'] ?? null,
            'expires_at'=> $result['expires_at'] ?? null,
        ], 409);
    }

    apiJsonResponse([
        'record_id'  => $recordId,
        'expires_at' => $result['expires_at'],
    ]);
}

function handleRefreshRecordLock(PDO $pdo): void
{
    $recordId = (int) ($_POST['record_id'] ?? 0);
    if ($recordId <= 0) {
        apiError('Invalid record.', 422);
    }

    $result = refreshCivilRecordEditLock($pdo, $recordId, staffId());
    if (!$result['ok']) {
        apiJsonResponse(['ok' => false, 'error' => $result['error'] ?? 'Lock lost.'], 409);
    }

    apiJsonResponse([
        'record_id'  => $recordId,
        'expires_at' => $result['expires_at'],
    ]);
}

function handleReleaseRecordLock(PDO $pdo): void
{
    $recordId = (int) ($_POST['record_id'] ?? 0);
    if ($recordId <= 0) {
        apiError('Invalid record.', 422);
    }

    releaseCivilRecordEditLock($pdo, $recordId, staffId());
    apiJsonResponse(['record_id' => $recordId, 'released' => true]);
}
