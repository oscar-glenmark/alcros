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

function notifyRequestStatusSms(PDO $pdo, int $requestId, string $newStatus): bool
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

    $status = normalizeRequestStatus($newStatus);
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

function notifyRequestVisitSmsReminder(array $row): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['tracking_code'];
    $doc = documentTypeLabel((string) ($row['document_type'] ?? ''));
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsSiteShortName() . ': Reminder — pickup visit for ' . $doc . ' (' . $code . ') is in about 3 hours.'
        . ($visit !== '' && $visit !== '—' ? ' Schedule: ' . $visit . '.' : '')
        . ' Bring valid ID.';

    return sendCitizenSms((string) $row['phone'], $message, 'visit_reminder_3h', $code);
}

function notifyAppointmentSmsReminder(array $row): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['appointment_code'];
    $service = appointmentServiceLabel((string) ($row['service_type'] ?? ''));
    $visit = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsSiteShortName() . ': Reminder — your ' . $service . ' appointment (' . $code . ') is in about 3 hours.'
        . ($visit !== '' && $visit !== '—' ? ' Schedule: ' . $visit . '.' : '')
        . ' Please arrive on time with valid ID.';

    return sendCitizenSms((string) $row['phone'], $message, 'appointment_reminder_3h', $code);
}

function sendDueSmsVisitReminders(PDO $pdo): int
{
    if (!isSmsConfigured()) {
        return 0;
    }

    ensureCitizenNotifyColumns($pdo);
    $sent = 0;

    $reqStmt = $pdo->query(
        "SELECT id, tracking_code, first_name, middle_name, last_name, phone, document_type, status,
                appointment_date, appointment_time, notify_sms
         FROM document_requests
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND sms_reminder_3h_sent_at IS NULL
           AND status IN ('pending', 'verified', 'ready')
           AND appointment_date IS NOT NULL
           AND appointment_time IS NOT NULL
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 3 HOUR)"
    );
    $requests = $reqStmt ? $reqStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $claimReq = $pdo->prepare('UPDATE document_requests SET sms_reminder_3h_sent_at = NOW() WHERE id = ? AND sms_reminder_3h_sent_at IS NULL');
    $undoReq = $pdo->prepare('UPDATE document_requests SET sms_reminder_3h_sent_at = NULL WHERE id = ?');
    $markLinkedAppt = $pdo->prepare(
        'UPDATE appointments SET sms_reminder_3h_sent_at = NOW()
         WHERE phone = ? AND appointment_date = ? AND appointment_time = ? AND sms_reminder_3h_sent_at IS NULL'
    );

    foreach ($requests as $row) {
        $claimReq->execute([(int) $row['id']]);
        if ($claimReq->rowCount() === 0) {
            continue;
        }
        if (notifyRequestVisitSmsReminder($row)) {
            $sent++;
            $markLinkedAppt->execute([$row['phone'], $row['appointment_date'], $row['appointment_time']]);
        } else {
            $undoReq->execute([(int) $row['id']]);
        }
    }

    $apptStmt = $pdo->query(
        "SELECT id, appointment_code, first_name, middle_name, last_name, phone, service_type,
                appointment_date, appointment_time, notify_sms
         FROM appointments
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND sms_reminder_3h_sent_at IS NULL
           AND status IN ('scheduled', 'confirmed')
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 3 HOUR)"
    );
    $appointments = $apptStmt ? $apptStmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $claimAppt = $pdo->prepare('UPDATE appointments SET sms_reminder_3h_sent_at = NOW() WHERE id = ? AND sms_reminder_3h_sent_at IS NULL');
    $undoAppt = $pdo->prepare('UPDATE appointments SET sms_reminder_3h_sent_at = NULL WHERE id = ?');

    foreach ($appointments as $row) {
        $claimAppt->execute([(int) $row['id']]);
        if ($claimAppt->rowCount() === 0) {
            continue;
        }
        if (notifyAppointmentSmsReminder($row)) {
            $sent++;
        } else {
            $undoAppt->execute([(int) $row['id']]);
        }
    }

    return $sent;
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
