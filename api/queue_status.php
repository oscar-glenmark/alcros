<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$pdo = getDB();
$filter = $_GET['purpose'] ?? 'all';
$mode = $_GET['mode'] ?? 'full';

try {
    $payload = [
        'stats' => fetchQueueStats($pdo),
        'purpose_labels' => queuePurposeLabels(),
    ];

    if ($mode === 'display') {
        $payload['display'] = fetchPublicQueueDisplay($pdo);
    } else {
        $payload['tickets'] = fetchActiveQueueTickets($pdo, $filter);
        $payload['grouped'] = fetchQueueTicketsGrouped($pdo);
        $payload['tables']  = queuePurposeConfig();
        $payload['display'] = fetchPublicQueueDisplay($pdo);
    }

    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to load queue status.', 500);
}
