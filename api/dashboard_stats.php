<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

try {
    $pdo = getDB();
    $month = (string) ($_GET['month'] ?? date('Y-m'));
    $scheduleDate = (string) ($_GET['date'] ?? date('Y-m-d'));
    $data = fetchDashboardStats($pdo, isAdmin(), staffId());
    $data['document_labels'] = documentTypeLabelsMap();
    $data['schedule'] = fetchDashboardSchedule($pdo, $scheduleDate, $month);
    apiJsonResponse($data);
} catch (Throwable $e) {
    apiError('Unable to load dashboard stats.', 500);
}
