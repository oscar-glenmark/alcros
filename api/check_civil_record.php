<?php
/**
 * Civil record lookup — checks if the citizen is registered in LCRO records.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('POST required.', 405);
}

rateLimitOrAbort(rateLimitKey('civil_record_check'), 20, 900, 'Too many verification attempts. Please try again later.');

$citizenName = citizenNameFromPost($_POST);
$dateOfBirth = trim($_POST['date_of_birth'] ?? '');
$documentType = trim($_POST['document_type'] ?? '');
$dateOfMarriage = trim($_POST['date_of_marriage'] ?? '');

try {
    $pdo = getDB();
    apiJsonResponse(verifyCitizenCivilRecord(
        $pdo,
        $citizenName,
        $dateOfBirth,
        $documentType,
        $dateOfMarriage !== '' ? $dateOfMarriage : null
    ));
} catch (PDOException $e) {
    apiError(dbConnectionHelpMessage(), 503);
}
