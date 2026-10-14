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
    $forAdministrator = isAdmin();
    $notifications = filterStaffPortalNotifications(fetchNotifications($pdo, $limit), $forAdministrator);
    foreach ($notifications as $notification) {
        upsertStaffNotification($notification);
    }

    $counts = $forAdministrator
        ? ['pending_requests' => 0, 'pending_appointments' => 0]
        : fetchSidebarActionCounts($pdo);

    apiJsonResponse([
        'notifications' => $notifications,
        'portal_role'   => $forAdministrator ? 'administrator' : 'staff',
        'count'         => count($notifications),
        'counts'        => $counts,
        'revision'      => sha1(json_encode([$counts, array_column($notifications, 'id'), $forAdministrator])),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load live admin data.', 500);
}
