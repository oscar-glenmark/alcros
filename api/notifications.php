<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/system_errors.php';

requireStaffLogin();

try {
    $pdo = getDB();
    ensureExtendedSchema($pdo);
    $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $notifications = fetchNotifications($pdo, $limit);
    foreach ($notifications as $notification) {
        upsertStaffNotification($notification);
    }
    apiJsonResponse([
        'notifications' => $notifications,
        'count'         => count($notifications),
        'counts'        => fetchSidebarActionCounts($pdo),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load notifications.', 500);
}
