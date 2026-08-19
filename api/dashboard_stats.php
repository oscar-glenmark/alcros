<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

try {
    $pdo = getDB();
    $data = fetchDashboardStats($pdo, isAdmin(), staffId());
    $data['document_labels'] = documentTypeLabelsMap();
    apiJsonResponse($data);
} catch (Throwable $e) {
    apiError('Unable to load dashboard stats.', 500);
}
