<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/staff_resource_locks.php';

requireStaffLogin();
requirePageAccess('manage_request.php');

try {
    $pdo = getDB();
    ensureCitizenNotifyColumns($pdo);
    ensureSoftDeleteColumns($pdo);
    migrateLegacyProcessingStatus($pdo);

    $filters = manageRequestsListFilters($_GET, $pdo);
    $awaitingAllDates = !manageRequestListUsesVisitDate($filters);
    $focusId = (int) ($_GET['focus_id'] ?? 0);
    $rows = fetchManageRequestsList($pdo, $filters);
    $lockMap = fetchStaffResourceLocksMap(
        $pdo,
        STAFF_LOCK_TYPE_REQUEST,
        array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $rows)
    );
    $staffId = staffId();
    $requests = [];
    foreach ($rows as $row) {
        $view = documentRequestViewData($row);
        $rid = (int) $row['id'];
        $lockRow = $lockMap[$rid] ?? null;
        $requests[] = [
            'id'                => $rid,
            'revision'          => $view['revision'],
            'tracking_code'     => $view['tracking_code'],
            'citizen_name'      => $view['citizen_name'],
            'document_type'     => $view['document_type'],
            'status_key'        => $view['status_key'],
            'status_badge_html' => $view['status_badge_html'],
            'submitted_at'      => $view['submitted_at'],
            'updated_at'        => $view['updated_at'],
            'lock'              => staffResourceLockDisplay($pdo, STAFF_LOCK_TYPE_REQUEST, $rid, $lockRow, $staffId),
        ];
    }

    $stats = fetchManageRequestStats($pdo);
    if (!$awaitingAllDates) {
        $visitStats = fetchManageRequestVisitDateStats($pdo, $filters['date']);
        $stats['total'] = $visitStats['visit_total'];
        $stats['ready'] = $visitStats['ready'];
        $stats['completed'] = $visitStats['completed'];
        $stats['no_show'] = $visitStats['no_show'];
    }

    $response = [
        'stats'    => $stats,
        'requests' => $requests,
        'filters'  => $filters,
        'count'    => count($requests),
    ];

    if ($focusId > 0) {
        $includeDeleted = $filters['status'] === 'recently_deleted';
        $focusRow = fetchDocumentRequestById($pdo, $focusId, $includeDeleted);
        if ($focusRow) {
            $focusId = (int) $focusRow['id'];
            $focusLock = touchStaffResourceLockForFocus(
                $pdo,
                STAFF_LOCK_TYPE_REQUEST,
                $focusId,
                staffId(),
                staffName()
            );
            if ($focusLock === null) {
                $lockRow = fetchStaffResourceLock($pdo, STAFF_LOCK_TYPE_REQUEST, $focusId);
                $focusLock = staffResourceLockDisplay($pdo, STAFF_LOCK_TYPE_REQUEST, $focusId, $lockRow, staffId());
            }
            $focus = documentRequestViewData($focusRow);
            $focus['lock'] = $focusLock;
            if ($focusLock && !($focusLock['held_by_you'] ?? false)) {
                $focus['_lockReadOnly'] = true;
            }
            $response['focus'] = $focus;
        }
    }

    apiJsonResponse($response);
} catch (Throwable $e) {
    apiError('Unable to load requests.', 500);
}
