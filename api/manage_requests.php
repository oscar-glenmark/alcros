<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();
requirePageAccess('manage_request.php');

try {
    $pdo = getDB();
    ensureCitizenNotifyColumns($pdo);
    ensureSoftDeleteColumns($pdo);
    migrateLegacyProcessingStatus($pdo);

    $filters = manageRequestsListFilters($_GET);
    $focusId = (int) ($_GET['focus_id'] ?? 0);
    $rows = fetchManageRequestsList($pdo, $filters);
    $requests = [];
    foreach ($rows as $row) {
        $view = documentRequestViewData($row);
        $requests[] = [
            'id'                => (int) $row['id'],
            'revision'          => $view['revision'],
            'tracking_code'     => $view['tracking_code'],
            'citizen_name'      => $view['citizen_name'],
            'document_type'     => $view['document_type'],
            'status_key'        => $view['status_key'],
            'status_badge_html' => $view['status_badge_html'],
            'submitted_at'      => $view['submitted_at'],
            'updated_at'        => $view['updated_at'],
        ];
    }

    $response = [
        'stats'    => fetchManageRequestStats($pdo),
        'requests' => $requests,
        'filters'  => $filters,
        'count'    => count($requests),
    ];

    if ($focusId > 0) {
        $includeDeleted = $filters['status'] === 'recently_deleted';
        $focusRow = fetchDocumentRequestById($pdo, $focusId, $includeDeleted);
        if ($focusRow) {
            $response['focus'] = documentRequestViewData($focusRow);
        }
    }

    apiJsonResponse($response);
} catch (Throwable $e) {
    apiError('Unable to load requests.', 500);
}
