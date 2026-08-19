<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();
requirePageAccess('manage_request.php');

$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$filterType = $_GET['type'] ?? 'all';

try {
    $pdo = getDB();
    apiJsonResponse([
        'requests'        => fetchManageRequests($pdo, $filterStatus, $search, $filterType),
        'document_labels' => documentTypeLabelsMap(),
        'status_classes'  => [
            'pending' => statusBadgeClass('pending'),
            'verified' => statusBadgeClass('verified'),
            'ready' => statusBadgeClass('ready'),
            'completed' => statusBadgeClass('completed'),
            'rejected' => statusBadgeClass('rejected'),
        ],
    ]);
} catch (Throwable $e) {
    apiError('Unable to load requests.', 500);
}
