<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/security.php';

requireQueueAnnouncementAccess();

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('Method not allowed.', 405);
}

$action = strtolower(trim((string) ($_POST['action'] ?? $_GET['action'] ?? '')));

try {
    if ($action === 'claim') {
        $active = queueClaimNextAnnouncement($pdo);
        apiJsonResponse([
            'announcement' => $active,
            'revision'     => queueAnnouncementRevision($pdo),
        ]);
    }

    if ($action === 'complete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            apiError('Announcement id is required.');
        }

        $completed = queueCompleteAnnouncement($pdo, $id);
        apiJsonResponse([
            'completed' => $completed,
            'revision'  => queueAnnouncementRevision($pdo),
        ]);
    }

    apiError('Unknown action.');
} catch (Throwable $e) {
    apiError('Unable to update announcement queue.', 500);
}
