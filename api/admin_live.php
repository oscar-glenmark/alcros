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
    ensureSoftDeleteColumns($pdo);
    ensureCitizenNotifyColumns($pdo);

    $limit = isset($_GET['limit']) ? max(1, min(100, (int) $_GET['limit'])) : 20;
    $notifications = fetchNotifications($pdo, $limit);
    foreach ($notifications as $notification) {
        $type = (string) ($notification['type'] ?? '');
        if ($type === 'system' && !isAdmin()) {
            continue;
        }
        if ($type !== 'system' && isAdmin()) {
            continue;
        }
        upsertStaffNotification($notification);
    }

    $counts = isAdmin()
        ? ['pending_requests' => 0, 'pending_appointments' => 0]
        : fetchSidebarActionCounts($pdo);

    apiJsonResponse([
        'notifications' => $notifications,
        'count'         => count($notifications),
        'counts'        => $counts,
        'revision'      => sha1(json_encode([$counts, array_column($notifications, 'id')])),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load live admin data.', 500);
}
