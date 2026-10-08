<?php
/**
 * IPROG SMS integration for ALCROS citizen notifications.
 * Requires includes/helpers.php to be loaded first.
 */

function isSmsEnabled(): bool
{
    return getSetting('sms_enabled', '0') === '1';
}

function isSmsConfigured(): bool
{
    purgeObsoleteSemaphoreSettings();

    return isSmsEnabled() && iprogApiToken() !== '';
}

function purgeObsoleteSemaphoreSettings(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        getDB()->exec(
            "DELETE FROM system_settings WHERE setting_key IN ('semaphore_api_key', 'semaphore_sender_name')"
        );
    } catch (Throwable $e) {
        // Non-fatal if the settings table is unavailable.
    }

    $legacyCache = __DIR__ . '/../storage/semaphore_sender_names.json';
    if (is_file($legacyCache)) {
        @unlink($legacyCache);
    }
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

const IPROG_SMS_API_BASE = 'https://sms.iprogtech.com/api/v1';

function iprogApiToken(): string
{
    return trim(getSetting('iprog_api_token', ''));
}

function iprogSenderName(): string
{
    return trim(getSetting('iprog_sender_name', ''));
}

function iprogHttpRequest(string $method, string $path, array $payload = []): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'PHP cURL extension is required for IPROG SMS.'];
    }

    $apiToken = iprogApiToken();
    if ($apiToken === '') {
        return ['ok' => false, 'error' => 'IPROG API token is not configured.'];
    }

    $url = IPROG_SMS_API_BASE . $path;
    $payload = array_merge(['api_token' => $apiToken], $payload);

    $ch = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
    ];
    if (strtoupper($method) === 'GET') {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($payload);
        curl_setopt($ch, CURLOPT_URL, $url);
    } else {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : 'Could not reach IPROG SMS.'];
    }

    $decoded = json_decode($body, true);

    return [
        'ok'       => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'body'     => $body,
        'decoded'  => $decoded,
    ];
}

function smsProviderSendBlockReason(): ?string
{
    if (!isSmsEnabled() || !isSmsConfigured()) {
        return null;
    }

    try {
        ensureExtendedSchema(getDB());
        // Only warn when the most recent attempt failed — not when an older failure precedes a later success.
        $stmt = getDB()->query(
            'SELECT success, error_message FROM sms_logs ORDER BY sent_at DESC, id DESC LIMIT 1'
        );
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row || !empty($row['success'])) {
            return null;
        }

        $reason = trim((string) ($row['error_message'] ?? ''));
        if ($reason === '') {
            return null;
        }
        if (stripos($reason, 'Smart/TNT') !== false) {
            return $reason;
        }
        if (stripos($reason, 'phishing') !== false || stripos($reason, 'suspicious') !== false) {
            return 'IPROG blocked the last SMS (often due to links in the message). ALCROS no longer puts URLs in SMS; retry after saving Configuration.';
        }

        return 'The last SMS attempt failed: ' . $reason;
    } catch (Throwable $e) {
        return null;
    }
}

function smsSenderConfigurationHint(): ?string
{
    return smsProviderSendBlockReason();
}

function iprogFormatApiError(mixed $decoded, int $httpCode): string
{
    if (is_array($decoded)) {
        foreach (['message', 'error', 'errors'] as $key) {
            if (!isset($decoded[$key])) {
                continue;
            }
            $value = $decoded[$key];
            if (is_array($value)) {
                $flat = [];
                array_walk_recursive($value, static function ($item) use (&$flat): void {
                    if (is_scalar($item) && (string) $item !== '') {
                        $flat[] = (string) $item;
                    }
                });
                if ($flat !== []) {
                    return implode(' ', $flat);
                }
                continue;
            }
            if ((string) $value !== '') {
                return (string) $value;
            }
        }
    }

    return 'IPROG returned HTTP ' . $httpCode . '.';
}

function iprogResponseIndicatesSuccess(mixed $decoded, int $httpCode): bool
{
    if ($httpCode < 200 || $httpCode >= 300) {
        return false;
    }
    if (!is_array($decoded)) {
        return $httpCode >= 200 && $httpCode < 300;
    }

    if (isset($decoded['status'])) {
        $status = $decoded['status'];
        if (is_numeric($status)) {
            $code = (int) $status;
            if ($code >= 400) {
                return false;
            }
            if ($code >= 200 && $code < 300) {
                return true;
            }
        }
        $statusText = strtolower((string) $status);
        if (in_array($statusText, ['error', 'failed', 'fail'], true)) {
            return false;
        }
        if (in_array($statusText, ['success', 'ok', 'queued', 'pending'], true)) {
            return true;
        }
    }

    return !empty($decoded['message_id']) || !empty($decoded['message_ids']);
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

/** Remove links IPROG often blocks (http localhost, non-https, etc.). Requirements belong in email. */
function smsSanitizeMessageForIprog(string $message): string
{
    $message = trim(preg_replace('/\s+/u', ' ', $message));
    if ($message === '') {
        return '';
    }

    if (preg_match('#https?://#iu', $message)) {
        $message = preg_replace('#https?://[^\s]+#iu', '', $message);
        $message = preg_replace('#\bRequirements:\s*#iu', '', $message);
        $message = trim(preg_replace('/\s+/u', ' ', $message));
    }

    return $message;
}

function sendIprogSms(string $phone, string $message): array
{
    if (!isSmsConfigured()) {
        return ['ok' => false, 'error' => 'SMS is disabled or IPROG is not configured.'];
    }

    $number = normalizeSmsPhone($phone);
    if ($number === '') {
        return ['ok' => false, 'error' => 'Invalid Philippine mobile number.'];
    }

    $message = smsSanitizeMessageForIprog($message);
    if ($message === '') {
        return ['ok' => false, 'error' => 'SMS message is empty.'];
    }

    $payload = [
        'phone_number' => $number,
        'message'      => $message,
        'sms_provider' => 2,
    ];

    $result = iprogHttpRequest('POST', '/sms_messages', $payload);
    $httpCode = (int) ($result['httpCode'] ?? 0);
    $decoded = $result['decoded'] ?? null;

    if (empty($result['ok']) || !iprogResponseIndicatesSuccess($decoded, $httpCode)) {
        return [
            'ok'       => false,
            'error'    => $result['error'] ?? iprogFormatApiError($decoded, $httpCode),
            'response' => $decoded ?? ($result['body'] ?? null),
        ];
    }

    return ['ok' => true, 'response' => $decoded ?? ($result['body'] ?? null)];
}

function smsLastDeliveryError(): ?string
{
    $err = $GLOBALS['alcros_sms_last_delivery_error'] ?? null;

    return is_string($err) && $err !== '' ? $err : null;
}

/** @return array{attempted: bool, sent: bool} */
function citizenSmsNotifySkipped(): array
{
    return ['attempted' => false, 'sent' => false];
}

/** @return array{attempted: bool, sent: bool} */
function citizenSmsNotifyResult(bool $sent): array
{
    return ['attempted' => true, 'sent' => $sent];
}

function sendCitizenSms(string $phone, string $message, string $smsType = 'general', ?string $referenceCode = null): bool
{
    $GLOBALS['alcros_sms_last_delivery_error'] = null;

    if (!isSmsEnabled()) {
        $GLOBALS['alcros_sms_last_delivery_error'] = 'SMS is disabled in Settings.';
        logSmsDelivery(
            normalizeSmsPhone($phone) ?: $phone,
            $message,
            $smsType,
            $referenceCode,
            false,
            $GLOBALS['alcros_sms_last_delivery_error']
        );
        return false;
    }

    if (!isSmsConfigured()) {
        $GLOBALS['alcros_sms_last_delivery_error'] = 'IPROG API token is not configured.';
        logSmsDelivery(
            normalizeSmsPhone($phone) ?: $phone,
            $message,
            $smsType,
            $referenceCode,
            false,
            $GLOBALS['alcros_sms_last_delivery_error']
        );
        return false;
    }

    $result = sendIprogSms($phone, $message);
    $error = $result['ok'] ? null : ($result['error'] ?? 'Send failed');
    if ($error !== null && $error !== '') {
        $GLOBALS['alcros_sms_last_delivery_error'] = $error;
    }
    logSmsDelivery(
        normalizeSmsPhone($phone) ?: $phone,
        $message,
        $smsType,
        $referenceCode,
        !empty($result['ok']),
        $error
    );

    return !empty($result['ok']);
}

/** IPROG template header already includes the site name — body must stay ≤150 chars. */
const SMS_BODY_MAX_LENGTH = 150;

function smsCitizenGreeting(array $row): string
{
    $first = trim((string) ($row['first_name'] ?? ''));
    if ($first !== '') {
        return 'Hi ' . $first . ',';
    }

    $name = trim(personNameFromRow($row));
    if ($name === '') {
        return 'Hello,';
    }

    $short = preg_split('/\s+/u', $name, 2)[0] ?? $name;

    return 'Hi ' . $short . ',';
}

/** Visit date/time line for SMS (month spelled out, e.g. September 12, 2026). */
function smsVisitShort(?string $date, ?string $time): string
{
    if ($date === null || trim($date) === '') {
        return '';
    }

    $dateOnly = trim($date);
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateOnly, $match)) {
        $dateOnly = $match[1];
    }

    $ts = strtotime($dateOnly);
    if ($ts === false) {
        return '';
    }

    $out = date('F j, Y', $ts);
    if ($time !== null && trim($time) !== '') {
        $timeTs = strtotime(trim($time));
        if ($timeTs !== false) {
            $out .= ' ' . date('g:i A', $timeTs);
        }
    }

    return $out;
}

/**
 * Single-paragraph SMS — capped at SMS_BODY_MAX_LENGTH (IPROG header is separate).
 * Pass $pinnedTail to always keep it and trim earlier segments (no URLs — IPROG rejects many links).
 *
 * @param list<string|null> $segments
 */
function smsComposeCitizenMessage(array $segments, ?string $pinnedTail = null): string
{
    $parts = [];
    foreach ($segments as $segment) {
        $segment = trim(preg_replace('/\s+/u', ' ', (string) $segment));
        if ($segment !== '') {
            $parts[] = $segment;
        }
    }

    $pinnedTail = trim(preg_replace('/\s+/u', ' ', (string) ($pinnedTail ?? '')));
    if ($pinnedTail !== '') {
        $suffix = ' ' . $pinnedTail;
        $budget = SMS_BODY_MAX_LENGTH - mb_strlen($suffix);
        if ($budget < 20) {
            return mb_substr($pinnedTail, 0, SMS_BODY_MAX_LENGTH);
        }
        $body = implode(' ', $parts);
        while ($parts !== [] && mb_strlen($body) > $budget) {
            array_pop($parts);
            $body = implode(' ', $parts);
        }
        if (mb_strlen($body) > $budget) {
            $body = mb_substr($body, 0, max(0, $budget - 1)) . '…';
        }

        return trim($body . $suffix);
    }

    while ($parts !== [] && mb_strlen(implode(' ', $parts)) > SMS_BODY_MAX_LENGTH) {
        array_pop($parts);
    }

    $message = implode(' ', $parts);
    if (mb_strlen($message) > SMS_BODY_MAX_LENGTH) {
        $message = mb_substr($message, 0, SMS_BODY_MAX_LENGTH - 1) . '…';
    }

    return $message;
}

/** @param list<string|null> $segments */
function smsComposeStandaloneAppointmentMessage(array $row, array $segments): string
{
    require_once __DIR__ . '/appointment_notice_requirements.php';
    $tail = smsTailForStandaloneAppointment($row);
    if ($tail === 'Bring valid ID.') {
        $segments[] = $tail;

        return smsComposeCitizenMessage($segments);
    }
    if ($tail === '') {
        return smsComposeCitizenMessage($segments);
    }

    return smsComposeCitizenMessage($segments, $tail);
}

function notifyRequestSubmittedSms(array $data): bool
{
    if (!isSmsConfigured() || !citizenWantsSmsNotify($data)) {
        return false;
    }

    $code = (string) ($data['tracking_code'] ?? '');
    $doc = (string) ($data['document_label'] ?? documentTypeLabel((string) ($data['document_type'] ?? '')));
    $visit = smsVisitShort($data['appointment_date'] ?? null, $data['appointment_time'] ?? null);
    $message = smsComposeCitizenMessage([
        smsCitizenGreeting($data),
        $doc . ' request received.',
        'Code ' . $code . '.',
        $visit !== '' ? 'Visit ' . $visit . '.' : '',
        'Pending review.',
    ]);

    return sendCitizenSms((string) $data['phone'], $message, 'request_submitted', $code);
}

/** Standalone appointments: SMS is sent when staff confirms, not at booking. */
function notifyAppointmentBookedSms(array $data): bool
{
    return false;
}

/** @return array{attempted: bool, sent: bool} */
function notifyRequestStatusSms(
    PDO $pdo,
    int $requestId,
    string $newStatus,
    ?string $staffAction = null,
    ?string $rejectionReason = null
): array {
    if (!isSmsConfigured()) {
        return citizenSmsNotifySkipped();
    }

    ensureCitizenNotifyColumns($pdo);
    ensureRejectionReasonColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, phone, document_type, status,
                appointment_date, appointment_time, notify_sms, rejection_reason
         FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!citizenWantsSmsNotify($row)) {
        return citizenSmsNotifySkipped();
    }

    $status = $staffAction === 'verified' ? 'verified' : normalizeRequestStatus($newStatus);
    $code = (string) $row['tracking_code'];
    $doc = documentTypeLabel((string) $row['document_type']);

    if ($status === 'rejected') {
        $reason = normalizeStaffRejectionReason($rejectionReason ?? ($row['rejection_reason'] ?? null));
        $tail = $reason !== null ? 'Reason: ' . $reason : 'Contact the office for help.';
        $message = smsComposeCitizenMessage([
            smsCitizenGreeting($row),
            $doc . ' request declined.',
            'Code ' . $code . '.',
        ], $tail);

        return citizenSmsNotifyResult(sendCitizenSms((string) $row['phone'], $message, 'request_rejected', $code));
    }

    if ($status !== 'verified' && $status !== 'ready') {
        return citizenSmsNotifySkipped();
    }

    $appointment = fetchDocumentRequestAppointment($pdo, $code);
    $visitDate = $appointment['appointment_date'] ?? $row['appointment_date'] ?? null;
    $visitTime = $appointment['appointment_time'] ?? $row['appointment_time'] ?? null;
    $visit = smsVisitShort($visitDate, $visitTime);
    $message = smsComposeCitizenMessage([
        smsCitizenGreeting($row),
        $doc . ' ready for pickup.',
        'Code ' . $code . '.',
        $visit !== '' ? 'Visit ' . $visit . '.' : '',
        'Bring valid ID.',
    ]);

    return citizenSmsNotifyResult(sendCitizenSms((string) $row['phone'], $message, 'request_ready', $code));
}

function smsReminderLeadTimes(): array
{
    return [1];
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

function notifyRequestVisitSmsReminder(array $row, int $hoursBefore = 1): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['tracking_code'];
    $hoursLabel = smsReminderHoursLabel($hoursBefore);
    $visit = smsVisitShort($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsComposeCitizenMessage([
        smsCitizenGreeting($row),
        'Pickup in ' . $hoursLabel . '.',
        'Code ' . $code . '.',
        $visit !== '' ? $visit . '.' : '',
        'Bring valid ID.',
    ]);

    return sendCitizenSms(
        (string) $row['phone'],
        $message,
        'visit_reminder_' . $hoursBefore . 'h',
        $code
    );
}

function notifyAppointmentSmsReminder(array $row, int $hoursBefore = 1): bool
{
    if (!citizenWantsSmsNotify($row)) {
        return false;
    }

    $code = (string) $row['appointment_code'];
    $hoursLabel = smsReminderHoursLabel($hoursBefore);
    $visit = smsVisitShort($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsComposeStandaloneAppointmentMessage($row, [
        smsCitizenGreeting($row),
        'Appt in ' . $hoursLabel . '.',
        'Code ' . $code . '.',
        $visit !== '' ? $visit . '.' : '',
    ]);

    return sendCitizenSms(
        (string) $row['phone'],
        $message,
        'appointment_reminder_' . $hoursBefore . 'h',
        $code
    );
}

function notifyRequestVisitSoonSms(array $row): bool
{
    if (!isSmsConfigured() || !citizenWantsSmsNotify($row)) {
        return false;
    }

    $minutesUntil = appointmentMinutesUntil($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    if ($minutesUntil === null || $minutesUntil <= 0 || $minutesUntil > visitSoonWindowMinutes()) {
        return false;
    }

    $code = (string) ($row['tracking_code'] ?? '');
    $soonLabel = formatVisitSoonLabel($minutesUntil);
    $visit = smsVisitShort($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsComposeCitizenMessage([
        smsCitizenGreeting($row),
        'Visit in ' . $soonLabel . '.',
        'Code ' . $code . '.',
        $visit !== '' ? $visit . '.' : '',
    ]);

    return sendCitizenSms((string) $row['phone'], $message, 'visit_soon', $code);
}

function notifyAppointmentVisitSoonSms(array $row): bool
{
    if (!isSmsConfigured() || !citizenWantsSmsNotify($row)) {
        return false;
    }

    $minutesUntil = appointmentMinutesUntil($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    if ($minutesUntil === null || $minutesUntil <= 0 || $minutesUntil > visitSoonWindowMinutes()) {
        return false;
    }

    $code = (string) ($row['appointment_code'] ?? '');
    $soonLabel = formatVisitSoonLabel($minutesUntil);
    $visit = smsVisitShort($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsComposeStandaloneAppointmentMessage($row, [
        smsCitizenGreeting($row),
        'Appt in ' . $soonLabel . '.',
        'Code ' . $code . '.',
        $visit !== '' ? $visit . '.' : '',
    ]);

    return sendCitizenSms((string) $row['phone'], $message, 'visit_soon', $code);
}

/**
 * Follow-up SMS body (≤ SMS_BODY_MAX_LENGTH — IPROG header is separate).
 *
 * @param array<string, mixed> $row
 */
function smsFollowUpVisitReminderMessage(array $row): string
{
    $first = trim((string) ($row['first_name'] ?? ''));
    $greeting = $first !== '' ? 'Hi ' . $first . ',' : 'Hi,';

    $dateRaw = (string) ($row['follow_up_date'] ?? '');
    $dateDisplay = formatDateDisplay($dateRaw);
    $service = appointmentServiceLabel((string) ($row['service_type'] ?? ''));

    $compose = static function (string $greet, string $date, string $serviceLabel): string {
        $message = $greet . ' reminder: your next visit is on ' . $date;
        if ($serviceLabel !== '') {
            $message .= ' for ' . $serviceLabel;
        }

        return $message . '. Please return on that date during office hours.';
    };

    $message = $compose($greeting, $dateDisplay, $service);
    if (mb_strlen($message) <= SMS_BODY_MAX_LENGTH) {
        return $message;
    }

    $ts = strtotime($dateRaw);
    $shortDate = $ts !== false ? date('M j, Y', $ts) : $dateDisplay;
    $message = $compose($greeting, $shortDate, $service);
    if (mb_strlen($message) <= SMS_BODY_MAX_LENGTH) {
        return $message;
    }

    $withoutService = $compose($greeting, $shortDate, '');
    $budget = SMS_BODY_MAX_LENGTH - mb_strlen($withoutService);
    if ($service !== '' && $budget > 8) {
        $trimmed = mb_strlen($service) > $budget
            ? mb_substr($service, 0, max(1, $budget - 1)) . '…'
            : $service;
        $message = $compose($greeting, $shortDate, $trimmed);
    } else {
        $message = $withoutService;
    }

    if (mb_strlen($message) > SMS_BODY_MAX_LENGTH) {
        $message = mb_substr($message, 0, SMS_BODY_MAX_LENGTH - 1) . '…';
    }

    return $message;
}

/** @param array<string, mixed> $row Follow-up row with phone, service fields */
function notifyFollowUpReminderSms(array $row): bool
{
    if (!isSmsConfigured()) {
        return false;
    }
    if (empty($row['notify_sms'])) {
        return false;
    }
    $phone = trim((string) ($row['phone'] ?? ''));
    if ($phone === '' || !isValidPhilippineMobile($phone)) {
        return false;
    }

    $message = smsFollowUpVisitReminderMessage($row);
    $ref = (string) ($row['appointment_code'] ?? 'follow-up');

    return sendCitizenSms($phone, $message, 'follow_up_reminder', $ref);
}

/** @return array{attempted: bool, sent: bool} */
function notifyAppointmentStatusSms(PDO $pdo, int $appointmentId, string $newStatus): array
{
    if (!isSmsConfigured()) {
        return citizenSmsNotifySkipped();
    }

    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT appointment_code, first_name, middle_name, last_name, phone, service_type,
                appointment_date, appointment_time, notify_sms, source, tracking_code
         FROM appointments WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!citizenWantsSmsNotify($row)) {
        return citizenSmsNotifySkipped();
    }

    if ($newStatus !== 'confirmed') {
        return citizenSmsNotifySkipped();
    }

    $code = (string) ($row['appointment_code'] ?? '');
    $visit = smsVisitShort($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $message = smsComposeStandaloneAppointmentMessage($row, [
        smsCitizenGreeting($row),
        'Appt confirmed.',
        'Code ' . $code . '.',
        $visit !== '' ? $visit . '.' : '',
    ]);

    return citizenSmsNotifyResult(sendCitizenSms((string) $row['phone'], $message, 'appointment_confirmed', $code));
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
                sms_reminder_1h_sent_at
         FROM document_requests
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND status = 'ready'
           AND appointment_date IS NOT NULL
           AND appointment_time IS NOT NULL
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
           AND sms_reminder_1h_sent_at IS NULL"
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
        "SELECT id, appointment_code, first_name, middle_name, last_name, phone, service_type, status,
                appointment_date, appointment_time, notify_sms, source, tracking_code,
                sms_reminder_1h_sent_at
         FROM appointments a
         WHERE notify_sms = 1
           AND phone IS NOT NULL AND phone != ''
           AND " . appointmentStandaloneSql('a') . "
           AND a.status = 'confirmed'
           AND TIMESTAMP(a.appointment_date, a.appointment_time) > NOW()
           AND TIMESTAMP(a.appointment_date, a.appointment_time) <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
           AND a.sms_reminder_1h_sent_at IS NULL"
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
    $hint = isSmsConfigured() ? smsSenderConfigurationHint() : null;

    return [
        'enabled'      => isSmsEnabled(),
        'configured'   => isSmsConfigured(),
        'sender'       => iprogSenderName(),
        'has_api_key'  => iprogApiToken() !== '',
        'sender_hint'  => $hint,
    ];
}
