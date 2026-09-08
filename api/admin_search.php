<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    apiJsonResponse(['results' => [], 'q' => '']);
}

rateLimitOrAbort(rateLimitKey('admin_search', staffId()), 60, 60, 'Too many searches. Please wait a moment.');

try {
    $pdo = getDB();
    $payload = staffGlobalSearch($pdo, $q);
    $payload['fallback_url'] = staffGlobalSearchFallbackUrl($q);
    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to search.', 500);
}
