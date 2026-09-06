<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$pdo = getDB();
$mode = ($_GET['mode'] ?? 'full') === 'display' ? 'display' : 'full';
$since = trim((string) ($_GET['since'] ?? ''));

try {
    $snapshot = fetchQueueSnapshot($pdo, $mode);
    $revision = (string) ($snapshot['revision'] ?? '');

    if ($since !== '' && $since === $revision) {
        apiJsonResponse([
            'unchanged' => true,
            'revision'  => $revision,
        ]);
    }

    unset($snapshot['revision']);
    $snapshot['revision'] = $revision;
    apiJsonResponse($snapshot);
} catch (Throwable $e) {
    apiError('Unable to load queue status.', 500);
}
