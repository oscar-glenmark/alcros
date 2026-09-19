<?php
/**
 * Semaphore SMS integration for ALCROS citizen notifications.
 * Requires includes/helpers.php to be loaded first.
 */

function isSmsEnabled(): bool
{
    return getSetting('sms_enabled', '0') === '1';
}

function isSmsConfigured(): bool
{
    return isSmsEnabled() && trim(getSetting('semaphore_api_key', '')) !== '';
}

function citizenWantsSmsNotify(?array $row): bool
{
    if (!$row || empty($row['notify_sms'])) {
        return false;
    }

    $phone = trim((string) ($row['phone'] ?? ''));
    return $phone !== '' && isValidPhilippineMobile($phone);
}

function normalizeSmsPhone(string $phone): string
{
    $phone = preg_replace('/\D+/', '', trim($phone));
    if ($phone === '') {
        return '';
    }
    if (str_starts_with($phone, '09') && strlen($phone) === 11) {
        return '63' . substr($phone, 1);
    }
    if (str_starts_with($phone, '639') && strlen($phone) === 12) {
        return $phone;
    }
    if (str_starts_with($phone, '9') && strlen($phone) === 10) {
        return '63' . $phone;
    }

    return '';
}

function logSmsDelivery(
    string $recipient,
    string $message,
    string $smsType = 'general',
    ?string $referenceCode = null,
    bool $success = true,
    ?string $errorMessage = null
): void {
    try {
        ensureExtendedSchema(getDB());
        $stmt = getDB()->prepare(
            'INSERT INTO sms_logs (recipient, message, sms_type, reference_code, success, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $recipient,
            mb_substr($message, 0, 500),
            $smsType,
            $referenceCode,
            $success ? 1 : 0,
            $errorMessage,
        ]);
    } catch (Throwable $e) {
        // Non-fatal if logging fails.
    }
}

function sendSemaphoreSms(string $phone, string $message): array
{
    if (!isSmsConfigured()) {
        return ['ok' => false, 'error' => 'SMS is disabled or Semaphore is not configured.'];
    }

    $number = normalizeSmsPhone($phone);
    if ($number === '') {
        return ['ok' => false, 'error' => 'Invalid Philippine mobile number.'];
    }

    $message = trim($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'SMS message is empty.'];
    }
    $payload = [
        'apikey'  => trim(getSetting('semaphore_api_key', '')),
        'number'  => $number,
        'message' => $message,
    ];
    $senderName = trim(getSetting('semaphore_sender_name', ''));
    if ($senderName !== '') {
        $payload['sendername'] = $senderName;
    }

    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is required for Semaphore SMS.'];
    }

    $ch = curl_init('https://api.semaphore.co/api/v4/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Could not reach Semaphore.'];
    }

    $decoded = json_decode($body, true);
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'response' => $decoded ?? $body];
    }

    $error = 'Semaphore returned HTTP ' . $httpCode . '.';
    if (is_array($decoded)) {
        if (!empty($decoded['message'])) {
            $error = (string) $decoded['message'];
        } elseif (!empty($decoded[0]['message'])) {
            $error = (string) $decoded[0]['message'];
        }
    }

    return ['ok' => false, 'error' => $error, 'response' => $decoded ?? $body];
}

function sendCitizenSms(string $phone, string $message, string $smsType = 'general', ?string $referenceCode = null): bool
{
    if (!isSmsEnabled()) {
        logSmsDelivery(
            normalizeSmsPhone($phone) ?: $phone,
            $message,
            $smsType,
            $referenceCode,
            false,
            'SMS is disabled in Settings.'
        );
        return false;
    }

    if (!isSmsConfigured()) {
        logSmsDelivery(
            normalizeSmsPhone($phone) ?: $phone,
            $message,
            $smsType,
            $referenceCode,
            false,
            'Semaphore API key is not configured.'
        );
        return false;
    }

    $result = sendSemaphoreSms($phone, $message);
    logSmsDelivery(
        normalizeSmsPhone($phone) ?: $phone,
        $message,
        $smsType,
        $referenceCode,
        !empty($result['ok']),
        $result['ok'] ? null : ($result['error'] ?? 'Send failed')
    );

    return !empty($result['ok']);
}

function smsSiteShortName(): string
{
    return getSetting('site_name', 'ALCROS');
}

function notifyRequestSubmittedSms(array $data): bool
{
    if (!isSmsConfigured() || !citizenWantsSmsNotify($data)) {
        return false;
    }

    $code = (string) ($data['tracking_code'] ?? '');
    $doc = (string) ($data['document_label'] ?? documentTypeLabel((string) ($data['document_type'] ?? '')));
    $visit = formatAppointmentDisplay($data['appointment_date'] ?? null, $data['appointment_time'] ?? null);
    $site = smsSiteShortName();
    $message = $site . ': Request received for ' . $doc . ' (' . $code . '). Status: pending review.'
        . ($visit !== '' && $visit !== '—' ? ' Preferred visit: ' . $visit . '.' : '')
        . ' Keep your tracking code.';

    return sendCitizenSms((string) $data['phone'], $message, 'request_submitted', $code);
}

function notifyAppointmentBookedSms(array $data): bool
{
    if (!isSmsConfigured() || !citizenWantsSmsNotify($data)) {
        return false;
    }

    $code = (string) ($data['appointment_code'] ?? '');
    $service = appointmentServiceLabel((string) ($data['service_type'] ?? $data['service_label'] ?? ''));
    $visit = formatAppointmentDisplay($data['appointment_date'] ?? null, $data['appointment_time'] ?? null);
    $site = smsSiteShortName();
    $message = $site . ': Appointment booked for ' . $service . ' (' . $code . ').'
        . ($visit !== '' && $visit !== '—' ? ' Preferred schedule: ' . $visit . '.' : '')
        . ' Staff will confirm your visit.';

    return sendCitizenSms((string) $data['phone'], $message, 'appointment_booked', $code);
}

function notifyRequestStatusSms(PDO $pdo, int $requestId, string $newStatus, ?string $staffAction = null): bool
{
    if (!isSmsConfigured()) {
        return false;
    }

    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, phone, document_type, status,
                appointment_date, appointment_time, notify_sms
         FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $status = $staffAction === 'verified' ? 'verified' : normalizeRequestStatus($newStatus);
    $code = (string) $row['tracking_code'];
    $doc = documentTypeLabel((string) $row['document_type']);
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $site = smsSiteShortName();

    $message = match ($status) {
        'verified' => $site . ': Your ' . $doc . ' request ' . $code . ' was accepted.'
            . ($visit !== '' && $visit !== '—' ? ' Confirmed visit: ' . $visit . '.' : '')
            . ' Visit the LCRO with valid ID.',
        'ready' => $site . ': Your ' . $doc . ' request ' . $code . ' is ready for pickup.'
            . ($visit !== '' && $visit !== '—' ? ' Visit: ' . $visit . '.' : '')
            . ' Bring your tracking code and valid ID.',
        default => null,
    };

    if ($message === null) {
        return false;
    }

    return sendCitizenSms((string) $row['phone'], $message, 'request_' . $status, $code);
}

function smsReminderLeadTimes(): array
{
    return [3, 1];
}

function smsReminderSentColumn(int $hours): string
{
    return match ($hours) {
        3 => 'sms_reminder_3h_sent_at',
        1 => 'sms_reminder_1h_sent_at',
        default => throw new InvalidArgumentException('Unsupported SMS reminder lead time.'),
    };
}

function smsReminderHoursLabel(int $hours): string
{
    return $hours === 1 ? '1 hour' : $hours . ' hours';
}

function nextDueSmsReminderLeadTime(int $minutesUntil): ?int
{
    if ($minutesUntil <= 0) {
        return null;
    }
    if ($minutesUntil <= 60) {
        return 1;
    }
    if ($minutesUntil <= 180) {
        return 3;
    }

    return null;
}

function markEarlierSmsRemindersSkipped(PDO $pdo, string $table, int $id, int $sentHours): void
{
    if (!in_array($table, ['document_requests', 'appointments'], true)) {
        return;
    }

    foreach (smsReminderLeadTimes() as $hours) {
        if ($hours <= $sentHours) {
            continue;
        }
        $column = smsReminderSentColumn($hours);
        $stmt = $pdo->prepare(
            "UPDATE `$table` SET `$column` = COALESCE(`$column`, NOW()) WHERE id = ? AND `$column` IS NULL"
        );
        $stmt->execute([$id]);
    }
}

function notifyRequestVisitSmsReminder(array $row, int $hoursBefore = 3): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['tracking_code'];
    $doc = documentTypeLabel((string) ($row['document_type'] ?? ''));
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $hoursLabel = smsReminderHoursLabel($hoursBefore);
    $message = smsSiteShortName() . ': Reminder — pickup visit for ' . $doc . ' (' . $code . ') is in about ' . $hoursLabel . '.'
        . ($visit !== '' && $visit !== '—' ? ' Schedule: ' . $visit . '.' : '')
        . ' Bring valid ID.';

    return sendCitizenSms(
        (string) $row['phone'],
        $message,
        'visit_reminder_' . $hoursBefore . 'h',
        $code
    );
}

function notifyAppointmentSmsReminder(array $row, int $hoursBefore = 3): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['appointment_code'];
    $service = appointmentServiceLabel((string) ($row['service_type'] ?? ''));
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $hoursLabel = smsReminderHoursLabel($hoursBefore);
    $message = smsSiteShortName() . ': Reminder — your ' . $service . ' appointment (' . $code . ') is in about ' . $hoursLabel . '.'
        . ($visit !== '' && $visit !== '—' ? ' Schedule: ' . $visit . '.' : '')
        . ' Please arrive on time with valid ID.';

    return sendCitizenSms(
        (string) $row['phone'],
        $message,
        'appointment_reminder_' . $hoursBefore . 'h',
        $code
    );
}

function notifyAppointmentStatusSms(PDO $pdo, int $appointmentId, string $newStatus): bool
{
    if (!isSmsConfigured()) {
        return false;
    }

    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT appointment_code, phone, service_type, appointment_date, appointment_time, notify_sms
         FROM appointments WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) ($row['appointment_code'] ?? '');
    $service = appointmentServiceLabel((string) ($row['service_type'] ?? ''));
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $site = smsSiteShortName();

    $message = match ($newStatus) {
        'confirmed' => $site . ': Your ' . $service . ' appointment ' . $code . ' is confirmed.'
            . ($visit !== '' && $visit !== '—' ? ' Schedule: ' . $visit . '.' : '')
            . ' Please arrive on time with valid ID.',
        'cancelled' => $site . ': Your ' . $service . ' appointment ' . $code . ' was cancelled.'
            . ' Contact the LCRO if you need to rebook.',
        'completed' => $site . ': Your ' . $service . ' appointment ' . $code . ' is marked completed. Thank you.',
        'no_show' => $site . ': You were marked no-show for appointment ' . $code . '.'
            . ' Contact the LCRO to reschedule.',
        default => null,
    };

    if ($message === null) {
        return false;
    }

    return sendCitizenSms((string) $row['phone'], $message, 'appointment_' . $newStatus, $code);
}

function sendDueSmsReminderRow(
    PDO $pdo,
    array $row,
    string $table,
    callable $notifyFn,
    ?callable $afterSendFn = null
): bool {
    $minutesUntil = appointmentMinutesUntil($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    if ($minutesUntil === null) {
        return false;
    }

    $leadTime = nextDueSmsReminderLeadTime($minutesUntil);
    if ($leadTime === null) {
        return false;
    }

    $column = smsReminderSentColumn($leadTime);
    if (!empty($row[$column])) {
        return false;
    }

    $claim = $pdo->prepare("UPDATE `$table` SET `$column` = NOW() WHERE id = ? AND `$column` IS NULL");
    $undo = $pdo->prepare("UPDATE `$table` SET `$column` = NULL WHERE id = ?");
    $claim->execute([(int) $row['id']]);
    if ($claim->rowCount() === 0) {
        return false;
    }

    if ($notifyFn($row, $leadTime)) {
        markEarlierSmsRemindersSkipped($pdo, $table, (int) $row['id'], $leadTime);
        if ($afterSendFn !== null) {
            $afterSendFn($row, $leadTime);
        }

        return true;
    }

    $undo->execute([(int) $row['id']]);

    return false;
}

function sendDueDocumentRequestSmsReminders(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT id, tracking_code, first_name, middle_name, last_name, phone, document_type, status,
                appointment_date, appointment_time, notify_sms,
                sms_reminder_3h_sent_at, sms_reminder_1h_sent_at
         FROM document_requests
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND status IN ('pending', 'verified', 'ready')
           AND appointment_date IS NOT NULL
           AND appointment_time IS NOT NULL
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 3 HOUR)
           AND (sms_reminder_3h_sent_at IS NULL OR sms_reminder_1h_sent_at IS NULL)"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $sent = 0;

    foreach ($rows as $row) {
        $afterSend = static function (array $sentRow, int $leadTime) use ($pdo): void {
            $trackingCode = trim((string) ($sentRow['tracking_code'] ?? ''));
            if ($trackingCode === '') {
                return;
            }
            $column = smsReminderSentColumn($leadTime);
            $markLinkedAppt = $pdo->prepare(
                "UPDATE appointments SET `$column` = NOW() WHERE tracking_code = ? AND `$column` IS NULL"
            );
            $markLinkedAppt->execute([$trackingCode]);
        };

        if (sendDueSmsReminderRow($pdo, $row, 'document_requests', 'notifyRequestVisitSmsReminder', $afterSend)) {
            $sent++;
        }
    }

    return $sent;
}

function sendDueStandaloneAppointmentSmsReminders(PDO $pdo): int
{
    $stmt = $pdo->query(
        "SELECT id, appointment_code, first_name, middle_name, last_name, phone, service_type,
                appointment_date, appointment_time, notify_sms,
                sms_reminder_3h_sent_at, sms_reminder_1h_sent_at
         FROM appointments
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND status IN ('scheduled', 'confirmed')
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 3 HOUR)
           AND (sms_reminder_3h_sent_at IS NULL OR sms_reminder_1h_sent_at IS NULL)"
    );
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $sent = 0;

    foreach ($rows as $row) {
        if (sendDueSmsReminderRow($pdo, $row, 'appointments', 'notifyAppointmentSmsReminder')) {
            $sent++;
        }
    }

    return $sent;
}

function sendDueSmsVisitReminders(PDO $pdo): int
{
    if (!isSmsConfigured()) {
        return 0;
    }

    ensureCitizenNotifyColumns($pdo);

    return sendDueDocumentRequestSmsReminders($pdo) + sendDueStandaloneAppointmentSmsReminders($pdo);
}

function smsConfigurationSummary(): array
{
    return [
        'enabled'    => isSmsEnabled(),
        'configured' => isSmsConfigured(),
        'sender'     => trim(getSetting('semaphore_sender_name', '')),
        'has_api_key'=> trim(getSetting('semaphore_api_key', '')) !== '',
    ];
}
