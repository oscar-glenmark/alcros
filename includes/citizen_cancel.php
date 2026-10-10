<?php

declare(strict_types=1);

function citizenCancellationReason(): string
{
    return 'Cancelled by the applicant through online tracking.';
}

function isCitizenCancellationReason(?string $reason): bool
{
    $reason = trim((string) $reason);

    return $reason !== '' && str_starts_with($reason, 'Cancelled by the applicant');
}

function documentRequestAllowsCitizenCancel(array $row): bool
{
    return normalizeRequestStatus((string) ($row['status'] ?? '')) === 'pending';
}

function appointmentAllowsCitizenCancel(array $row): bool
{
    return (string) ($row['status'] ?? '') === 'scheduled';
}

function publicCancelledRequestStatusLabel(?string $rejectionReason): string
{
    return isCitizenCancellationReason($rejectionReason) ? 'Cancelled' : publicRequestStatusLabel('rejected');
}

function publicCancelledRequestStatusMessage(?string $rejectionReason): string
{
    if (isCitizenCancellationReason($rejectionReason)) {
        return 'Cancellation complete. Your document request is closed and will not be processed further.';
    }

    return publicRequestStatusMessage('rejected', null, false, $rejectionReason);
}

function publicTrackStatusTone(string $status, ?string $rejectionReason, bool $isDocumentRequest): string
{
    if ($isDocumentRequest && normalizeRequestStatus($status) === 'rejected' && isCitizenCancellationReason($rejectionReason)) {
        return 'success';
    }
    if (!$isDocumentRequest && $status === 'cancelled' && isCitizenCancellationReason($rejectionReason)) {
        return 'success';
    }
    if (in_array($status, ['rejected', 'cancelled', 'no_show'], true)) {
        return 'danger';
    }

    return 'info';
}

function publicAppointmentStatusLabel(string $status, ?string $rejectionReason = null): string
{
    if ($status === 'cancelled' && isCitizenCancellationReason($rejectionReason)) {
        return 'Cancelled';
    }

    return appointmentStatusLabel($status);
}

function publicAppointmentStatusMessage(string $status, ?string $rejectionReason = null): string
{
    if ($status === 'scheduled') {
        return '';
    }
    if ($status === 'cancelled' && isCitizenCancellationReason($rejectionReason)) {
        return 'Cancellation complete. Your time slot has been released.';
    }

    return appointmentStatusMessage($status, $rejectionReason);
}

function publicAppointmentStatusBadge(string $status, ?string $rejectionReason = null): string
{
    $classes = [
        'scheduled' => 'bg-blue-100 text-blue-700',
        'confirmed' => 'bg-purple-100 text-purple-700',
        'completed' => 'bg-gray-100 text-gray-600',
        'cancelled' => isCitizenCancellationReason($rejectionReason)
            ? 'bg-emerald-100 text-emerald-800'
            : 'bg-red-100 text-red-700',
        'no_show'   => 'bg-amber-100 text-amber-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';

    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">'
        . htmlspecialchars(publicAppointmentStatusLabel($status, $rejectionReason))
        . '</span>';
}

function publicRequestStatusBadgeForRow(array $requestRow): string
{
    $status = normalizeRequestStatus((string) ($requestRow['status'] ?? ''));
    $reason = $requestRow['rejection_reason'] ?? null;
    if ($status === 'rejected' && isCitizenCancellationReason($reason)) {
        return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-emerald-100 text-emerald-800">'
            . htmlspecialchars('Cancelled')
            . '</span>';
    }

    return publicRequestStatusBadge($status);
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function citizenCancelDocumentRequest(PDO $pdo, string $trackingCode): array
{
    $trackingCode = strtoupper(trim($trackingCode));
    if ($trackingCode === '') {
        return ['ok' => false, 'error' => 'Tracking code is required.'];
    }

    ensureSoftDeleteColumns($pdo);
    ensureRejectionReasonColumns($pdo);
    migrateLegacyProcessingStatus($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, status, tracking_code FROM document_requests WHERE tracking_code = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$trackingCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Request not found.'];
    }
    if (!documentRequestAllowsCitizenCancel($row)) {
        return ['ok' => false, 'error' => 'This request can no longer be cancelled online. Contact the registry office for help.'];
    }

    $reason = citizenCancellationReason();
    $updated = updateDocumentRequestStatus($pdo, (int) $row['id'], 'rejected', false, $reason);
    if (!$updated) {
        return ['ok' => false, 'error' => 'Unable to cancel this request. Its status may have changed — refresh and try again.'];
    }

    logActivity('citizen', 'Request Cancelled', $trackingCode . ' — applicant cancelled online');

    return ['ok' => true];
}

/**
 * @return array{ok: true}|array{ok: false, error: string}
 */
function citizenCancelAppointment(PDO $pdo, string $appointmentCode): array
{
    $appointmentCode = strtoupper(trim($appointmentCode));
    if ($appointmentCode === '') {
        return ['ok' => false, 'error' => 'Appointment code is required.'];
    }

    ensureSoftDeleteColumns($pdo);
    ensureAppointmentUpdatedColumn($pdo);
    ensureRejectionReasonColumns($pdo);

    $stmt = $pdo->prepare(
        'SELECT id, status, appointment_code FROM appointments WHERE appointment_code = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$appointmentCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'error' => 'Appointment not found.'];
    }
    if (!appointmentAllowsCitizenCancel($row)) {
        return ['ok' => false, 'error' => 'This appointment can no longer be cancelled online. Contact the registry office for help.'];
    }

    $reason = citizenCancellationReason();
    $updated = updateAppointmentStatus($pdo, (int) $row['id'], 'cancelled', $reason);
    if (!$updated) {
        return ['ok' => false, 'error' => 'Unable to cancel this appointment. Its status may have changed — refresh and try again.'];
    }

    logActivity('citizen', 'Appointment Cancelled', $appointmentCode . ' — applicant cancelled online');

    return ['ok' => true];
}

/** @return array<string, mixed> */
function buildPublicRequestTrackPayload(PDO $pdo, string $code): array
{
    $code = strtoupper(trim($code));
    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at, updated_at,
                appointment_date, appointment_time, email, deleted_at, rejection_reason
         FROM document_requests
         WHERE tracking_code = ? AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) {
        return ['found' => false];
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

    $reason = $request['rejection_reason'] ?? null;
    $status = normalizeRequestStatus((string) $request['status']);
    $statusMessage = $status === 'rejected' && isCitizenCancellationReason($reason)
        ? publicCancelledRequestStatusMessage($reason)
        : publicRequestStatusMessage($status, $appointment, false, $reason);

    return [
        'found'                 => true,
        'request'               => $tracking,
        'document'              => documentTypeLabel($request['document_type']),
        'status_html'           => publicRequestStatusBadgeForRow($request),
        'status_label'          => $status === 'rejected' && isCitizenCancellationReason($reason)
            ? 'Cancelled'
            : publicRequestStatusLabel($status),
        'status_message'        => $statusMessage,
        'current_idx'           => $currentIdx === false ? -1 : (int) $currentIdx,
        'status_steps'          => $statusSteps,
        'step_labels'           => array_map('requestStatusLabel', $statusSteps),
        'updated_at'            => $request['updated_at'],
        'revision'              => publicTrackingRevision($request, $appointment),
        'appointment_status'    => $appointment['status'] ?? null,
        'appointment_confirmed' => ($appointment['status'] ?? '') === 'confirmed',
        'can_cancel'            => documentRequestAllowsCitizenCancel($request),
        'cancel_type'           => 'request',
        'cancel_label'          => 'Cancel Request',
        'status_tone'           => publicTrackStatusTone($status, $reason, true),
    ];
}

/** @return array<string, mixed> */
function buildPublicAppointmentTrackPayload(PDO $pdo, string $code): array
{
    $code = strtoupper(trim($code));
    $stmt = $pdo->prepare(
        'SELECT appointment_code, first_name, middle_name, last_name, service_type, status,
                appointment_date, appointment_time, email, phone, created_at, updated_at, rejection_reason
         FROM appointments
         WHERE appointment_code = ? AND deleted_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$code]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appointment) {
        return ['found' => false];
    }

    $statusSteps = appointmentStatusWorkflow();
    $currentIdx = appointmentStatusProgressIndex($appointment['status']);
    if (in_array($appointment['status'], ['cancelled', 'no_show'], true)) {
        $currentIdx = false;
    }

    $updatedAt = $appointment['updated_at'] ?? $appointment['created_at'];
    $reason = $appointment['rejection_reason'] ?? null;

    return [
        'found'          => true,
        'appointment'    => publicTrackingAppointment($appointment),
        'service'        => appointmentServiceLabel($appointment['service_type']),
        'status_html'    => publicAppointmentStatusBadge($appointment['status'], $reason),
        'status_label'   => publicAppointmentStatusLabel($appointment['status'], $reason),
        'status_message' => publicAppointmentStatusMessage($appointment['status'], $reason),
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
            . '|' . (string) ($appointment['rejection_reason'] ?? '')
        ),
        'can_cancel'     => appointmentAllowsCitizenCancel($appointment),
        'cancel_type'    => 'appointment',
        'cancel_label'   => 'Cancel Appointment',
        'status_tone'    => publicTrackStatusTone((string) $appointment['status'], $reason, false),
    ];
}
