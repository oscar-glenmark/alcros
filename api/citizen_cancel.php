<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/citizen_cancel.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    apiError('Invalid request method.', 405);
}

requirePublicPostCsrf();

$type = strtolower(trim((string) ($_POST['type'] ?? '')));
$code = strtoupper(trim((string) ($_POST['code'] ?? '')));

if ($code === '' || !in_array($type, ['request', 'appointment'], true)) {
    apiError('A valid tracking code and cancel type are required.', 422);
}

rateLimitOrAbort(rateLimitKey('citizen_cancel', $type . '_' . $code), 8, 900, 'Too many cancel attempts. Please wait and try again.');

try {
    $pdo = getDB();

    if ($type === 'request') {
        $result = citizenCancelDocumentRequest($pdo, $code);
        if (!$result['ok']) {
            apiError($result['error'], 409);
        }
        $payload = buildPublicRequestTrackPayload($pdo, $code);
    } else {
        $result = citizenCancelAppointment($pdo, $code);
        if (!$result['ok']) {
            apiError($result['error'], 409);
        }
        $payload = buildPublicAppointmentTrackPayload($pdo, $code);
    }

    if (empty($payload['found'])) {
        apiError('Record not found after cancellation.', 500);
    }

    apiJsonResponse(array_merge($payload, [
        'ok'      => true,
        'message' => $type === 'request'
            ? 'Your request has been cancelled.'
            : 'Your appointment has been cancelled.',
    ]));
} catch (Throwable $e) {
    apiError('Unable to complete cancellation. Please try again or contact the office.', 500);
}
