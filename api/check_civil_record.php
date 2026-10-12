<?php
/**
 * Civil record lookup — checks if the citizen is registered in LCRO records.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/civil_record_schema.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('POST required.', 405);
}

rateLimitOrAbort(rateLimitKey('civil_record_check'), 20, 900, 'Too many verification attempts. Please try again later.');

try {
    $pdo = getDB();
    ensureCivilRecordLookupSchema($pdo);
    apiJsonResponse(verifyCitizenCivilRecordFromInput($pdo, $_POST));
} catch (PDOException $e) {
    error_log('check_civil_record: ' . $e->getMessage());
    $message = dbConnectionHelpMessage();
    if (str_contains($e->getMessage(), 'Unknown column') || str_contains($e->getMessage(), 'Base table or view not found')) {
        $message = 'Civil registry database schema is out of date. Ask staff to open Records once while logged in, or run install.php migrations.';
    }
    apiError($message, 503);
}
