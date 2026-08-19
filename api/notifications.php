<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

try {
    $pdo = getDB();
    $notifications = fetchNotifications($pdo);
    apiJsonResponse([
        'notifications' => $notifications,
        'count'         => count($notifications),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load notifications.', 500);
}
