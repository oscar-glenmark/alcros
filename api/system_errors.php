<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/system_errors.php';
require_once __DIR__ . '/../includes/api_helpers.php';

requireStaffLogin();

if (!isAdmin()) {
    apiError('Administrator access required.', 403);
}

$pdo = getDB();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireStaffPostCsrf();
        $errorKey = trim((string) ($_POST['error_key'] ?? ''));
        if ($errorKey === '' || !preg_match('/^[a-z0-9\-]+$/', $errorKey)) {
            apiError('Invalid system error.', 400);
        }

        $result = runSystemErrorFix($pdo, $errorKey, staffId());
        apiJsonResponse([
            'fixed'   => (bool) ($result['ok'] ?? false),
            'message' => (string) ($result['message'] ?? ''),
            'errors'  => fetchActiveSystemErrors($pdo),
        ], ($result['ok'] ?? false) ? 200 : 422);
    }

    syncSystemErrors($pdo);
    apiJsonResponse([
        'errors' => fetchActiveSystemErrors($pdo),
        'count'  => countActiveSystemErrors($pdo),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load system errors.', 500);
}
