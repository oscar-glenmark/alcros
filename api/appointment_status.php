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
    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);

    $stmt = $pdo->prepare(
        'SELECT appointment_code, first_name, middle_name, last_name, service_type, status,
                appointment_date, appointment_time, email, phone, created_at, updated_at
         FROM appointments
         WHERE appointment_code = ? AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$appointment) {
        apiJsonResponse(['found' => false]);
    }

    $statusSteps = appointmentStatusWorkflow();
    $currentIdx = appointmentStatusProgressIndex($appointment['status']);
    if (in_array($appointment['status'], ['cancelled', 'no_show'], true)) {
        $currentIdx = false;
    }

    $updatedAt = $appointment['updated_at'] ?? $appointment['created_at'];

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
        'updated_at'     => $updatedAt,
        'revision'       => sha1(
            (string) ($appointment['appointment_code'] ?? '')
            . '|' . (string) ($appointment['status'] ?? '')
            . '|' . (string) ($appointment['appointment_date'] ?? '')
            . '|' . (string) ($appointment['appointment_time'] ?? '')
            . '|' . (string) $updatedAt
        ),
    ]);
} catch (Throwable $e) {
    apiError('Unable to load appointment status.', 500);
}
