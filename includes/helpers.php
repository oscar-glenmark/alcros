<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/security.php';
bootstrapSecurity();

function generateCode(string $prefix, int $length = 6): string
{
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
}

function generateTrackingCode(): string
{
    return generateCode('ALR', 8);
}

function generateAppointmentCode(): string
{
    return generateCode('APT', 6);
}

function generateTicketNumber(PDO $pdo, string $purpose = 'walk_in'): string
{
    $prefix = match ($purpose) {
        'walk_in'        => 'W',
        'appointment'    => 'A',
        'document_claim' => 'C',
        default          => 'Q',
    };
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM queue_tickets WHERE DATE(created_at) = CURDATE() AND purpose = ?'
    );
    $stmt->execute([$purpose]);
    $count = (int) $stmt->fetchColumn() + 1;
    return $prefix . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
}

function queuePurposeConfig(): array
{
    return [
        'walk_in' => [
            'label'       => 'Walk-in',
            'table'       => 1,
            'description' => 'General inquiries & new requests',
            'icon'        => 'users',
            'color'       => 'orange',
            'border'      => 'border-orange-400',
            'bg'          => 'bg-orange-50',
            'text'        => 'text-orange-600',
            'btn'         => 'bg-orange-600 hover:bg-orange-700',
        ],
        'appointment' => [
            'label'       => 'Appointment',
            'table'       => 2,
            'description' => 'Scheduled visits & consultations',
            'icon'        => 'calendar-days',
            'color'       => 'blue',
            'border'      => 'border-blue-400',
            'bg'          => 'bg-blue-50',
            'text'        => 'text-blue-600',
            'btn'         => 'bg-blue-600 hover:bg-blue-700',
        ],
        'document_claim' => [
            'label'       => 'Claim',
            'table'       => 3,
            'description' => 'Document pickup & release',
            'icon'        => 'package',
            'color'       => 'emerald',
            'border'      => 'border-emerald-400',
            'bg'          => 'bg-emerald-50',
            'text'        => 'text-emerald-600',
            'btn'         => 'bg-emerald-600 hover:bg-emerald-700',
        ],
    ];
}

function queueTableForPurpose(string $purpose): int
{
    return queuePurposeConfig()[$purpose]['table'] ?? 1;
}

function logActivity(?string $staffId, string $action, string $details = ''): void
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('INSERT INTO activity_logs (staff_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([$staffId, $action, $details]);
    } catch (PDOException $e) {
        // Non-fatal if logging fails
    }
}

function ensureExtendedSchema(PDO $pdo): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notif_key VARCHAR(80) NOT NULL UNIQUE,
            type ENUM('pending_request','ready_pickup','queue','appointment','system') NOT NULL DEFAULT 'system',
            title VARCHAR(150) NOT NULL,
            message VARCHAR(255) NOT NULL,
            detail VARCHAR(100) DEFAULT NULL,
            href VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_type_created (type, created_at)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(150) NOT NULL,
            subject VARCHAR(255) NOT NULL,
            email_type VARCHAR(50) NOT NULL DEFAULT 'general',
            reference_code VARCHAR(30) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message VARCHAR(255) DEFAULT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient),
            INDEX idx_reference (reference_code),
            INDEX idx_sent (sent_at)
        ) ENGINE=InnoDB"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS sms_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            recipient VARCHAR(20) NOT NULL,
            message VARCHAR(500) NOT NULL,
            sms_type VARCHAR(50) NOT NULL DEFAULT 'general',
            reference_code VARCHAR(30) DEFAULT NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            error_message VARCHAR(255) DEFAULT NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_recipient (recipient),
            INDEX idx_reference (reference_code),
            INDEX idx_sent (sent_at)
        ) ENGINE=InnoDB"
    );

    try {
        $pdo->query('SELECT 1 FROM request_status_history LIMIT 1');
    } catch (PDOException) {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS request_status_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                tracking_code VARCHAR(20) NOT NULL,
                old_status VARCHAR(20) DEFAULT NULL,
                new_status VARCHAR(20) NOT NULL,
                changed_by VARCHAR(50) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_request (request_id),
                INDEX idx_tracking (tracking_code),
                INDEX idx_created (created_at),
                CONSTRAINT fk_status_history_request
                    FOREIGN KEY (request_id) REFERENCES document_requests(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );
    }

    try {
        $pdo->exec("UPDATE staff SET role = 'Administrator' WHERE role = 'Registrar'");
    } catch (PDOException $e) {
        // Non-fatal if migration fails
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS staff_password_otps (
            id INT AUTO_INCREMENT PRIMARY KEY,
            staff_id VARCHAR(50) NOT NULL,
            otp_hash VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_staff_otp (staff_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB"
    );
}

function logEmailDelivery(
    string $recipient,
    string $subject,
    string $emailType = 'general',
    ?string $referenceCode = null,
    bool $success = true,
    ?string $errorMessage = null
): void {
    try {
        ensureExtendedSchema(getDB());
        $stmt = getDB()->prepare(
            'INSERT INTO email_logs (recipient, subject, email_type, reference_code, success, error_message)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $recipient,
            $subject,
            $emailType,
            $referenceCode,
            $success ? 1 : 0,
            $errorMessage,
        ]);
    } catch (PDOException $e) {
        // Non-fatal if logging fails
    }
}

function logRequestStatusChange(
    PDO $pdo,
    int $requestId,
    string $trackingCode,
    ?string $oldStatus,
    string $newStatus,
    ?string $changedBy = null,
    ?string $notes = null
): void {
    try {
        ensureExtendedSchema($pdo);
        $stmt = $pdo->prepare(
            'INSERT INTO request_status_history (request_id, tracking_code, old_status, new_status, changed_by, notes)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$requestId, $trackingCode, $oldStatus, $newStatus, $changedBy, $notes]);
    } catch (PDOException $e) {
        // Non-fatal if logging fails
    }
}

function upsertStaffNotification(array $item): void
{
    try {
        ensureExtendedSchema(getDB());
        $stmt = getDB()->prepare(
            'INSERT INTO staff_notifications (notif_key, type, title, message, detail, href, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                type = VALUES(type),
                title = VALUES(title),
                message = VALUES(message),
                detail = VALUES(detail),
                href = VALUES(href),
                created_at = VALUES(created_at)'
        );
        $stmt->execute([
            $item['id'] ?? $item['notif_key'] ?? '',
            $item['type'] ?? 'system',
            $item['title'] ?? '',
            $item['message'] ?? '',
            $item['detail'] ?? null,
            $item['href'] ?? null,
            $item['created_at'] ?? date('Y-m-d H:i:s'),
        ]);
    } catch (PDOException $e) {
        // Non-fatal if sync fails
    }
}

function getSetting(string $key, string $default = ''): string
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function getSiteSettings(): array
{
    return [
        'name'               => getSetting('site_name', 'ALCROS'),
        'office'             => getSetting('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
        'address'            => getSetting('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
        'phone'              => getSetting('office_phone', '+639473212350'),
        'email'              => getSetting('office_email', 'aloran@gov.ph'),
        'head'               => getSetting('office_head', 'ATTY. LOCAL CIVIL REGISTRAR'),
        'hours'              => getSetting('office_hours', '8:00 AM - 5:00 PM (Monday to Friday)'),
        'overview'           => getSetting('overview_text', 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.'),
        'portal_title'       => getSetting('portal_title', 'ALCROS Online Request Portal'),
        'portal_description' => getSetting('portal_description', 'Request document submissions or track application statuses online.'),
    ];
}

function renderOverviewText(string $template, string $officeName): string
{
    $text = str_replace('{office}', htmlspecialchars($officeName), $template);
    if (strpos($text, '<') === false) {
        return nl2br(htmlspecialchars($text));
    }

    return sanitizeOverviewHtml($text);
}

function documentTypeLabel(string $type): string
{
    $labels = [
        'birth'    => 'Birth Certificate',
        'death'    => 'Death Certificate',
        'marriage' => 'Marriage Certificate',
        'cenomar'  => 'CENOMAR',
    ];
    return $labels[$type] ?? ucfirst($type);
}

function requestStatusWorkflow(): array
{
    return ['pending', 'verified', 'ready', 'completed'];
}

function requestStatusUpdateOptions(): array
{
    return ['verified', 'rejected'];
}

function normalizeRequestStatus(string $status): string
{
    return $status === 'processing' ? 'verified' : $status;
}

function requestStatusProgressIndex(string $status): int|false
{
    if ($status === 'rejected') {
        return false;
    }
    $idx = array_search(normalizeRequestStatus($status), requestStatusWorkflow(), true);

    return $idx === false ? false : (int) $idx;
}

function migrateLegacyProcessingStatus(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;

    try {
        $pdo->exec("UPDATE document_requests SET status = 'verified' WHERE status = 'processing'");
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }
}

function ensureCitizenNotifyColumns(PDO $pdo): void
{
    ensurePersonNamePartColumns($pdo);
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT notify_email FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER privacy_agreed');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT reminder_sent_at FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    ensureReminderLeadColumns($pdo, 'document_requests');

    try {
        $pdo->query('SELECT notify_sms FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN notify_sms TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT sms_reminder_3h_sent_at FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN sms_reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL AFTER reminder_1h_sent_at');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT notify_email FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER phone');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT reminder_sent_at FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    ensureReminderLeadColumns($pdo, 'appointments');

    try {
        $pdo->query('SELECT notify_sms FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN notify_sms TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT sms_reminder_3h_sent_at FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN sms_reminder_3h_sent_at TIMESTAMP NULL DEFAULT NULL AFTER reminder_1h_sent_at');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT source FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'standalone' AFTER status");
            $pdo->exec('ALTER TABLE appointments ADD COLUMN tracking_code VARCHAR(20) DEFAULT NULL AFTER source');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT id_front_path FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN id_front_path VARCHAR(255) DEFAULT NULL AFTER tracking_code');
            $pdo->exec('ALTER TABLE appointments ADD COLUMN id_back_path VARCHAR(255) DEFAULT NULL AFTER id_front_path');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->exec(
            "UPDATE appointments a
             INNER JOIN document_requests r
                ON r.appointment_date = a.appointment_date
               AND r.appointment_time = a.appointment_time
               AND (
                    (r.email IS NOT NULL AND r.email != '' AND r.email = a.email)
                    OR (
                        r.first_name = a.first_name
                        AND IFNULL(r.middle_name, '') = IFNULL(a.middle_name, '')
                        AND r.last_name = a.last_name
                    )
               )
             SET a.source = 'document_request', a.tracking_code = r.tracking_code
             WHERE a.source = 'standalone'
               AND (a.tracking_code IS NULL OR a.tracking_code = '')"
        );
    } catch (Throwable $ignored) {
    }
}

function deleteIdUploadFiles(?string ...$paths): void
{
    foreach ($paths as $rel) {
        $rel = trim((string) $rel);
        if ($rel === '') {
            continue;
        }
        $path = __DIR__ . '/../' . ltrim($rel, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function deleteCompletedDocumentRequest(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare(
        'SELECT tracking_code, status, id_front_path, id_back_path FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || normalizeRequestStatus((string) $row['status']) !== 'completed') {
        return false;
    }

    deleteIdUploadFiles($row['id_front_path'] ?? null, $row['id_back_path'] ?? null);

    $pdo->prepare('DELETE FROM document_requests WHERE id = ?')->execute([$id]);
    logActivity(staffId(), 'Request Deleted', 'Deleted completed request ' . $row['tracking_code']);

    return true;
}

function requestStatusBadge(string $status): string
{
    $status = normalizeRequestStatus($status);
    $classes = [
        'pending'    => 'bg-yellow-100 text-yellow-700',
        'verified'   => 'bg-blue-100 text-blue-700',
        'ready'      => 'bg-green-100 text-green-700',
        'completed'  => 'bg-gray-100 text-gray-600',
        'rejected'   => 'bg-red-100 text-red-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';
    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function formatTimeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    return date('g:i A', $ts);
}

function formatDateDisplay(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('m/d/Y', $ts) : $date;
}

function getDocumentTypes(): array
{
    return [
        ['slug' => 'birth',    'label' => 'Birth Certificate',    'desc' => 'Request your official birth certificate document online.',    'icon' => 'users',     'iconBg' => 'bg-blue-100 text-blue-600'],
        ['slug' => 'death',    'label' => 'Death Certificate',    'desc' => 'Request your official death certificate document online.',    'icon' => 'activity',  'iconBg' => 'bg-teal-100 text-teal-600'],
        ['slug' => 'marriage', 'label' => 'Marriage Certificate', 'desc' => 'Request your official marriage certificate document online.', 'icon' => 'heart',     'iconBg' => 'bg-pink-100 text-pink-500'],
    ];
}

function getAppointmentServices(): array
{
    return [
        ['slug' => 'supplemental-report', 'label' => 'Supplemental Report', 'desc' => 'Add missing birth/death details to existing records.',       'icon' => 'file-signature', 'iconBg' => 'bg-orange-100 text-orange-500'],
        ['slug' => 'report-correction',   'label' => 'Report Correction',   'desc' => 'Correct clerical errors in names, dates, or typos.',          'icon' => 'search',         'iconBg' => 'bg-purple-100 text-purple-500'],
        ['slug' => 'legitimation',        'label' => 'Legitimation',        'desc' => 'Update child status to legitimate after parents marry.',     'icon' => 'users',          'iconBg' => 'bg-blue-100 text-blue-500'],
        ['slug' => 'acknowledgement',     'label' => 'Acknowledgement',     'desc' => 'Official parent acknowledgement of a child.',                'icon' => 'shield-check',   'iconBg' => 'bg-teal-100 text-teal-500'],
        ['slug' => 'certified-true-copy', 'label' => 'Certified True Copy', 'desc' => 'Request an official certified copy of any record.',          'icon' => 'copy',           'iconBg' => 'bg-gray-100 text-gray-600'],
        ['slug' => 'cenomar',             'label' => 'CENOMAR',             'desc' => 'Certificate of No Marriage — schedule an appointment.',        'icon' => 'file-text',      'iconBg' => 'bg-green-100 text-green-600'],
    ];
}

function appointmentServiceLabel(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    foreach (getAppointmentServices() as $svc) {
        if ($svc['slug'] === $value || strcasecmp($svc['label'], $value) === 0) {
            return $svc['label'];
        }
    }

    return ucwords(str_replace('-', ' ', $value));
}

function isDocumentRequestAppointment(array $row): bool
{
    return (($row['source'] ?? '') === 'document_request') || !empty($row['tracking_code']);
}

function appointmentDisplayStatusLabel(array $row): string
{
    if (isDocumentRequestAppointment($row) && ($row['status'] ?? '') === 'confirmed') {
        return 'Ready for Pickup';
    }

    return appointmentStatusLabel((string) ($row['status'] ?? 'scheduled'));
}

function appointmentStatusWorkflow(): array
{
    return ['scheduled', 'confirmed', 'completed'];
}

function appointmentStatusUpdateOptions(): array
{
    return ['confirmed', 'completed', 'no_show'];
}

function appointmentStatusLabel(string $status): string
{
    return match ($status) {
        'scheduled' => 'Awaiting confirmation',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Rejected',
        'no_show'   => 'No Show',
        default     => ucfirst(str_replace('_', ' ', $status)),
    };
}

function appointmentStatusMessage(string $status): string
{
    return match ($status) {
        'scheduled' => 'Your appointment request was received. Our office will confirm your visit — this is not yet a confirmed schedule.',
        'confirmed' => 'Your appointment has been confirmed by our office. Please arrive on time with a valid ID.',
        'completed' => 'This appointment has been completed. Thank you for visiting ALCROS.',
        'cancelled' => 'This appointment was rejected. Contact the office to reschedule.',
        'no_show'   => 'You were marked as a no-show. Please contact the office to reschedule.',
        default     => 'Track your appointment status below.',
    };
}

function activeAppointmentSlotStatuses(): array
{
    return ['scheduled', 'confirmed'];
}

function normalizeAppointmentTime(string $time): string
{
    $time = trim($time);
    if ($time === '') {
        return '';
    }
    $ts = strtotime('1970-01-01 ' . $time);
    if ($ts === false) {
        return '';
    }

    return date('H:i:s', $ts);
}

function maxDailyAppointmentsLimit(): int
{
    return max(1, (int) getSetting('max_daily_appointments', '20'));
}

function isOfficeAppointmentDate(string $date): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return false;
    }

    $dow = (int) date('N', strtotime($date));

    return $dow >= 1 && $dow <= 5;
}

function isWithinOfficeHours(string $time): bool
{
    $normalized = normalizeAppointmentTime($time);
    if ($normalized === '') {
        return false;
    }

    return $normalized >= '08:00:00' && $normalized <= '17:00:00';
}

function getBookedAppointmentTimes(PDO $pdo, string $date): array
{
    ensureCitizenNotifyColumns($pdo);
    $statuses = activeAppointmentSlotStatuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT TIME_FORMAT(appointment_time, '%H:%i') AS slot_time
         FROM appointments
         WHERE appointment_date = ? AND status IN ($placeholders)
         ORDER BY appointment_time ASC"
    );
    $stmt->execute(array_merge([$date], $statuses));

    return array_column($stmt->fetchAll(), 'slot_time');
}

function countActiveAppointmentsOnDate(PDO $pdo, string $date): int
{
    $statuses = activeAppointmentSlotStatuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ($placeholders)"
    );
    $stmt->execute(array_merge([$date], $statuses));

    return (int) $stmt->fetchColumn();
}

function isAppointmentSlotTaken(PDO $pdo, string $date, string $time, ?int $excludeId = null): bool
{
    $normalized = normalizeAppointmentTime($time);
    if ($normalized === '') {
        return true;
    }

    $statuses = activeAppointmentSlotStatuses();
    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $sql = "SELECT COUNT(*) FROM appointments
            WHERE appointment_date = ? AND appointment_time = ? AND status IN ($placeholders)";
    $params = array_merge([$date, $normalized], $statuses);
    if ($excludeId !== null) {
        $sql .= ' AND id != ?';
        $params[] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function validateAppointmentBooking(PDO $pdo, string $date, string $time, ?string $email = null, bool $lockRows = false): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return 'Please choose a valid appointment date.';
    }
    if ($date < date('Y-m-d')) {
        return 'Appointment date cannot be in the past.';
    }
    if (!isOfficeAppointmentDate($date)) {
        return 'Appointments are available Monday to Friday only.';
    }
    if (!isWithinOfficeHours($time)) {
        return 'Please choose a time within office hours (8:00 AM – 5:00 PM).';
    }

    $normalized = normalizeAppointmentTime($time);
    if ($normalized === '') {
        return 'Please choose a valid appointment time.';
    }

    $statuses = activeAppointmentSlotStatuses();
    $statusList = "'" . implode("','", $statuses) . "'";
    $lock = $lockRows ? ' FOR UPDATE' : '';

    $slotStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE appointment_date = ? AND appointment_time = ? AND status IN ($statusList)$lock"
    );
    $slotStmt->execute([$date, $normalized]);
    if ((int) $slotStmt->fetchColumn() > 0) {
        return 'This time slot is already booked. Please choose another date or time.';
    }

    $maxDaily = maxDailyAppointmentsLimit();
    $dailyStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM appointments
         WHERE appointment_date = ? AND status IN ($statusList)$lock"
    );
    $dailyStmt->execute([$date]);
    if ((int) $dailyStmt->fetchColumn() >= $maxDaily) {
        return 'No appointment slots remain on this date. Please choose another day.';
    }

    if ($email !== null && $email !== '') {
        $emailStmt = $pdo->prepare(
            "SELECT appointment_code FROM appointments
             WHERE appointment_date = ? AND appointment_time = ? AND email = ?
               AND status IN ($statusList) LIMIT 1"
        );
        $emailStmt->execute([$date, $normalized, normalizeGmail($email)]);
        if ($emailStmt->fetchColumn()) {
            return 'You already have an appointment at this date and time.';
        }
    }

    return null;
}

function appointmentStatusBadge(string $status): string
{
    $classes = [
        'scheduled' => 'bg-blue-100 text-blue-700',
        'confirmed' => 'bg-purple-100 text-purple-700',
        'completed' => 'bg-gray-100 text-gray-600',
        'cancelled' => 'bg-red-100 text-red-700',
        'no_show'   => 'bg-amber-100 text-amber-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';

    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">'
        . htmlspecialchars(appointmentStatusLabel($status)) . '</span>';
}

function appointmentStatusProgressIndex(string $status): int|false
{
    if (in_array($status, ['cancelled', 'no_show'], true)) {
        return false;
    }
    $idx = array_search($status, appointmentStatusWorkflow(), true);

    return $idx === false ? false : (int) $idx;
}

function getPurposeOptions(): array
{
    return [
        'passport'      => 'Passport Application',
        'employment'    => 'Employment Requirement',
        'school'        => 'School Enrollment',
        'legal'         => 'Legal Proceedings',
        'insurance'     => 'Insurance Claim',
        'travel'        => 'Travel Abroad',
        'personal'      => 'Personal Record',
        'other'         => 'Other',
    ];
}

function isValidPhilippineMobile(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', $phone);
}

function isValidGmail(string $email): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email);
}

function normalizeGmail(string $email): string
{
    return strtolower(trim($email));
}

function markGmailVerified(string $email): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['alcros_email_verified'] = [
        'email'   => normalizeGmail($email),
        'expires' => time() + 7200,
    ];
}

function getFirebaseWebApiKey(): string
{
    $configFile = __DIR__ . '/../config/google.php';
    if (is_file($configFile) && !defined('FIREBASE_WEB_API_KEY')) {
        require_once $configFile;
    }
    return defined('FIREBASE_WEB_API_KEY') ? trim((string) FIREBASE_WEB_API_KEY) : '';
}

/** Same check Google uses on the signup form — username taken means Gmail is active. */
function gmailExistsViaSignupValidator(string $email): ?bool
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $username = explode('@', normalizeGmail($email))[0];
    if ($username === '') {
        return false;
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $signupUrl = 'https://accounts.google.com/signup/v2/createaccount?flowName=GlifWebSignIn&flowEntry=SignUp&hl=en';

    $ch = curl_init($signupUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => $userAgent,
    ]);
    $page = curl_exec($ch);
    curl_close($ch);

    if ($page === false) {
        return null;
    }

    preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $page, $cookieMatches);
    $cookieHeader = implode('; ', $cookieMatches[1] ?? []);
    if ($cookieHeader === '') {
        return null;
    }

    $payload = json_encode([
        'input01' => [
            'Input'        => 'GmailAddress',
            'GmailAddress' => $username,
            'FirstName'    => '',
            'LastName'     => '',
        ],
        'Locale' => 'en',
    ]);

    $ch = curl_init('https://accounts.google.com/InputValidator?resource=SignUp');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Origin: https://accounts.google.com',
            'Referer: ' . $signupUrl,
            'User-Agent: ' . $userAgent,
            'Cookie: ' . $cookieHeader,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['input01']['Valid'])) {
        return null;
    }

    $valid = $data['input01']['Valid'];
    // Signup API: Valid=false → username already taken → active Gmail.
    if ($valid === false || $valid === 'false') {
        return true;
    }
    if ($valid === true || $valid === 'true') {
        return false;
    }

    return null;
}

/** Gmail MX servers used for SMTP verification. */
function gmailMxHosts(): array
{
    $hosts = [];
    if (getmxrr('gmail.com', $hosts) && $hosts !== []) {
        return $hosts;
    }
    return ['gmail-smtp-in.l.google.com'];
}

/**
 * Check mailbox via SMTP RCPT (no email is sent to the user).
 * Returns true = exists, false = not found, null = could not check.
 */
function smtpRcptCheck(string $host, string $email): ?bool
{
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('tcp://' . $host . ':25', $errno, $errstr, 10);
    if (!$fp) {
        return null;
    }

    stream_set_timeout($fp, 10);

    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    $banner = $read();
    if ($banner === '' || (int) substr($banner, 0, 3) !== 220) {
        fclose($fp);
        return null;
    }

    $write('EHLO alcros.local');
    if ((int) substr($read(), 0, 3) !== 250) {
        fclose($fp);
        return null;
    }

    $write('MAIL FROM:<>');
    if ((int) substr($read(), 0, 3) !== 250) {
        fclose($fp);
        return null;
    }

    $write('RCPT TO:<' . $email . '>');
    $rcpt = $read();
    $write('QUIT');
    fclose($fp);

    $code = (int) substr($rcpt, 0, 3);
    if (in_array($code, [250, 251], true)) {
        return true;
    }
    if (in_array($code, [550, 551, 552, 553, 554], true)) {
        return false;
    }

    return null;
}

function gmailExistsViaSmtp(string $email): ?bool
{
    foreach (gmailMxHosts() as $host) {
        $result = smtpRcptCheck($host, $email);
        if ($result !== null) {
            return $result;
        }
    }
    return null;
}

/** HTTPS check — works on localhost where port 25 is often blocked. */
function gmailExistsViaHttp(string $email): ?bool
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $url = 'https://mail.google.com/mail/gxlu?email=' . urlencode($email);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ALCROS/1.0)',
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false || $errno !== 0) {
        return null;
    }

    if (preg_match('/^set-cookie:/im', $response)) {
        return true;
    }

    // Google no longer sends Set-Cookie on this endpoint — result is inconclusive, not "not found".
    if (preg_match('/^HTTP\/[\d.]+ 204/m', $response)) {
        return null;
    }

    if (preg_match('/^HTTP\/[\d.]+ (\d+)/m', $response, $match)) {
        $status = (int) $match[1];
        if ($status >= 200 && $status < 400) {
            return null;
        }
    }

    return null;
}

/** Firebase Identity Toolkit — reliable HTTPS check (works on XAMPP). */
function gmailExistsViaFirebase(string $email): ?bool
{
    $apiKey = getFirebaseWebApiKey();
    if ($apiKey === '') {
        return null;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $continueUri = $scheme . '://' . $host . '/';

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:createAuthUri?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'identifier'  => $email,
            'continueUri' => $continueUri,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }
    if (array_key_exists('registered', $data)) {
        return (bool) $data['registered'];
    }
    if (isset($data['error'])) {
        return null;
    }

    return null;
}

/** Returns true if active, false if not found, null if check failed. */
function gmailAccountIsActive(string $email): ?bool
{
    foreach ([
        'gmailExistsViaSignupValidator',
        'gmailExistsViaFirebase',
        'gmailExistsViaSmtp',
        'gmailExistsViaHttp',
    ] as $checker) {
        $result = $checker($email);
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

/** Verify typed Gmail for the request form — no Google Sign-In required. */
function verifyActiveGmailAccount(string $email): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $email = normalizeGmail($email);
    if (!isValidGmail($email)) {
        return ['ok' => false, 'error' => 'Please enter a valid @gmail.com address.'];
    }

    $active = gmailAccountIsActive($email);

    if ($active === true) {
        markGmailVerified($email);
        return [
            'ok'      => true,
            'email'   => $email,
            'message' => 'Gmail verified — this is an active Google account.',
        ];
    }

    if ($active === false) {
        return [
            'ok'    => false,
            'error' => 'This Gmail is not an active Google account. Check the spelling or try another Gmail.',
        ];
    }

    return [
        'ok'    => false,
        'error' => 'Could not verify right now. Check your internet connection and try again in a moment.',
    ];
}

function isGmailVerifiedInSession(string $email): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $email = normalizeGmail($email);
    $verified = $_SESSION['alcros_email_verified'] ?? null;
    if (!$verified || ($verified['email'] ?? '') !== $email) {
        return false;
    }
    if (time() > (int) ($verified['expires'] ?? 0)) {
        unset($_SESSION['alcros_email_verified']);
        return false;
    }
    return true;
}

function formatPersonName(?string $first, ?string $middle = null, ?string $last = null): string
{
    $parts = array_filter([
        trim((string) $first),
        trim((string) ($middle ?? '')),
        trim((string) ($last ?? '')),
    ], static fn ($part) => $part !== '');

    return implode(' ', $parts);
}

function personNamePartsFromInput(array $input, string $prefix = ''): array
{
    return [
        'first_name'  => trim((string) ($input[$prefix . 'first_name'] ?? '')),
        'middle_name' => trim((string) ($input[$prefix . 'middle_name'] ?? '')) ?: null,
        'last_name'   => trim((string) ($input[$prefix . 'last_name'] ?? '')),
    ];
}

function validatePersonNameParts(array $parts): ?string
{
    if ($parts['first_name'] === '' || $parts['last_name'] === '') {
        return 'Please enter your first name and last name.';
    }

    return null;
}

function personNameFromRow(array $row): string
{
    if (isset($row['first_name']) || isset($row['middle_name']) || isset($row['last_name'])) {
        return formatPersonName(
            (string) ($row['first_name'] ?? ''),
            $row['middle_name'] ?? null,
            (string) ($row['last_name'] ?? '')
        );
    }

    return trim((string) ($row['citizen_name'] ?? $row['person_name'] ?? ''));
}

function civilRecordDisplayName(array $row): string
{
    if (($row['record_type'] ?? '') === 'marriage') {
        $husband = trim((string) ($row['husband_name'] ?? ''));
        $wife = trim((string) ($row['wife_name'] ?? ''));
        if ($husband !== '' && $wife !== '') {
            return $husband . ' & ' . $wife;
        }
    }

    $name = personNameFromRow($row);

    return $name !== '' ? $name : '—';
}

function parsePersonNameToParts(string $full): array
{
    $full = trim($full);
    if ($full === '') {
        return ['first_name' => '', 'middle_name' => null, 'last_name' => ''];
    }

    if (str_contains($full, ',')) {
        [$last, $rest] = array_map('trim', explode(',', $full, 2));
        $restParts = preg_split('/\s+/', trim($rest)) ?: [];

        return [
            'first_name'  => $restParts[0] ?? '',
            'middle_name' => count($restParts) > 1 ? implode(' ', array_slice($restParts, 1)) : null,
            'last_name'   => $last,
        ];
    }

    $parts = preg_split('/\s+/', $full) ?: [];
    if (count($parts) === 1) {
        return ['first_name' => $parts[0], 'middle_name' => null, 'last_name' => ''];
    }
    if (count($parts) === 2) {
        return ['first_name' => $parts[0], 'middle_name' => null, 'last_name' => $parts[1]];
    }

    return [
        'first_name'  => $parts[0],
        'middle_name' => implode(' ', array_slice($parts, 1, -1)),
        'last_name'   => $parts[count($parts) - 1],
    ];
}

function normalizePersonNameParts(?string $first, ?string $middle, ?string $last): string
{
    return normalizePersonName(formatPersonName($first, $middle, $last));
}

function citizenNameFromPost(array $post): string
{
    if (trim((string) ($post['citizen_name'] ?? '')) !== '') {
        return trim((string) $post['citizen_name']);
    }

    $parts = personNamePartsFromInput($post);

    return formatPersonName($parts['first_name'], $parts['middle_name'], $parts['last_name']);
}

function ensurePersonNamePartColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $requiredTables = [
        'document_requests' => true,
        'appointments'      => true,
        'queue_tickets'     => false,
        'civil_records'     => false,
        'staff'             => true,
    ];

    foreach ($requiredTables as $table => $namesRequired) {
        migrateTablePersonNameParts($pdo, $table, $namesRequired);
    }
}

function legacyNameColumnForTable(string $table): string
{
    return match ($table) {
        'civil_records' => 'person_name',
        'staff'         => 'name',
        default         => 'citizen_name',
    };
}

function migrateTablePersonNameParts(PDO $pdo, string $table, bool $namesRequired): void
{
    $legacyColumn = legacyNameColumnForTable($table);
    $hasParts = true;

    try {
        $pdo->query("SELECT first_name FROM `$table` LIMIT 1");
    } catch (Throwable $e) {
        $hasParts = false;
    }

    if (!$hasParts) {
        try {
            if ($table === 'civil_records') {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN first_name VARCHAR(80) NULL AFTER registry_number");
            } elseif ($table === 'staff') {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN first_name VARCHAR(80) NULL AFTER staff_id");
            } elseif ($table === 'document_requests') {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN first_name VARCHAR(80) NULL AFTER tracking_code");
            } elseif ($table === 'appointments') {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN first_name VARCHAR(80) NULL AFTER appointment_code");
            } else {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN first_name VARCHAR(80) NULL AFTER ticket_number");
            }
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN middle_name VARCHAR(80) NULL AFTER first_name");
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN last_name VARCHAR(80) NULL AFTER middle_name");
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query("SELECT `$legacyColumn` FROM `$table` LIMIT 1");
        $stmt = $pdo->query("SELECT * FROM `$table` WHERE (`$legacyColumn` IS NOT NULL AND `$legacyColumn` != '') AND (first_name IS NULL OR first_name = '')");
        while ($row = $stmt->fetch()) {
            $parts = parsePersonNameToParts((string) $row[$legacyColumn]);
            $update = $pdo->prepare("UPDATE `$table` SET first_name = ?, middle_name = ?, last_name = ? WHERE id = ?");
            $update->execute([
                $parts['first_name'] !== '' ? $parts['first_name'] : 'Unknown',
                $parts['middle_name'],
                $parts['last_name'],
                $row['id'],
            ]);
        }

        if ($namesRequired) {
            $pdo->exec("UPDATE `$table` SET first_name = 'Unknown' WHERE first_name IS NULL OR first_name = ''");
            $pdo->exec("UPDATE `$table` SET last_name = '' WHERE last_name IS NULL");
            $pdo->exec("ALTER TABLE `$table` MODIFY first_name VARCHAR(80) NOT NULL");
            $pdo->exec("ALTER TABLE `$table` MODIFY last_name VARCHAR(80) NOT NULL");
        }

        try {
            $pdo->exec("ALTER TABLE `$table` DROP INDEX idx_citizen");
        } catch (Throwable $ignored) {
        }
        try {
            $pdo->exec("ALTER TABLE `$table` DROP INDEX idx_person");
        } catch (Throwable $ignored) {
        }

        $pdo->exec("ALTER TABLE `$table` DROP COLUMN `$legacyColumn`");

        if ($table !== 'civil_records') {
            try {
                $pdo->exec("CREATE INDEX idx_citizen_name ON `$table` (last_name, first_name)");
            } catch (Throwable $ignored) {
            }
        } else {
            try {
                $pdo->exec('CREATE INDEX idx_person_name ON civil_records (last_name, first_name)');
            } catch (Throwable $ignored) {
            }
        }
    } catch (Throwable $ignored) {
    }
}

function enrichCitizenNameRow(array $row): array
{
    $row['citizen_name'] = personNameFromRow($row);

    return $row;
}

function enrichCitizenNameRows(array $rows): array
{
    return array_map('enrichCitizenNameRow', $rows);
}

function normalizePersonName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[.,]+/', ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);

    return $name;
}

function findCivilRecordMatch(PDO $pdo, string $citizenName, string $dateOfBirth): ?array
{
    $normalized = normalizePersonName($citizenName);
    if ($normalized === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, record_type, first_name, middle_name, last_name, birth_date, registry_number
         FROM civil_records
         WHERE deleted_at IS NULL AND birth_date = ? AND record_type IN (\'birth\', \'death\')
         ORDER BY last_name ASC, first_name ASC'
    );
    $stmt->execute([$dateOfBirth]);
    while ($row = $stmt->fetch()) {
        if (normalizePersonNameParts(
            (string) ($row['first_name'] ?? ''),
            $row['middle_name'] ?? null,
            (string) ($row['last_name'] ?? '')
        ) === $normalized) {
            return $row;
        }
    }

    return null;
}

function markCivilRecordVerified(string $citizenName, string $dateOfBirth): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['alcros_civil_record_verified'] = [
        'name'    => normalizePersonName($citizenName),
        'dob'     => $dateOfBirth,
        'expires' => time() + 7200,
    ];
}

function isCivilRecordVerifiedInSession(string $citizenName, string $dateOfBirth): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if ($citizenName === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
        return false;
    }

    $verified = $_SESSION['alcros_civil_record_verified'] ?? null;
    if (!$verified) {
        return false;
    }
    if (normalizePersonName($citizenName) !== ($verified['name'] ?? '')
        || $dateOfBirth !== ($verified['dob'] ?? '')) {
        return false;
    }
    if (time() > (int) ($verified['expires'] ?? 0)) {
        unset($_SESSION['alcros_civil_record_verified']);
        return false;
    }

    return true;
}

function verifyCitizenCivilRecord(PDO $pdo, string $citizenName, string $dateOfBirth): array
{
    $citizenName = trim($citizenName);
    if ($citizenName === '') {
        return ['ok' => false, 'error' => 'Enter your first name, middle name (if any), and last name on record first.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
        return ['ok' => false, 'error' => 'Enter your date of birth first.'];
    }

    $row = findCivilRecordMatch($pdo, $citizenName, $dateOfBirth);
    if ($row) {
        markCivilRecordVerified($citizenName, $dateOfBirth);
        return [
            'ok'          => true,
            'message'     => 'Record found — you are registered with the Local Civil Registry Office.',
            'record_type' => civilRecordTypeLabel((string) $row['record_type']),
        ];
    }

    return [
        'ok'    => false,
        'error' => 'No civil registry record was found for this name and date of birth. Please visit the Local Civil Registry Office (LCRO) in person to register before submitting an online request.',
    ];
}

function saveIdUpload(array $file, string $prefix): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return null;
    }

    $ext = match ($mime) {
        'image/jpeg'        => 'jpg',
        'image/png'         => 'png',
        'image/webp'        => 'webp',
        'application/pdf'   => 'pdf',
        default             => 'bin',
    };

    $dir = __DIR__ . '/../uploads/ids';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return 'uploads/ids/' . $filename;
}

function ensureStaffProfileColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    ensurePersonNamePartColumns($pdo);

    try {
        $pdo->query('SELECT profile_photo_path FROM staff LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE staff ADD COLUMN profile_photo_path VARCHAR(255) DEFAULT NULL AFTER role');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT email FROM staff LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE staff ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER last_name');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT recovery_gmail_2sv_confirmed FROM staff LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE staff ADD COLUMN recovery_gmail_2sv_confirmed TINYINT(1) NOT NULL DEFAULT 0 AFTER email');
        } catch (Throwable $ignored) {
        }
    }
}

function staffRowById(PDO $pdo, string $staffId): ?array
{
    ensureStaffProfileColumns($pdo);
    $stmt = $pdo->prepare('SELECT staff_id, first_name, middle_name, last_name, email, recovery_gmail_2sv_confirmed, role, password_hash, profile_photo_path, created_at FROM staff WHERE staff_id = ? LIMIT 1');
    $stmt->execute([strtoupper(trim($staffId))]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function normalizeStaffEmail(string $email): string
{
    return normalizeGmail($email);
}

function validateStaffEmail(string $email): ?string
{
    $email = normalizeStaffEmail($email);
    if ($email === '') {
        return 'Gmail address is required for password recovery.';
    }
    if (!isValidGmail($email)) {
        return 'Please enter a valid Gmail address (example@gmail.com).';
    }

    return null;
}

function staffRecoveryGmailNeeds2svConfirmation(?array $existingStaff, string $newEmail): bool
{
    if (!$existingStaff) {
        return true;
    }

    $oldEmail = normalizeStaffEmail((string) ($existingStaff['email'] ?? ''));
    $newEmail = normalizeStaffEmail($newEmail);
    $confirmed = (int) ($existingStaff['recovery_gmail_2sv_confirmed'] ?? 0) === 1;

    if ($newEmail !== $oldEmail) {
        return true;
    }

    return !$confirmed;
}

function validateStaffRecoveryGmail2sv(bool $confirmed): ?string
{
    if (!$confirmed) {
        return 'You must confirm that this Gmail account already has Google 2-Step Verification enabled. Enable it at myaccount.google.com/signinoptions/two-step-verification, then try again.';
    }

    return null;
}

function staffRecoveryGmail2svConfirmedValue(?array $existingStaff, string $newEmail, bool $checkboxConfirmed): int
{
    if (staffRecoveryGmailNeeds2svConfirmation($existingStaff, $newEmail)) {
        return $checkboxConfirmed ? 1 : 0;
    }

    return (int) ($existingStaff['recovery_gmail_2sv_confirmed'] ?? 0);
}

function staffEmailInUse(PDO $pdo, string $email, ?string $exceptStaffId = null): bool
{
    ensureStaffProfileColumns($pdo);
    $email = normalizeStaffEmail($email);
    if ($email === '') {
        return false;
    }
    $sql = 'SELECT COUNT(*) FROM staff WHERE email = ?';
    $params = [$email];
    if ($exceptStaffId !== null && $exceptStaffId !== '') {
        $sql .= ' AND staff_id <> ?';
        $params[] = strtoupper(trim($exceptStaffId));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function maskEmailAddress(string $email): string
{
    $email = normalizeStaffEmail($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }
    [$local, $domain] = explode('@', $email, 2);
    $visible = substr($local, 0, 1);
    $maskedLocal = $visible . str_repeat('*', max(1, strlen($local) - 1));

    return $maskedLocal . '@' . $domain;
}

function purgeExpiredStaffOtps(PDO $pdo): void
{
    ensureExtendedSchema($pdo);
    $pdo->exec('DELETE FROM staff_password_otps WHERE expires_at < NOW()');
}

function sendStaffPasswordOtp(PDO $pdo, string $staffId): array
{
    ensureExtendedSchema($pdo);
    purgeExpiredStaffOtps($pdo);

    $staff = staffRowById($pdo, $staffId);
    if (!$staff) {
        return ['ok' => true, 'message' => 'If that Staff ID is registered with a Gmail address, a verification code was sent.'];
    }

    $email = normalizeStaffEmail((string) ($staff['email'] ?? ''));
    if ($email === '') {
        return [
            'ok' => false,
            'message' => 'No Gmail is registered for this account. Ask an administrator to add your Gmail under System Settings → Staff Members, or set it in My Settings if you can still sign in.',
        ];
    }

    if ((int) ($staff['recovery_gmail_2sv_confirmed'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'message' => 'Password recovery is not enabled for this account because the recovery Gmail has not been confirmed with Google 2-Step Verification. Sign in and update Recovery Gmail in System Settings, or ask an administrator.',
        ];
    }

    $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $pdo->prepare('DELETE FROM staff_password_otps WHERE staff_id = ?')->execute([$staff['staff_id']]);
    $pdo->prepare(
        'INSERT INTO staff_password_otps (staff_id, otp_hash, expires_at, attempts) VALUES (?, ?, ?, 0)'
    )->execute([$staff['staff_id'], password_hash($otp, PASSWORD_DEFAULT), $expiresAt]);

    $site = getSiteSettings()['name'];
    $subject = $site . ' — Staff password reset code';
    $displayName = personNameFromRow($staff);
    $plain = "Hello {$displayName},\n\n"
        . "Your ALCROS staff portal password reset code is: {$otp}\n\n"
        . "Staff ID: {$staff['staff_id']}\n"
        . "This code expires in 15 minutes.\n\n"
        . "If you did not request this, ignore this email and contact your administrator.";
    $html = '<p>Hello <strong>' . htmlspecialchars($displayName) . '</strong>,</p>'
        . '<p>Your staff portal password reset code is:</p>'
        . '<p style="font-size:28px;font-weight:800;letter-spacing:6px;color:#2563eb;">' . htmlspecialchars($otp) . '</p>'
        . '<p>Staff ID: <strong>' . htmlspecialchars($staff['staff_id']) . '</strong><br>'
        . 'This code expires in <strong>15 minutes</strong>.</p>'
        . '<p style="color:#64748b;font-size:13px;">If you did not request this, ignore this email and contact your administrator.</p>';

    if (!sendCitizenEmail($email, $subject, $plain, $html)) {
        $pdo->prepare('DELETE FROM staff_password_otps WHERE staff_id = ?')->execute([$staff['staff_id']]);

        return [
            'ok' => false,
            'message' => 'Could not send the verification email. Confirm Gmail SMTP is configured in System Settings, then try again.',
        ];
    }

    logEmailDelivery($email, $subject, 'staff_password_otp', $staff['staff_id'], true);

    return [
        'ok' => true,
        'message' => 'A 6-digit verification code was sent to ' . maskEmailAddress($email) . '.',
        'staff_id' => $staff['staff_id'],
        'email_hint' => maskEmailAddress($email),
    ];
}

function resetStaffPasswordWithOtp(PDO $pdo, string $staffId, string $otp, string $newPassword): array
{
    ensureExtendedSchema($pdo);
    purgeExpiredStaffOtps($pdo);

    $staffId = strtoupper(trim($staffId));
    $otp = trim($otp);
    if ($staffId === '' || $otp === '') {
        return ['ok' => false, 'message' => 'Staff ID and verification code are required.'];
    }
    if ($passwordError = validatePasswordStrength($newPassword)) {
        return ['ok' => false, 'message' => $passwordError];
    }

    $stmt = $pdo->prepare(
        'SELECT id, otp_hash, expires_at, attempts FROM staff_password_otps WHERE staff_id = ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$staffId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => 'No active verification code found. Request a new code.'];
    }
    if ((int) ($row['attempts'] ?? 0) >= 5) {
        return ['ok' => false, 'message' => 'Too many incorrect attempts. Request a new verification code.'];
    }
    if (strtotime((string) $row['expires_at']) < time()) {
        $pdo->prepare('DELETE FROM staff_password_otps WHERE id = ?')->execute([(int) $row['id']]);

        return ['ok' => false, 'message' => 'Verification code expired. Request a new code.'];
    }

    if (!password_verify($otp, (string) $row['otp_hash'])) {
        $pdo->prepare('UPDATE staff_password_otps SET attempts = attempts + 1 WHERE id = ?')->execute([(int) $row['id']]);

        return ['ok' => false, 'message' => 'Incorrect verification code.'];
    }

    $pdo->prepare('UPDATE staff SET password_hash = ? WHERE staff_id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $staffId]);
    $pdo->prepare('DELETE FROM staff_password_otps WHERE staff_id = ?')->execute([$staffId]);
    logActivity($staffId, 'Password Reset', 'Password reset via Gmail OTP');

    return ['ok' => true, 'message' => 'Password updated successfully. You can now sign in.'];
}

function saveStaffPhotoUpload(array $file, string $staffId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxBytes = 2 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return null;
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        default      => 'bin',
    };

    $dir = __DIR__ . '/../uploads/staff';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $safeId = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($staffId));
    $filename = 'staff_' . ($safeId !== '' ? $safeId : 'USER') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return 'uploads/staff/' . $filename;
}

function deleteStaffPhotoFile(?string $path): void
{
    deleteIdUploadFiles($path);
}

function staffInitial(string $name): string
{
    $name = trim($name);
    return $name !== '' ? strtoupper(substr($name, 0, 1)) : 'U';
}

function staffPhotoExists(?string $photoPath): bool
{
    $photoPath = trim((string) $photoPath);
    if ($photoPath === '') {
        return false;
    }

    return is_file(__DIR__ . '/../' . ltrim($photoPath, '/'));
}

function alcrosFaviconImg(int $sizePx = 20, string $extraClass = ''): string
{
    $size = max(16, min(72, $sizePx));
    $class = trim('object-cover shrink-0 rounded-full bg-white ' . $extraClass);

    return sprintf(
        '<img src="images/favicon.png?v=2" alt="ALCROS" class="%s" width="%d" height="%d">',
        htmlspecialchars($class, ENT_QUOTES, 'UTF-8'),
        $size,
        $size
    );
}

function renderStaffAvatar(?string $photoPath, string $name, string $classes = 'w-8 h-8', string $rounded = 'rounded-full'): string
{
    $initial = staffInitial($name);
    if (staffPhotoExists($photoPath)) {
        $src = protectedUploadUrl($photoPath) ?? $photoPath;
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="" class="'
            . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($rounded, ENT_QUOTES, 'UTF-8')
            . ' border border-gray-200 object-cover shrink-0">';
    }

    return '<div class="' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . ' ' . htmlspecialchars($rounded, ENT_QUOTES, 'UTF-8')
        . ' bg-gray-100 flex items-center justify-center border border-gray-200 text-xs font-bold text-gray-500 shrink-0">'
        . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</div>';
}

function civilRecordTypeLabel(string $type): string
{
    return match ($type) {
        'birth'    => 'Birth',
        'death'    => 'Death',
        'marriage' => 'Marriage',
        default    => ucfirst($type),
    };
}

function formatRecordDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

function recordsFlashSet(string $type, string $message): void
{
    $_SESSION['records_flash'] = [$type, $message];
}

function recordsFlashGet(): ?array
{
    $flash = $_SESSION['records_flash'] ?? null;
    unset($_SESSION['records_flash']);
    return $flash;
}

function settingsFlashSet(string $type, string $message): void
{
    $_SESSION['settings_flash'] = [$type, $message];
}

function settingsFlashGet(): ?array
{
    $flash = $_SESSION['settings_flash'] ?? null;
    unset($_SESSION['settings_flash']);
    return $flash;
}

function manageRequestsFlashSet(string $type, string $message): void
{
    $_SESSION['manage_requests_flash'] = [$type, $message];
}

function manageRequestsFlashGet(): ?array
{
    $flash = $_SESSION['manage_requests_flash'] ?? null;
    unset($_SESSION['manage_requests_flash']);
    return $flash;
}

function appointmentFlashSet(string $type, string $message): void
{
    $_SESSION['appointment_flash'] = [$type, $message];
}

function appointmentFlashGet(): ?array
{
    $flash = $_SESSION['appointment_flash'] ?? null;
    unset($_SESSION['appointment_flash']);
    return $flash;
}

function queueFlashSet(string $type, string $message): void
{
    $_SESSION['queue_flash'] = [$type, $message];
}

function queueFlashGet(): ?array
{
    $flash = $_SESSION['queue_flash'] ?? null;
    unset($_SESSION['queue_flash']);
    return $flash;
}

function documentRequestViewData(array $row): array
{
    $appointment = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);

    return [
        'tracking_code'  => (string) ($row['tracking_code'] ?? ''),
        'citizen_name'   => personNameFromRow($row),
        'first_name'     => (string) ($row['first_name'] ?? ''),
        'middle_name'    => (string) ($row['middle_name'] ?? ''),
        'last_name'      => (string) ($row['last_name'] ?? ''),
        'date_of_birth'  => !empty($row['date_of_birth']) ? formatDateDisplay($row['date_of_birth']) : '—',
        'sex'            => !empty($row['sex']) ? ucfirst((string) $row['sex']) : '—',
        'email'          => !empty($row['email']) ? (string) $row['email'] : '—',
        'email_verified' => !empty($row['email_verified']) ? 'Yes' : 'No',
        'phone'          => !empty($row['phone']) ? (string) $row['phone'] : '—',
        'document_type'  => documentTypeLabel((string) ($row['document_type'] ?? '')),
        'purpose'        => !empty($row['purpose']) ? (string) $row['purpose'] : '—',
        'id_front_path'  => protectedUploadUrl(!empty($row['id_front_path']) ? (string) $row['id_front_path'] : null),
        'id_back_path'   => protectedUploadUrl(!empty($row['id_back_path']) ? (string) $row['id_back_path'] : null),
        'appointment'    => $appointment !== '' ? $appointment : '—',
        'status'         => requestStatusLabel((string) ($row['status'] ?? 'pending')),
        'privacy_agreed' => !empty($row['privacy_agreed']) ? 'Yes' : 'No',
        'submitted_at'   => !empty($row['submitted_at']) ? formatDateDisplay($row['submitted_at']) : '—',
        'updated_at'     => !empty($row['updated_at']) ? formatDateDisplay($row['updated_at']) : '—',
        'notes'          => !empty($row['notes']) ? (string) $row['notes'] : null,
    ];
}

function appointmentViewData(array $row): array
{
    $isRequest = (($row['source'] ?? '') === 'document_request') || !empty($row['tracking_code']);

    return [
        'appointment_code' => (string) ($row['appointment_code'] ?? ''),
        'citizen_name'     => personNameFromRow($row),
        'first_name'       => (string) ($row['first_name'] ?? ''),
        'middle_name'      => (string) ($row['middle_name'] ?? ''),
        'last_name'        => (string) ($row['last_name'] ?? ''),
        'email'            => !empty($row['email']) ? (string) $row['email'] : '—',
        'phone'            => !empty($row['phone']) ? (string) $row['phone'] : '—',
        'service_type'     => appointmentServiceLabel((string) ($row['service_type'] ?? '')),
        'schedule'         => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null) ?: '—',
        'status'           => appointmentDisplayStatusLabel($row),
        'source'           => $isRequest ? 'Document request visit' : 'Special service appointment',
        'tracking_code'    => !empty($row['tracking_code']) ? (string) $row['tracking_code'] : '',
        'notify_email'     => !empty($row['notify_email']) ? 'Yes' : 'No',
        'id_front_path'    => protectedUploadUrl(!empty($row['id_front_path']) ? (string) $row['id_front_path'] : null),
        'id_back_path'     => protectedUploadUrl(!empty($row['id_back_path']) ? (string) $row['id_back_path'] : null),
        'created_at'       => !empty($row['created_at']) ? formatDateDisplay($row['created_at']) : '—',
        'notes'            => !empty($row['notes']) ? (string) $row['notes'] : null,
    ];
}

function appointmentSearchBlob(array $row): string
{
    $parts = [
        $row['appointment_code'] ?? '',
        $row['tracking_code'] ?? '',
        personNameFromRow($row),
        $row['first_name'] ?? '',
        $row['middle_name'] ?? '',
        $row['last_name'] ?? '',
        $row['email'] ?? '',
        $row['phone'] ?? '',
        appointmentServiceLabel((string) ($row['service_type'] ?? '')),
        appointmentDisplayStatusLabel($row),
    ];

    return implode(' ', array_filter(array_map(static fn ($v) => trim((string) $v), $parts), static fn ($v) => $v !== ''));
}

function findAppointmentDateForSearch(PDO $pdo, string $q): ?string
{
    $q = trim($q);
    if ($q === '') {
        return null;
    }

    $term = '%' . $q . '%';
    $stmt = $pdo->prepare(
        'SELECT appointment_date
         FROM appointments
         WHERE appointment_code LIKE ? OR tracking_code LIKE ?
            OR first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ?
         ORDER BY appointment_date DESC
         LIMIT 1'
    );
    $stmt->execute([$term, $term, $term, $term, $term]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return !empty($row['appointment_date']) ? (string) $row['appointment_date'] : null;
}

function requestStatusLabel(string $status): string
{
    return match (normalizeRequestStatus($status)) {
        'pending'   => 'Pending',
        'verified'  => 'Verified',
        'ready'     => 'Ready for Pickup',
        'completed' => 'Completed',
        'rejected'  => 'Rejected',
        default     => ucfirst($status),
    };
}

function requestStatusMessage(string $status): string
{
    return match (normalizeRequestStatus($status)) {
        'pending'   => 'We received your request. Staff will review your documents soon.',
        'verified'  => 'Your request has been verified. The civil registry office is now processing your document.',
        'ready'     => 'Your document is ready for pickup! Visit the office with your tracking code and valid ID.',
        'completed' => 'This request is complete. Thank you for using ALCROS.',
        'rejected'  => 'This request could not be approved. Please contact the registry office for help.',
        default     => 'Track your request status below.',
    };
}

function fetchDocumentRequestAppointment(PDO $pdo, string $trackingCode): ?array
{
    $trackingCode = trim($trackingCode);
    if ($trackingCode === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT appointment_code, status, appointment_date, appointment_time
         FROM appointments WHERE tracking_code = ? LIMIT 1'
    );
    $stmt->execute([$trackingCode]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function publicRequestStatusLabel(string $status): string
{
    return match (normalizeRequestStatus($status)) {
        'verified'  => 'Confirmed · Ready for Pickup',
        'ready'     => 'Ready for Pickup',
        default     => requestStatusLabel($status),
    };
}

function publicRequestStatusMessage(string $status, ?array $appointment = null): string
{
    $status = normalizeRequestStatus($status);

    if ($status === 'verified' || $status === 'ready') {
        $visit = '';
        if ($appointment && !empty($appointment['appointment_date'])) {
            $visit = ' Your confirmed visit is on '
                . formatAppointmentDisplay($appointment['appointment_date'], $appointment['appointment_time'] ?? null)
                . '.';
        }

        return 'Your request has been verified, your pickup visit is confirmed, and your document is ready for pickup.'
            . $visit
            . ' Please visit the Local Civil Registrar Office with your tracking code and a valid ID.';
    }

    return requestStatusMessage($status);
}

function publicRequestStatusProgressIndex(string $status): int|false
{
    $status = normalizeRequestStatus($status);
    if ($status === 'rejected') {
        return false;
    }
    if ($status === 'verified') {
        return 2;
    }

    return requestStatusProgressIndex($status);
}

function publicRequestStatusBadge(string $status): string
{
    $status = normalizeRequestStatus($status);
    $classes = [
        'pending'   => 'bg-yellow-100 text-yellow-700',
        'verified'  => 'bg-green-100 text-green-700',
        'ready'     => 'bg-green-100 text-green-700',
        'completed' => 'bg-gray-100 text-gray-600',
        'rejected'  => 'bg-red-100 text-red-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';

    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">'
        . htmlspecialchars(publicRequestStatusLabel($status))
        . '</span>';
}

function appBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '' || $base === '.') {
        return $scheme . '://' . $host;
    }
    return $scheme . '://' . $host . $base;
}

function trackRequestUrl(string $trackingCode): string
{
    return appBaseUrl() . '/index.php?track=' . urlencode($trackingCode);
}

function citizenWantsEmailNotify(?array $row): bool
{
    if (!$row) {
        return false;
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return !empty($row['notify_email']);
}

function smtpRead($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtpCommand($fp, string $command, string $expectPrefix): bool
{
    fwrite($fp, $command . "\r\n");
    $response = smtpRead($fp);
    return str_starts_with($response, $expectPrefix);
}

function citizenEmailText(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function citizenEmailPlain(array $mail): string
{
    $lines = [];
    $name = trim((string) ($mail['name'] ?? ''));
    $lines[] = $name !== '' ? 'Dear ' . $name . ',' : 'Hello,';
    $lines[] = '';
    $lines[] = trim((string) ($mail['intro'] ?? ''));
    $lines[] = '';
    if (!empty($mail['code'])) {
        $lines[] = ($mail['code_label'] ?? 'Code') . ': ' . $mail['code'];
    }
    foreach ($mail['details'] ?? [] as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $lines[] = $label . ': ' . $value;
    }
    $lines[] = '';
    if (!empty($mail['note'])) {
        $lines[] = trim((string) $mail['note']);
        $lines[] = '';
    }
    if (!empty($mail['button_url'])) {
        $lines[] = ($mail['button_label'] ?? 'Open link') . ':';
        $lines[] = (string) $mail['button_url'];
        $lines[] = '';
    }
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $lines[] = '— ALCROS, ' . $office;

    return implode("\n", $lines);
}

function citizenEmailHtml(array $mail): string
{
    $site    = getSetting('site_name', 'ALCROS');
    $office  = getSetting('office_name', 'Local Civil Registrar Office');
    $phone   = getSetting('office_phone', '');
    $hours   = getSetting('office_hours', '');
    $accent  = (string) ($mail['accent'] ?? '#2563eb');
    $heading = (string) ($mail['heading'] ?? 'Update from ALCROS');
    $name    = trim((string) ($mail['name'] ?? ''));
    $intro   = trim((string) ($mail['intro'] ?? ''));
    $note    = trim((string) ($mail['note'] ?? ''));
    $code    = trim((string) ($mail['code'] ?? ''));
    $preheader = $intro !== '' ? $intro : $heading;

    $detailRows = '';
    foreach ($mail['details'] ?? [] as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $detailRows .= '<tr>'
            . '<td style="padding:8px 0;font-size:11px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;width:38%;vertical-align:top;">'
            . citizenEmailText((string) $label) . '</td>'
            . '<td style="padding:8px 0;font-size:14px;color:#0f172a;font-weight:600;">'
            . citizenEmailText($value) . '</td>'
            . '</tr>';
    }

    $codeBlock = '';
    if ($code !== '') {
        $codeBlock = '<p style="margin:0 0 4px;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;">'
            . citizenEmailText((string) ($mail['code_label'] ?? 'Code')) . '</p>'
            . '<p style="margin:0 0 16px;font-size:22px;letter-spacing:.12em;font-weight:800;color:' . citizenEmailText($accent) . ';font-family:Consolas,\'Courier New\',monospace;">'
            . citizenEmailText($code) . '</p>';
    }

    $button = '';
    if (!empty($mail['button_url'])) {
        $label = citizenEmailText((string) ($mail['button_label'] ?? 'View details'));
        $url   = citizenEmailText((string) $mail['button_url']);
        $button = '<table cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;">'
            . '<tr><td style="background:' . citizenEmailText($accent) . ';border-radius:8px;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-size:13px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;">'
            . $label . '</a></td></tr></table>';
    }

    $introHtml = $intro !== ''
        ? '<p style="margin:0 0 20px;font-size:14px;line-height:1.65;color:#475569;">' . nl2br(citizenEmailText($intro)) . '</p>'
        : '';
    $noteHtml = $note !== ''
        ? '<p style="margin:18px 0 20px;font-size:14px;line-height:1.65;color:#475569;">' . nl2br(citizenEmailText($note)) . '</p>'
        : '';
    $hello = $name !== '' ? 'Hello, ' . $name : 'Hello';
    $footerBits = array_filter([$hours !== '' ? 'Office hours: ' . $hours : '', $phone !== '' ? 'Contact: ' . $phone : '']);
    $footer = implode(' · ', $footerBits);

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . citizenEmailText($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<tr><td style="background:' . citizenEmailText($accent) . ';padding:22px 28px;">'
        . '<p style="margin:0;color:#ffffff;font-size:11px;letter-spacing:.18em;font-weight:bold;">' . citizenEmailText($site) . '</p>'
        . '<p style="margin:6px 0 0;color:#ffffff;font-size:13px;opacity:.9;">' . citizenEmailText($office) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 6px;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:' . citizenEmailText($accent) . ';">'
        . citizenEmailText($heading) . '</p>'
        . '<h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#0f172a;">' . citizenEmailText($hello) . '</h1>'
        . $introHtml
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<tr><td style="padding:18px 20px;">' . $codeBlock
        . ($detailRows !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $detailRows . '</table>' : '')
        . '</td></tr></table>'
        . $noteHtml
        . $button
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e2e8f0;">'
        . '<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">'
        . citizenEmailText($footer) . ($footer !== '' ? '<br>' : '')
        . 'This is an automated message from ALCROS. Please do not reply to this email.'
        . '</p></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function sendCitizenNotice(string $to, string $subject, array $mail, string $emailType = 'general'): bool
{
    $ok = sendCitizenEmail($to, $subject, citizenEmailPlain($mail), citizenEmailHtml($mail));
    logEmailDelivery($to, $subject, $emailType, isset($mail['code']) ? (string) $mail['code'] : null, $ok);
    return $ok;
}

function buildEmailMime(string $fromName, string $fromEmail, string $body, ?string $html = null): array
{
    $fromHeader = 'From: "' . addcslashes($fromName, '"') . '" <' . $fromEmail . '>';
    $headers = $fromHeader . "\r\n"
        . 'Reply-To: ' . $fromEmail . "\r\n"
        . "MIME-Version: 1.0\r\n";

    $plain = str_replace(["\r\n", "\r"], "\n", $body);
    if ($html === null || $html === '') {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";
        return [$headers, $plain];
    }

    $boundary = 'alcros_' . bin2hex(random_bytes(8));
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
    $htmlNorm = str_replace(["\r\n", "\r"], "\n", $html);
    $mime = "--{$boundary}\n"
        . "Content-Type: text/plain; charset=UTF-8\n"
        . "Content-Transfer-Encoding: 8bit\n\n"
        . $plain . "\n\n"
        . "--{$boundary}\n"
        . "Content-Type: text/html; charset=UTF-8\n"
        . "Content-Transfer-Encoding: 8bit\n\n"
        . $htmlNorm . "\n\n"
        . "--{$boundary}--";

    return [$headers, $mime];
}

function sendSmtpEmail(string $to, string $subject, string $body, ?string $html = null): bool
{
    $host = trim(getSetting('smtp_host', 'smtp.gmail.com')) ?: 'smtp.gmail.com';
    $port = (int) getSetting('smtp_port', '587');
    if ($port <= 0) {
        $port = 587;
    }
    $user = trim(getSetting('smtp_user', getSetting('notification_email', '')));
    $pass = (string) getSetting('smtp_pass', '');
    if ($user === '' || $pass === '') {
        return false;
    }

    $fromName = getSetting('site_name', 'ALCROS') . ' - ' . getSetting('office_name', 'Local Civil Registrar Office');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    [$headers, $mimeBody] = buildEmailMime($fromName, $user, $body, $html);
    $payload = $headers
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n\r\n"
        . str_replace("\n", "\r\n", $mimeBody) . "\r\n";
    $payload = preg_replace('/^\./m', '..', $payload);

    $errno = 0;
    $errstr = '';
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 15);

    if (!str_starts_with(smtpRead($fp), '220')) {
        fclose($fp);
        return false;
    }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!smtpCommand($fp, 'EHLO ' . $ehloHost, '250')) {
        fclose($fp);
        return false;
    }

    if ($port !== 465) {
        if (!smtpCommand($fp, 'STARTTLS', '220')) {
            fclose($fp);
            return false;
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        if (!smtpCommand($fp, 'EHLO ' . $ehloHost, '250')) {
            fclose($fp);
            return false;
        }
    }

    if (!smtpCommand($fp, 'AUTH LOGIN', '334')
        || !smtpCommand($fp, base64_encode($user), '334')
        || !smtpCommand($fp, base64_encode($pass), '235')
        || !smtpCommand($fp, 'MAIL FROM:<' . $user . '>', '250')
        || !smtpCommand($fp, 'RCPT TO:<' . $to . '>', '250')
        || !smtpCommand($fp, 'DATA', '354')
    ) {
        fclose($fp);
        return false;
    }

    fwrite($fp, $payload . "\r\n.\r\n");
    $dataOk = str_starts_with(smtpRead($fp), '250');
    smtpCommand($fp, 'QUIT', '221');
    fclose($fp);

    return $dataOk;
}

function sendCitizenEmail(string $to, string $subject, string $body, ?string $html = null): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (sendSmtpEmail($to, $subject, $body, $html)) {
        return true;
    }

    $from    = getSetting('smtp_user', getSetting('notification_email', getSetting('office_email', 'aloran@gov.ph')));
    $office  = getSetting('office_name', 'Local Civil Registrar Office');
    [$headers, $mimeBody] = buildEmailMime('ALCROS - ' . $office, $from, $body, $html);
    return @mail($to, $subject, str_replace("\n", "\r\n", $mimeBody), $headers);
}

function notifyRequestSubmitted(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    $details = [
        'Document'       => (string) ($data['document_label'] ?? ''),
        'Current status' => 'Pending review',
    ];
    if (!empty($data['appointment_date']) && !empty($data['appointment_time'])) {
        $details['Preferred visit'] = formatDateDisplay($data['appointment_date'])
            . ' at ' . date('g:i A', strtotime($data['appointment_time']));
    }

    return sendCitizenNotice(
        $data['email'],
        'ALCROS — Request received (' . $data['tracking_code'] . ')',
        [
            'heading'      => 'Request received',
            'name'         => personNameFromRow($data),
            'intro'        => 'Thank you for submitting your document request through ALCROS.',
            'code_label'   => 'Tracking code',
            'code'         => $data['tracking_code'],
            'details'      => $details,
            'note'         => "We will email this Gmail address when staff verifies your request, when the status changes, and at 5 hours, 3 hours, and 1 hour before your preferred visit.\n\nKeep this code safe — you will need it to check your status.",
            'button_label' => 'Track your request',
            'button_url'   => trackRequestUrl($data['tracking_code']),
            'accent'       => '#2563eb',
        ]
    );
}

function syncDocumentRequestAppointment(PDO $pdo, int $requestId, string $requestStatus): void
{
    ensureCitizenNotifyColumns($pdo);
    $status = normalizeRequestStatus($requestStatus);

    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, email, phone, notify_email,
                document_type, appointment_date, appointment_time, id_front_path, id_back_path
         FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $trackingCode = (string) $row['tracking_code'];
    $apptStmt = $pdo->prepare('SELECT id, status FROM appointments WHERE tracking_code = ? LIMIT 1');
    $apptStmt->execute([$trackingCode]);
    $existing = $apptStmt->fetch(PDO::FETCH_ASSOC);

    if ($status === 'verified') {
        $apptDate = $row['appointment_date'] ?? null;
        $apptTime = $row['appointment_time'] ?? null;
        if (!$apptDate || !$apptTime) {
            return;
        }

        $normalizedTime = normalizeAppointmentTime($apptTime);
        $serviceType = documentTypeLabel($row['document_type']);

        if ($existing) {
            $pdo->prepare(
                "UPDATE appointments
                 SET status = 'confirmed', appointment_date = ?, appointment_time = ?, service_type = ?
                 WHERE id = ?"
            )->execute([$apptDate, $normalizedTime, $serviceType, $existing['id']]);

            return;
        }

        $apptCode = generateAppointmentCode();
        $pdo->prepare(
            'INSERT INTO appointments
             (appointment_code, first_name, middle_name, last_name, email, phone, notify_email,
              service_type, appointment_date, appointment_time, status, source, tracking_code,
              id_front_path, id_back_path)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $apptCode,
            $row['first_name'],
            $row['middle_name'] ?? null,
            $row['last_name'],
            $row['email'],
            $row['phone'],
            (int) ($row['notify_email'] ?? 0),
            $serviceType,
            $apptDate,
            $normalizedTime,
            'confirmed',
            'document_request',
            $trackingCode,
            $row['id_front_path'] ?? null,
            $row['id_back_path'] ?? null,
        ]);

        return;
    }

    if (!$existing) {
        return;
    }

    $apptStatus = match ($status) {
        'rejected'  => 'cancelled',
        'completed' => 'completed',
        default     => null,
    };
    if ($apptStatus !== null && $existing['status'] !== $apptStatus) {
        $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$apptStatus, $existing['id']]);
    }
}

function notifyRequestStatusChange(PDO $pdo, int $requestId, string $newStatus): void
{
    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT tracking_code, first_name, middle_name, last_name, email, document_type, status, appointment_date, appointment_time, notify_email
         FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!citizenWantsEmailNotify($row)) {
        return;
    }

    $status = normalizeRequestStatus($newStatus);
    $appointment = fetchDocumentRequestAppointment($pdo, (string) $row['tracking_code']);
    $visitSchedule = formatAppointmentDisplay(
        $appointment['appointment_date'] ?? $row['appointment_date'] ?? null,
        $appointment['appointment_time'] ?? $row['appointment_time'] ?? null
    );

    $intro = match ($status) {
        'verified'  => 'Your request has been verified, your pickup visit is confirmed, and your document is ready for pickup.',
        'ready'     => 'Good news — your document is ready for pickup and your visit remains confirmed.',
        'completed' => 'Your request has been completed.',
        'rejected'  => 'There is an update on your document request.',
        default     => 'There is an update on your document request.',
    };
    $subject = match ($status) {
        'verified', 'ready' => 'ALCROS — Ready for pickup (' . $row['tracking_code'] . ')',
        default             => 'ALCROS — Request update (' . $row['tracking_code'] . ')',
    };
    $accent = match ($status) {
        'verified', 'ready' => '#16a34a',
        'completed'         => '#475569',
        'rejected'          => '#dc2626',
        default             => '#2563eb',
    };
    $heading = match ($status) {
        'verified'  => 'Confirmed · Ready for pickup',
        'ready'     => 'Ready for pickup',
        'completed' => 'Request completed',
        'rejected'  => 'Request update',
        default     => 'Request update',
    };

    $details = [
        'Document'   => documentTypeLabel($row['document_type']),
        'New status' => publicRequestStatusLabel($newStatus),
    ];
    if ($status === 'verified' || $status === 'ready') {
        $details['Visit status'] = 'Confirmed';
        if ($visitSchedule !== '—') {
            $details['Scheduled visit'] = $visitSchedule;
        }
    }

    sendCitizenNotice($row['email'], $subject, [
        'heading'      => $heading,
        'name'         => personNameFromRow($row),
        'intro'        => $intro,
        'code_label'   => 'Tracking code',
        'code'         => $row['tracking_code'],
        'details'      => $details,
        'note'         => publicRequestStatusMessage($newStatus, $appointment),
        'button_label' => 'View full details',
        'button_url'   => trackRequestUrl($row['tracking_code']),
        'accent'       => $accent,
    ]);
}

function notifyAppointmentBooked(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    return sendCitizenNotice(
        $data['email'],
        'ALCROS — Appointment booked (' . $data['appointment_code'] . ')',
        [
            'heading'      => 'Appointment booked',
            'name'         => personNameFromRow($data),
            'intro'        => 'Your appointment was booked successfully.',
            'code_label'   => 'Appointment code',
            'code'         => $data['appointment_code'],
            'details'      => [
                'Service'  => (string) ($data['service_label'] ?? ''),
                'Schedule' => formatAppointmentDisplay($data['appointment_date'] ?? null, $data['appointment_time'] ?? null),
                'Status'   => 'Awaiting confirmation',
            ],
            'note'         => 'Your preferred date and time are saved, but staff must still confirm your visit. We will email this Gmail address when the status changes and at 5 hours, 3 hours, and 1 hour before a confirmed appointment.',
            'button_label' => 'Track appointment',
            'button_url'   => trackRequestUrl($data['appointment_code']),
            'accent'       => '#2563eb',
        ]
    );
}

function notifyAppointmentStatusChange(PDO $pdo, int $appointmentId, string $newStatus): void
{
    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT appointment_code, first_name, middle_name, last_name, email, service_type, appointment_date, appointment_time, notify_email
         FROM appointments WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!citizenWantsEmailNotify($row)) {
        return;
    }

    $accent = match ($newStatus) {
        'completed' => '#16a34a',
        'cancelled', 'no_show' => '#dc2626',
        'confirmed' => '#2563eb',
        default => '#2563eb',
    };

    sendCitizenNotice(
        $row['email'],
        'ALCROS — Appointment update (' . $row['appointment_code'] . ')',
        [
            'heading'      => 'Appointment update',
            'name'         => personNameFromRow($row),
            'intro'        => 'There is an update on your appointment.',
            'code_label'   => 'Appointment code',
            'code'         => $row['appointment_code'],
            'details'      => [
                'Service'    => appointmentServiceLabel((string) $row['service_type']),
                'Schedule'   => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null),
                'New status' => appointmentStatusLabel($newStatus),
            ],
            'note'         => appointmentStatusMessage($newStatus),
            'button_label' => 'View full details',
            'button_url'   => trackRequestUrl($row['appointment_code']),
            'accent'       => $accent,
        ]
    );
}

function reminderLeadTimes(): array
{
    return [5, 3, 1];
}

function reminderSentColumn(int $hours): string
{
    return match ($hours) {
        5 => 'reminder_5h_sent_at',
        3 => 'reminder_3h_sent_at',
        1 => 'reminder_1h_sent_at',
        default => throw new InvalidArgumentException('Unsupported reminder lead time.'),
    };
}

function reminderHoursLabel(int $hours): string
{
    return $hours === 1 ? '1 hour' : $hours . ' hours';
}

function ensureReminderLeadColumns(PDO $pdo, string $table): void
{
    if (!in_array($table, ['document_requests', 'appointments'], true)) {
        return;
    }

    foreach (reminderLeadTimes() as $hours) {
        $column = reminderSentColumn($hours);
        try {
            $pdo->query("SELECT `$column` FROM `$table` LIMIT 1");
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` TIMESTAMP NULL DEFAULT NULL AFTER reminder_sent_at");
            } catch (Throwable $ignored) {
            }
        }
    }

    try {
        $pdo->exec(
            "UPDATE `$table`
             SET reminder_5h_sent_at = reminder_sent_at
             WHERE reminder_sent_at IS NOT NULL
               AND reminder_5h_sent_at IS NULL"
        );
    } catch (Throwable $ignored) {
    }
}

function notifyAppointmentReminder(array $row, int $hoursBefore): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    $hoursLabel = reminderHoursLabel($hoursBefore);

    return sendCitizenNotice(
        (string) $row['email'],
        'ALCROS — Appointment in ' . $hoursLabel . ' (' . $row['appointment_code'] . ')',
        [
            'heading'      => 'Appointment reminder',
            'name'         => personNameFromRow($row),
            'intro'        => 'This is a reminder that your appointment is in about ' . $hoursLabel . '.',
            'code_label'   => 'Appointment code',
            'code'         => $row['appointment_code'],
            'details'      => [
                'Service'  => appointmentServiceLabel((string) ($row['service_type'] ?? '')),
                'Schedule' => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null),
            ],
            'note'         => 'Please arrive on time and bring a valid ID.',
            'button_label' => 'View appointment',
            'button_url'   => trackRequestUrl((string) $row['appointment_code']),
            'accent'       => '#d97706',
        ]
    );
}

function notifyRequestVisitReminder(array $row, int $hoursBefore): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    $hoursLabel = reminderHoursLabel($hoursBefore);

    return sendCitizenNotice(
        (string) $row['email'],
        'ALCROS — Visit in ' . $hoursLabel . ' (' . $row['tracking_code'] . ')',
        [
            'heading'      => 'Visit reminder',
            'name'         => personNameFromRow($row),
            'intro'        => 'This is a reminder that your preferred visit for document pickup is in about ' . $hoursLabel . '.',
            'code_label'   => 'Tracking code',
            'code'         => $row['tracking_code'],
            'details'      => [
                'Document'        => documentTypeLabel((string) ($row['document_type'] ?? '')),
                'Current status'  => requestStatusLabel((string) ($row['status'] ?? 'pending')),
                'Preferred visit' => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null),
            ],
            'note'         => 'Please arrive on time and bring your tracking code and a valid ID.',
            'button_label' => 'Track your request',
            'button_url'   => trackRequestUrl((string) $row['tracking_code']),
            'accent'       => '#d97706',
        ]
    );
}

function sendDueAppointmentReminders(PDO $pdo): int
{
    ensureCitizenNotifyColumns($pdo);

    $lockDir = __DIR__ . '/../storage';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }
    $lockFile = $lockDir . '/appointment_reminders.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return 0;
    }

    $sent = 0;
    try {
        foreach (reminderLeadTimes() as $hours) {
            $sent += sendDueAppointmentRemindersForLeadTime($pdo, $hours);
        }
        require_once __DIR__ . '/sms.php';
        $sent += sendDueSmsVisitReminders($pdo);
    } catch (Throwable $e) {
        $sent = 0;
    }

    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $sent;
}

function sendDueAppointmentRemindersForLeadTime(PDO $pdo, int $hours): int
{
    $column = reminderSentColumn($hours);
    $sent = 0;

    $reqStmt = $pdo->query(
        "SELECT id, tracking_code, first_name, middle_name, last_name, email, document_type, status, appointment_date, appointment_time, notify_email
         FROM document_requests
         WHERE notify_email = 1
           AND email IS NOT NULL AND email != ''
           AND `$column` IS NULL
           AND status IN ('pending', 'verified', 'ready')
           AND appointment_date IS NOT NULL
           AND appointment_time IS NOT NULL
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL $hours HOUR)"
    );
    $requests = $reqStmt ? $reqStmt->fetchAll() : [];

    $claimReq = $pdo->prepare("UPDATE document_requests SET `$column` = NOW() WHERE id = ? AND `$column` IS NULL");
    $undoReq  = $pdo->prepare("UPDATE document_requests SET `$column` = NULL WHERE id = ?");
    $markLinkedAppt = $pdo->prepare(
        "UPDATE appointments SET `$column` = NOW()
         WHERE email = ? AND appointment_date = ? AND appointment_time = ? AND `$column` IS NULL"
    );

    foreach ($requests as $row) {
        $claimReq->execute([(int) $row['id']]);
        if ($claimReq->rowCount() === 0) {
            continue;
        }
        if (notifyRequestVisitReminder($row, $hours)) {
            $sent++;
            $markLinkedAppt->execute([$row['email'], $row['appointment_date'], $row['appointment_time']]);
        } else {
            $undoReq->execute([(int) $row['id']]);
        }
    }

    $apptStmt = $pdo->query(
        "SELECT id, appointment_code, first_name, middle_name, last_name, email, service_type, appointment_date, appointment_time, notify_email
         FROM appointments
         WHERE notify_email = 1
           AND email IS NOT NULL AND email != ''
           AND `$column` IS NULL
           AND status IN ('scheduled', 'confirmed')
           AND TIMESTAMP(appointment_date, appointment_time) > NOW()
           AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL $hours HOUR)"
    );
    $appointments = $apptStmt ? $apptStmt->fetchAll() : [];

    $claimAppt = $pdo->prepare("UPDATE appointments SET `$column` = NOW() WHERE id = ? AND `$column` IS NULL");
    $undoAppt  = $pdo->prepare("UPDATE appointments SET `$column` = NULL WHERE id = ?");

    foreach ($appointments as $row) {
        $claimAppt->execute([(int) $row['id']]);
        if ($claimAppt->rowCount() === 0) {
            continue;
        }
        if (notifyAppointmentReminder($row, $hours)) {
            $sent++;
        } else {
            $undoAppt->execute([(int) $row['id']]);
        }
    }

    return $sent;
}

function formatAppointmentDisplay(?string $date, ?string $time): string
{
    if (!$date) {
        return '';
    }
    $out = formatDateDisplay($date);
    if ($time) {
        $ts = strtotime($time);
        $out .= $ts ? ' · ' . date('g:i A', $ts) : '';
    }
    return $out;
}

function isMaintenanceMode(): bool
{
    return getSetting('maintenance_mode', '0') === '1';
}

function arePublicRequestsAllowed(): bool
{
    return getSetting('allow_public_requests', '1') === '1';
}

function getSystemStats(PDO $pdo): array
{
    $tables = [
        'document_requests' => 'Requests',
        'appointments'      => 'Appointments',
        'civil_records'     => 'Civil Records',
        'queue_tickets'     => 'Queue Tickets',
        'staff'             => 'Staff Accounts',
        'activity_logs'     => 'Activity Logs',
    ];
    $stats = [];
    foreach ($tables as $table => $label) {
        try {
            $stats[$table] = [
                'label' => $label,
                'count' => (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
            ];
        } catch (PDOException $e) {
            $stats[$table] = ['label' => $label, 'count' => 0];
        }
    }
    return $stats;
}

function resolveReportDateRange(string $range, ?string $from = null, ?string $to = null): array
{
    $today = date('Y-m-d');

    if ($range === 'week') {
        return [date('Y-m-d', strtotime('-6 days')), $today];
    }

    if ($range === 'month') {
        return [date('Y-m-01'), $today];
    }

    if ($range === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to)) {
        if ($from > $to) {
            return [$to, $from];
        }

        return [$from, $to];
    }

    return [$today, $today];
}

function reportRangeLabel(string $range, string $from, string $to): string
{
    if ($from === $to) {
        return formatDateDisplay($from);
    }

    return formatDateDisplay($from) . ' – ' . formatDateDisplay($to);
}

function civilRecordRegisteredDateExpr(): string
{
    return 'COALESCE(registration_date, event_date, DATE(created_at))';
}

function resolveReportYear(?string $yearInput = null): int
{
    $year = (int) ($yearInput ?? date('Y'));
    $current = (int) date('Y');

    if ($year < 2000 || $year > $current + 1) {
        return $current;
    }

    return $year;
}

function quarterLabels(): array
{
    return [
        1 => 'Q1 (Jan–Mar)',
        2 => 'Q2 (Apr–Jun)',
        3 => 'Q3 (Jul–Sep)',
        4 => 'Q4 (Oct–Dec)',
    ];
}

function buildQuarterlyCivilRecordsReport(PDO $pdo, int $year): array
{
    $dateExpr = civilRecordRegisteredDateExpr();
    $stmt = $pdo->prepare(
        "SELECT QUARTER($dateExpr) AS quarter_num, record_type, COUNT(*) AS cnt
         FROM civil_records
         WHERE deleted_at IS NULL AND YEAR($dateExpr) = ?
         GROUP BY quarter_num, record_type
         ORDER BY quarter_num, record_type"
    );
    $stmt->execute([$year]);

    $quarters = [];
    foreach (quarterLabels() as $num => $label) {
        $quarters[$num] = [
            'label' => $label,
            'birth' => 0,
            'death' => 0,
            'marriage' => 0,
            'total' => 0,
        ];
    }

    $yearTotals = ['birth' => 0, 'death' => 0, 'marriage' => 0, 'total' => 0];

    foreach ($stmt->fetchAll() as $row) {
        $quarterNum = (int) $row['quarter_num'];
        $recordType = (string) $row['record_type'];
        $count = (int) $row['cnt'];

        if (!isset($quarters[$quarterNum]) || !in_array($recordType, ['birth', 'death', 'marriage'], true)) {
            continue;
        }

        $quarters[$quarterNum][$recordType] = $count;
        $quarters[$quarterNum]['total'] += $count;
        $yearTotals[$recordType] += $count;
        $yearTotals['total'] += $count;
    }

    return [
        'year' => $year,
        'generated_at' => date('Y-m-d H:i:s'),
        'office_name' => getSetting('office_name', 'Local Civil Registrar Office'),
        'site_name' => getSetting('site_name', 'ALCROS'),
        'quarters' => $quarters,
        'year_totals' => $yearTotals,
        'record_types' => ['birth', 'death', 'marriage'],
    ];
}

function exportQuarterlyCivilRecordsCsv(array $report): void
{
    $year = $report['year'];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ALCROS_Civil_Records_Quarterly_' . $year . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($out, ['ALCROS Civil Records — Quarterly Registration Report']);
    fputcsv($out, ['Office', $report['office_name']]);
    fputcsv($out, ['Calendar year', $year]);
    fputcsv($out, ['Generated on', $report['generated_at']]);
    fputcsv($out, ['Note', 'Counts use registration date, or event/entry date when registration date is not set.']);
    fputcsv($out, []);
    fputcsv($out, ['Quarter', 'Birth', 'Death', 'Marriage', 'Quarter total']);

    foreach ($report['quarters'] as $quarter) {
        fputcsv($out, [
            $quarter['label'],
            $quarter['birth'],
            $quarter['death'],
            $quarter['marriage'],
            $quarter['total'],
        ]);
    }

    fputcsv($out, [
        'Year total',
        $report['year_totals']['birth'],
        $report['year_totals']['death'],
        $report['year_totals']['marriage'],
        $report['year_totals']['total'],
    ]);

    fclose($out);
}

function buildOperationalReport(PDO $pdo, string $from, string $to): array
{
    $summaryStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN DATE(submitted_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS requests_submitted,
            SUM(CASE WHEN status = 'completed' AND DATE(updated_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS requests_completed
         FROM document_requests"
    );
    $summaryStmt->execute([$from, $to, $from, $to]);
    $requestSummary = $summaryStmt->fetch() ?: [];

    $apptSummaryStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN appointment_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS appointments_scheduled,
            SUM(CASE WHEN status = 'completed' AND appointment_date BETWEEN ? AND ? THEN 1 ELSE 0 END) AS appointments_completed
         FROM appointments"
    );
    $apptSummaryStmt->execute([$from, $to, $from, $to]);
    $apptSummary = $apptSummaryStmt->fetch() ?: [];

    $queueSummaryStmt = $pdo->prepare(
        "SELECT
            SUM(CASE WHEN status = 'completed' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS queue_served,
            SUM(CASE WHEN status = 'waiting' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS queue_waiting,
            SUM(CASE WHEN status = 'skipped' AND DATE(created_at) BETWEEN ? AND ? THEN 1 ELSE 0 END) AS queue_skipped
         FROM queue_tickets"
    );
    $queueSummaryStmt->execute([$from, $to, $from, $to, $from, $to]);
    $queueSummary = $queueSummaryStmt->fetch() ?: [];

    $statusRows = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM document_requests
         WHERE DATE(submitted_at) BETWEEN ? AND ?
         GROUP BY status ORDER BY cnt DESC"
    );
    $statusRows->execute([$from, $to]);
    $requestsByStatus = [];
    foreach ($statusRows->fetchAll() as $row) {
        $requestsByStatus[$row['status']] = (int) $row['cnt'];
    }

    $typeRows = $pdo->prepare(
        "SELECT document_type, COUNT(*) AS cnt FROM document_requests
         WHERE DATE(submitted_at) BETWEEN ? AND ?
         GROUP BY document_type ORDER BY cnt DESC"
    );
    $typeRows->execute([$from, $to]);
    $requestsByType = [];
    foreach ($typeRows->fetchAll() as $row) {
        $requestsByType[$row['document_type']] = (int) $row['cnt'];
    }

    $requestList = $pdo->prepare(
        "SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at, updated_at
         FROM document_requests
         WHERE DATE(submitted_at) BETWEEN ? AND ?
         ORDER BY submitted_at DESC"
    );
    $requestList->execute([$from, $to]);

    $apptStatusRows = $pdo->prepare(
        "SELECT status, COUNT(*) AS cnt FROM appointments
         WHERE appointment_date BETWEEN ? AND ?
         GROUP BY status ORDER BY cnt DESC"
    );
    $apptStatusRows->execute([$from, $to]);
    $appointmentsByStatus = [];
    foreach ($apptStatusRows->fetchAll() as $row) {
        $appointmentsByStatus[$row['status']] = (int) $row['cnt'];
    }

    $appointmentList = $pdo->prepare(
        "SELECT appointment_code, first_name, middle_name, last_name, service_type, appointment_date, appointment_time, status, source, created_at
         FROM appointments
         WHERE appointment_date BETWEEN ? AND ?
         ORDER BY appointment_date ASC, appointment_time ASC"
    );
    $appointmentList->execute([$from, $to]);

    $queuePurposeRows = $pdo->prepare(
        "SELECT purpose, COUNT(*) AS cnt FROM queue_tickets
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY purpose ORDER BY cnt DESC"
    );
    $queuePurposeRows->execute([$from, $to]);
    $queueByPurpose = [];
    foreach ($queuePurposeRows->fetchAll() as $row) {
        $queueByPurpose[$row['purpose']] = (int) $row['cnt'];
    }

    $queueList = $pdo->prepare(
        "SELECT ticket_number, purpose, status, first_name, middle_name, last_name, reference_code, window_number, created_at, called_at
         FROM queue_tickets
         WHERE DATE(created_at) BETWEEN ? AND ?
         ORDER BY created_at DESC"
    );
    $queueList->execute([$from, $to]);

    $activityList = $pdo->prepare(
        "SELECT staff_id, action, details, created_at
         FROM activity_logs
         WHERE DATE(created_at) BETWEEN ? AND ?
         ORDER BY created_at DESC
         LIMIT 500"
    );
    $activityList->execute([$from, $to]);

    return [
        'from' => $from,
        'to' => $to,
        'generated_at' => date('Y-m-d H:i:s'),
        'office_name' => getSetting('office_name', 'Local Civil Registrar Office'),
        'site_name' => getSetting('site_name', 'ALCROS'),
        'summary' => [
            'requests_submitted'   => (int) ($requestSummary['requests_submitted'] ?? 0),
            'requests_completed'   => (int) ($requestSummary['requests_completed'] ?? 0),
            'appointments_scheduled' => (int) ($apptSummary['appointments_scheduled'] ?? 0),
            'appointments_completed' => (int) ($apptSummary['appointments_completed'] ?? 0),
            'queue_served'         => (int) ($queueSummary['queue_served'] ?? 0),
            'queue_waiting'        => (int) ($queueSummary['queue_waiting'] ?? 0),
            'queue_skipped'        => (int) ($queueSummary['queue_skipped'] ?? 0),
            'pending_requests'     => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'")->fetchColumn(),
            'ready_for_pickup'     => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'ready'")->fetchColumn(),
            'total_records'        => (int) $pdo->query('SELECT COUNT(*) FROM civil_records WHERE deleted_at IS NULL')->fetchColumn(),
        ],
        'requests_by_status' => $requestsByStatus,
        'requests_by_type' => $requestsByType,
        'requests' => $requestList->fetchAll(),
        'appointments_by_status' => $appointmentsByStatus,
        'appointments' => $appointmentList->fetchAll(),
        'queue_by_purpose' => $queueByPurpose,
        'queue_tickets' => $queueList->fetchAll(),
        'activities' => $activityList->fetchAll(),
    ];
}

function reportSummaryMetricLabels(): array
{
    return [
        'requests_submitted'     => 'New document requests received',
        'requests_completed'     => 'Document requests marked completed',
        'appointments_scheduled' => 'Appointments scheduled',
        'appointments_completed' => 'Appointments completed',
        'queue_served'           => 'Citizens served from queue',
        'queue_waiting'          => 'Queue tickets still waiting',
        'queue_skipped'          => 'Queue no-shows (skipped)',
        'pending_requests'       => 'Pending requests right now',
        'ready_for_pickup'       => 'Documents ready for pickup right now',
        'total_records'          => 'Civil registry records on file',
    ];
}

function formatReportDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return 'Not recorded';
    }

    $ts = strtotime($value);

    return $ts ? date('M j, Y g:i A', $ts) : $value;
}

function formatReportTime(?string $time): string
{
    if ($time === null || trim($time) === '') {
        return 'Not set';
    }

    $ts = strtotime($time);

    return $ts ? date('g:i A', $ts) : $time;
}

function queuePurposeLabel(string $purpose): string
{
    return match ($purpose) {
        'walk_in'         => 'Walk-in visit',
        'appointment'     => 'Appointment check-in',
        'document_claim'  => 'Document pickup / claim',
        default           => ucwords(str_replace('_', ' ', $purpose)),
    };
}

function queueStatusLabel(string $status): string
{
    return match ($status) {
        'waiting'   => 'Waiting in line',
        'serving'   => 'Currently being served',
        'completed' => 'Served / completed',
        'skipped'   => 'No-show (skipped)',
        default     => ucfirst($status),
    };
}

function appointmentSourceLabel(string $source): string
{
    return $source === 'document_request'
        ? 'Document request visit'
        : 'Special service appointment';
}

function exportOperationalReportCsv(array $report, string $type): void
{
    $from = $report['from'];
    $to = $report['to'];
    $periodLabel = reportRangeLabel('custom', $from, $to);
    $filename = 'ALCROS_Operational_Report_' . $from . ($from !== $to ? '_to_' . $to : '') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    $writeSection = static function (string $title, ?string $description = null) use ($out): void {
        fputcsv($out, []);
        fputcsv($out, ['--- ' . strtoupper($title) . ' ---']);
        if ($description !== null && $description !== '') {
            fputcsv($out, [$description]);
        }
    };

    $writeEmpty = static function (string $message) use ($out): void {
        fputcsv($out, [$message]);
    };

    if ($type === 'full' || $type === 'summary') {
        fputcsv($out, ['ALCROS Operational Report']);
        fputcsv($out, ['Report title', 'Daily operations summary for the Local Civil Registry office']);
        fputcsv($out, ['System name', $report['site_name']]);
        fputcsv($out, ['Office', $report['office_name']]);
        fputcsv($out, ['Reporting period', $periodLabel]);
        fputcsv($out, ['Report generated on', formatReportDateTime($report['generated_at'])]);

        if ($type === 'full') {
            $writeSection('Report guide', 'This file is organized by section. Each section starts with a heading row, followed by column names, then the data rows.');
            fputcsv($out, ['Section order']);
            fputcsv($out, ['1', 'Summary — key counts for the selected period']);
            fputcsv($out, ['2', 'Document requests — status summary, type summary, and full request list']);
            fputcsv($out, ['3', 'Appointments — status summary and full appointment list']);
            fputcsv($out, ['4', 'Queue — purpose summary and ticket list']);
            fputcsv($out, ['5', 'Staff activity — actions logged by staff accounts']);
        }

        $writeSection('Summary', 'Headline numbers for the reporting period. Items marked “right now” show the current live count, not just the selected dates.');
        fputcsv($out, ['Description', 'Count']);
        foreach (reportSummaryMetricLabels() as $key => $label) {
            if (!array_key_exists($key, $report['summary'])) {
                continue;
            }
            fputcsv($out, [$label, $report['summary'][$key]]);
        }
    }

    if ($type === 'full' || $type === 'requests') {
        $writeSection('Document requests — status summary', 'How many requests fall under each processing status during the reporting period.');
        fputcsv($out, ['Request status', 'Number of requests']);
        if (empty($report['requests_by_status'])) {
            $writeEmpty('No document requests were submitted during this period.');
        } else {
            foreach ($report['requests_by_status'] as $status => $count) {
                fputcsv($out, [requestStatusLabel($status), $count]);
            }
        }

        $writeSection('Document requests — document type summary', 'Breakdown of certificate types requested during the reporting period.');
        fputcsv($out, ['Document type', 'Number of requests']);
        if (empty($report['requests_by_type'])) {
            $writeEmpty('No document types to show for this period.');
        } else {
            foreach ($report['requests_by_type'] as $docType => $count) {
                fputcsv($out, [documentTypeLabel($docType), $count]);
            }
        }

        $writeSection('Document requests — full list', 'One row per online document request submitted in the reporting period.');
        fputcsv($out, [
            'Tracking code',
            'Citizen full name',
            'Document requested',
            'Current status',
            'Date submitted',
            'Last status update',
        ]);
        if (empty($report['requests'])) {
            $writeEmpty('No document requests were submitted during this period.');
        } else {
            foreach ($report['requests'] as $row) {
                fputcsv($out, [
                    $row['tracking_code'],
                    personNameFromRow($row),
                    documentTypeLabel($row['document_type']),
                    requestStatusLabel($row['status']),
                    formatReportDateTime($row['submitted_at']),
                    formatReportDateTime($row['updated_at']),
                ]);
            }
        }
    }

    if ($type === 'full' || $type === 'appointments') {
        $writeSection('Appointments — status summary', 'How many visits are scheduled, confirmed, completed, or rejected in the reporting period.');
        fputcsv($out, ['Appointment status', 'Number of appointments']);
        if (empty($report['appointments_by_status'])) {
            $writeEmpty('No appointments were scheduled during this period.');
        } else {
            foreach ($report['appointments_by_status'] as $status => $count) {
                fputcsv($out, [appointmentStatusLabel($status), $count]);
            }
        }

        $writeSection('Appointments — full list', 'One row per appointment scheduled within the reporting period.');
        fputcsv($out, [
            'Appointment code',
            'Citizen full name',
            'Service or document',
            'Visit date',
            'Visit time',
            'Status',
            'Appointment type',
            'Date booked online',
        ]);
        if (empty($report['appointments'])) {
            $writeEmpty('No appointments were scheduled during this period.');
        } else {
            foreach ($report['appointments'] as $row) {
                fputcsv($out, [
                    $row['appointment_code'],
                    personNameFromRow($row),
                    appointmentServiceLabel($row['service_type']),
                    formatRecordDate($row['appointment_date']),
                    formatReportTime($row['appointment_time']),
                    appointmentStatusLabel($row['status']),
                    appointmentSourceLabel((string) ($row['source'] ?? '')),
                    formatReportDateTime($row['created_at']),
                ]);
            }
        }
    }

    if ($type === 'full' || $type === 'queue') {
        $writeSection('Queue — purpose summary', 'Why citizens took a queue number during the reporting period.');
        fputcsv($out, ['Queue purpose', 'Number of tickets']);
        if (empty($report['queue_by_purpose'])) {
            $writeEmpty('No queue tickets were created during this period.');
        } else {
            foreach ($report['queue_by_purpose'] as $purpose => $count) {
                fputcsv($out, [queuePurposeLabel($purpose), $count]);
            }
        }

        $writeSection('Queue tickets — full list', 'One row per queue ticket issued during the reporting period.');
        fputcsv($out, [
            'Ticket number',
            'Purpose of visit',
            'Ticket status',
            'Citizen name (if provided)',
            'Reference code (tracking / appointment)',
            'Service window / table',
            'Ticket issued on',
            'Called to window on',
        ]);
        if (empty($report['queue_tickets'])) {
            $writeEmpty('No queue tickets were created during this period.');
        } else {
            foreach ($report['queue_tickets'] as $row) {
                fputcsv($out, [
                    $row['ticket_number'],
                    queuePurposeLabel((string) $row['purpose']),
                    queueStatusLabel((string) $row['status']),
                    personNameFromRow($row) ?: 'Not provided',
                    $row['reference_code'] ?: 'None',
                    $row['window_number'] ? 'Window ' . $row['window_number'] : 'Not assigned',
                    formatReportDateTime($row['created_at']),
                    formatReportDateTime($row['called_at'] ?? null),
                ]);
            }
        }
    }

    if ($type === 'full' || $type === 'activity') {
        $writeSection('Staff activity log', 'Actions recorded from staff accounts during the reporting period (latest 500 entries).');
        fputcsv($out, [
            'Staff account ID',
            'Action performed',
            'Additional details',
            'Date and time',
        ]);
        if (empty($report['activities'])) {
            $writeEmpty('No staff activity was logged during this period.');
        } else {
            foreach ($report['activities'] as $row) {
                fputcsv($out, [
                    $row['staff_id'] ?: 'System',
                    $row['action'],
                    $row['details'] ?: 'No extra details',
                    formatReportDateTime($row['created_at']),
                ]);
            }
        }
    }

    fputcsv($out, []);
    fputcsv($out, ['End of report']);

    fclose($out);
}
