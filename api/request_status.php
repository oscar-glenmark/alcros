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
    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);

    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at, updated_at,
                appointment_date, appointment_time, email, deleted_at
         FROM document_requests
         WHERE tracking_code = ? AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        apiJsonResponse(['found' => false]);
    }

    $appointment = fetchDocumentRequestAppointment($pdo, (string) $request['tracking_code']);
    $statusSteps = requestStatusWorkflow();
    $currentIdx = publicRequestStatusProgressIndex($request['status']);

    $tracking = publicTrackingRequest($request);
    if ($appointment) {
        $tracking['appointment_status'] = $appointment['status'];
        $tracking['appointment_confirmed'] = $appointment['status'] === 'confirmed';
        if (empty($tracking['appointment_date']) && !empty($appointment['appointment_date'])) {
            $tracking['appointment_date'] = $appointment['appointment_date'];
            $tracking['appointment_time'] = $appointment['appointment_time'] ?? null;
        }
    }

    apiJsonResponse([
        'found'                 => true,
        'request'               => $tracking,
        'document'              => documentTypeLabel($request['document_type']),
        'status_html'           => publicRequestStatusBadge($request['status']),
        'status_label'          => publicRequestStatusLabel($request['status']),
        'status_message'        => publicRequestStatusMessage($request['status'], $appointment),
        'current_idx'           => $currentIdx === false ? -1 : (int) $currentIdx,
        'status_steps'          => $statusSteps,
        'step_labels'           => array_map('requestStatusLabel', $statusSteps),
        'updated_at'            => $request['updated_at'],
        'revision'              => publicTrackingRevision($request, $appointment),
        'appointment_status'    => $appointment['status'] ?? null,
        'appointment_confirmed' => ($appointment['status'] ?? '') === 'confirmed',
    ]);
} catch (Throwable $e) {
    apiError('Unable to load request status.', 500);
}
