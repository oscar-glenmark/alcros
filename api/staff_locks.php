<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/staff_resource_locks.php';
require_once __DIR__ . '/../includes/record_locks.php';

requireStaffLogin();

try {
    $pdo = getDB();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($method === 'POST') {
        requireStaffPostCsrf();
    }

    switch ($action) {
        case 'batch':
            handleStaffLocksBatch($pdo);
            break;
        case 'revision':
            handleStaffLocksRevision($pdo);
            break;
        case 'acquire':
            handleStaffLockAcquire($pdo);
            break;
        case 'refresh':
            handleStaffLockRefresh($pdo);
            break;
        case 'release':
            handleStaffLockRelease($pdo);
            break;
        case 'force_release':
            requireAdmin();
            handleStaffLockForceRelease($pdo);
            break;
        default:
            apiError('Unknown action.', 404);
    }
} catch (InvalidArgumentException $e) {
    apiError($e->getMessage(), 422);
} catch (Throwable $e) {
    apiError('Lock request failed. Refresh and try again.', 500);
}

function staffLockTypeFromInput(string $raw): string
{
    $type = strtolower(trim($raw));
    if ($type === STAFF_LOCK_TYPE_REQUEST || $type === STAFF_LOCK_TYPE_APPOINTMENT) {
        return $type;
    }
    if ($type === 'civil_record') {
        return 'civil_record';
    }

    throw new InvalidArgumentException('Invalid lock type.');
}

function parseStaffLockIds(string $raw): array
{
    if ($raw === '') {
        return [];
    }

    return array_values(array_unique(array_filter(array_map('intval', explode(',', $raw)), static fn (int $id): bool => $id > 0)));
}

function requireStaffLockPageAccess(string $type): void
{
    if ($type === STAFF_LOCK_TYPE_REQUEST) {
        requirePageAccess('manage_request.php');
    } elseif ($type === STAFF_LOCK_TYPE_APPOINTMENT) {
        requirePageAccess('appointment.php');
    }
}

function formattedStaffLocksForIds(PDO $pdo, string $type, array $ids, string $staffId): array
{
    $locks = [];

    if ($type === 'civil_record') {
        foreach ($ids as $id) {
            $row = fetchCivilRecordEditLock($pdo, $id);
            $formatted = $row ? formatCivilRecordEditLock($row, $staffId) : null;
            if ($formatted && !($formatted['active'] ?? false)) {
                $formatted = null;
            }
            $locks[(string) $id] = $formatted;
        }

        return $locks;
    }

    $map = fetchStaffResourceLocksMap($pdo, $type, $ids);
    foreach ($ids as $id) {
        $formatted = isset($map[$id]) ? formatStaffResourceLock($map[$id], $staffId) : null;
        $locks[(string) $id] = $formatted;
    }

    return $locks;
}

function handleStaffLocksBatch(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_GET['type'] ?? ''));
    requireStaffLockPageAccess($type);
    $ids = parseStaffLockIds((string) ($_GET['ids'] ?? ''));

    apiJsonResponse([
        'type'  => $type,
        'locks' => formattedStaffLocksForIds($pdo, $type, $ids, staffId()),
    ]);
}

function handleStaffLocksRevision(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_GET['type'] ?? ''));
    requireStaffLockPageAccess($type);
    $ids = parseStaffLockIds((string) ($_GET['ids'] ?? ''));
    $since = trim((string) ($_GET['since'] ?? ''));

    if ($type === 'civil_record') {
        $revision = sha1(json_encode(formattedStaffLocksForIds($pdo, $type, $ids, staffId()), JSON_UNESCAPED_UNICODE));
    } else {
        $revision = computeStaffResourceLocksRevision($pdo, $type, $ids);
    }

    if ($since !== '' && hash_equals($since, $revision)) {
        apiJsonResponse([
            'unchanged' => true,
            'revision'  => $revision,
            'type'      => $type,
        ]);

        return;
    }

    apiJsonResponse([
        'revision' => $revision,
        'type'     => $type,
        'locks'    => formattedStaffLocksForIds($pdo, $type, $ids, staffId()),
    ]);
}

function handleStaffLockAcquire(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_POST['resource_type'] ?? ''));
    $id = (int) ($_POST['resource_id'] ?? 0);
    if ($id <= 0) {
        apiError('Invalid record.', 422);
    }

    if ($type === STAFF_LOCK_TYPE_REQUEST) {
        requirePageAccess('manage_request.php');
        if (!documentRequestLockEligible($pdo, $id)) {
            apiJsonResponse(['ok' => true, 'skipped' => true, 'reason' => 'not_lockable']);
        }
    } elseif ($type === STAFF_LOCK_TYPE_APPOINTMENT) {
        requirePageAccess('appointment.php');
        if (!appointmentLockEligible($pdo, $id)) {
            apiJsonResponse(['ok' => true, 'skipped' => true, 'reason' => 'not_lockable']);
        }
    } elseif ($type === 'civil_record') {
        requirePageAccess('records.php');
        assertStaffCanManageCivilRecords();
        $result = acquireCivilRecordEditLock($pdo, $id, staffId(), staffName());
        if (!$result['ok']) {
            apiJsonResponse([
                'ok'        => false,
                'error'     => 'This record is being edited by ' . ($result['locked_by'] ?? 'another staff member') . '.',
                'locked_by' => $result['locked_by'] ?? null,
            ], 409);
        }
        apiJsonResponse(['ok' => true, 'resource_type' => $type, 'resource_id' => $id, 'expires_at' => $result['expires_at']]);

        return;
    }

    $result = acquireStaffResourceLock($pdo, $type, $id, staffId(), staffName());
    if (!$result['ok']) {
        apiJsonResponse([
            'ok'        => false,
            'error'     => 'Being handled by ' . ($result['locked_by'] ?? 'another staff member') . '.',
            'locked_by' => $result['locked_by'] ?? null,
        ], 409);
    }

    apiJsonResponse([
        'ok'            => true,
        'resource_type' => $type,
        'resource_id'   => $id,
        'expires_at'    => $result['expires_at'],
    ]);
}

function handleStaffLockRefresh(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_POST['resource_type'] ?? ''));
    $id = (int) ($_POST['resource_id'] ?? 0);
    if ($id <= 0) {
        apiError('Invalid record.', 422);
    }

    if ($type === 'civil_record') {
        requirePageAccess('records.php');
        $result = refreshCivilRecordEditLock($pdo, $id, staffId(), staffName());
        if (!$result['ok']) {
            apiJsonResponse(['ok' => false, 'error' => $result['error'] ?? 'Lock lost.'], 409);
        }
        apiJsonResponse(['ok' => true, 'resource_id' => $id, 'expires_at' => $result['expires_at']]);

        return;
    }

    if ($type === STAFF_LOCK_TYPE_REQUEST) {
        requirePageAccess('manage_request.php');
        if (!documentRequestLockEligible($pdo, $id)) {
            clearStaffResourceLock($pdo, $type, $id);
            apiJsonResponse(['ok' => true, 'skipped' => true, 'reason' => 'not_lockable']);
        }
    } else {
        requirePageAccess('appointment.php');
        if (!appointmentLockEligible($pdo, $id)) {
            clearStaffResourceLock($pdo, $type, $id);
            apiJsonResponse(['ok' => true, 'skipped' => true, 'reason' => 'not_lockable']);
        }
    }

    $result = refreshStaffResourceLock($pdo, $type, $id, staffId(), staffName());
    if (!empty($result['skipped'])) {
        apiJsonResponse(['ok' => true, 'skipped' => true, 'reason' => $result['reason'] ?? 'not_lockable']);
    }
    if (!$result['ok']) {
        apiJsonResponse(['ok' => false, 'error' => $result['error'] ?? 'Lock lost.'], 409);
    }

    apiJsonResponse(['ok' => true, 'resource_id' => $id, 'expires_at' => $result['expires_at']]);
}

function handleStaffLockRelease(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_POST['resource_type'] ?? ''));
    $id = (int) ($_POST['resource_id'] ?? 0);
    if ($id <= 0) {
        apiError('Invalid record.', 422);
    }

    if ($type === 'civil_record') {
        requirePageAccess('records.php');
        releaseCivilRecordEditLock($pdo, $id, staffId());
        apiJsonResponse(['ok' => true, 'released' => true]);

        return;
    }

    if ($type === STAFF_LOCK_TYPE_REQUEST) {
        requirePageAccess('manage_request.php');
    } else {
        requirePageAccess('appointment.php');
    }

    releaseStaffResourceLock($pdo, $type, $id, staffId());
    apiJsonResponse(['ok' => true, 'released' => true]);
}

function handleStaffLockForceRelease(PDO $pdo): void
{
    $type = staffLockTypeFromInput((string) ($_POST['resource_type'] ?? ''));
    $id = (int) ($_POST['resource_id'] ?? 0);
    if ($id <= 0) {
        apiError('Invalid record.', 422);
    }

    if ($type === 'civil_record') {
        $lock = fetchCivilRecordEditLock($pdo, $id);
        if (!$lock || !civilRecordEditLockIsActive($lock)) {
            apiJsonResponse(['ok' => false, 'error' => 'No active lock.'], 404);
        }
        $holder = (string) ($lock['staff_name'] ?: $lock['staff_id']);
        $pdo->prepare('DELETE FROM civil_record_edit_locks WHERE civil_record_id = ?')->execute([$id]);
        logActivity(staffId(), 'Lock Released', staffName() . ' cleared a stale civil record lock on #' . $id
            . ($holder !== '' ? ' (was held by ' . $holder . ')' : ''));
        apiJsonResponse(['ok' => true, 'released' => true]);

        return;
    }

    if (!forceReleaseStaffResourceLock($pdo, $type, $id, staffId(), staffName())) {
        apiJsonResponse(['ok' => false, 'error' => 'No active lock.'], 404);
    }

    apiJsonResponse(['ok' => true, 'released' => true]);
}
