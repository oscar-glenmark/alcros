<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/api_helpers.php';

$code = strtoupper(trim($_GET['code'] ?? ''));
if ($code === '') {
    apiError('Appointment code is required.');
}

rateLimitOrAbort(rateLimitKey('track_appointment', $code), 30, 300, 'Too many tracking lookups. Please wait a few minutes.');

try {
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'SELECT appointment_code, citizen_name, service_type, status, appointment_date, appointment_time, email, phone, created_at
         FROM appointments WHERE appointment_code = ?'
    );
    $stmt->execute([$code]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        apiJsonResponse(['found' => false]);
    }

    $statusSteps = appointmentStatusWorkflow();
    $currentIdx = appointmentStatusProgressIndex($appointment['status']);
    if (in_array($appointment['status'], ['cancelled', 'no_show'], true)) {
        $currentIdx = false;
    }

    apiJsonResponse([
        'found'          => true,
        'appointment'    => publicTrackingAppointment($appointment),
        'service'        => appointmentServiceLabel($appointment['service_type']),
        'status_html'    => appointmentStatusBadge($appointment['status']),
        'status_label'   => appointmentStatusLabel($appointment['status']),
        'status_message' => appointmentStatusMessage($appointment['status']),
        'current_idx'    => $currentIdx === false ? -1 : (int) $currentIdx,
        'status_steps'   => $statusSteps,
        'step_labels'    => array_map('appointmentStatusLabel', $statusSteps),
        'updated_at'     => $appointment['created_at'],
    ]);
} catch (Throwable $e) {
    apiError('Unable to load appointment status.', 500);
}
