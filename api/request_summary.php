<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

try {
    $pdo = getDB();
    ensureSoftDeleteColumns($pdo);

    apiJsonResponse([
        'pending_count' => countPendingDocumentRequests($pdo),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load request summary.', 500);
}
