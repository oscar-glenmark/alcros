<?php
/**
 * Civil record lookup — checks if the citizen is registered in LCRO records.
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError('POST required.', 405);
}

$citizenName = trim($_POST['citizen_name'] ?? '');
$dateOfBirth = trim($_POST['date_of_birth'] ?? '');

try {
    $pdo = getDB();
    apiJsonResponse(verifyCitizenCivilRecord($pdo, $citizenName, $dateOfBirth));
} catch (PDOException $e) {
    apiError(dbConnectionHelpMessage(), 503);
}
