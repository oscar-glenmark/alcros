<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
if ($code === '') {
    apiError('Tracking code is required.');
}

rateLimitOrAbort(rateLimitKey('track_request', $code), 30, 300, 'Too many tracking lookups. Please wait a few minutes.');

try {
    $pdo = getDB();
    migrateLegacyProcessingStatus($pdo);
    $stmt = $pdo->prepare(
        'SELECT tracking_code, citizen_name, document_type, status, submitted_at, updated_at,
                appointment_date, appointment_time, email
         FROM document_requests WHERE tracking_code = ?'
    );
    $stmt->execute([$code]);
    $request = $stmt->fetch();

    if (!$request) {
        apiJsonResponse(['found' => false]);
    }

    $statusSteps = requestStatusWorkflow();
    $currentIdx = requestStatusProgressIndex($request['status']);
    if ($request['status'] === 'rejected') {
        $currentIdx = false;
    }

    apiJsonResponse([
        'found'          => true,
        'request'        => publicTrackingRequest($request),
        'document'       => documentTypeLabel($request['document_type']),
        'status_html'    => requestStatusBadge($request['status']),
        'status_label'   => requestStatusLabel($request['status']),
        'status_message' => requestStatusMessage($request['status']),
        'current_idx'    => $currentIdx === false ? -1 : (int) $currentIdx,
        'status_steps'   => $statusSteps,
        'step_labels'    => array_map('requestStatusLabel', $statusSteps),
        'updated_at'     => $request['updated_at'],
    ]);
} catch (Throwable $e) {
    apiError('Unable to load request status.', 500);
}
