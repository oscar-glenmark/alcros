<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/citizen_cancel.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
if ($code === '') {
    apiError('Appointment code is required.');
}

rateLimitOrAbort(rateLimitKey('track_appointment', $code), 30, 300, 'Too many tracking lookups. Please wait a few minutes.');

try {
    $pdo = getDB();
    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);
    ensureRejectionReasonColumns($pdo);

    $payload = buildPublicAppointmentTrackPayload($pdo, $code);
    apiJsonResponse($payload);
} catch (Throwable $e) {
    apiError('Unable to load appointment status.', 500);
}
