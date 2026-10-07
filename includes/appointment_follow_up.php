<?php
/**
 * Follow-up reminders after completed special-service appointments.
 */

require_once __DIR__ . '/appointment_notice_requirements.php';
require_once __DIR__ . '/sms.php';

/** 0 = send email/SMS on the follow-up date staff set (same calendar day). */
const APPOINTMENT_FOLLOW_UP_REMINDER_DAYS = 0;

/** Local office hour (24h) when follow-up reminders may start on the follow-up date. */
const APPOINTMENT_FOLLOW_UP_REMINDER_HOUR = 8;

function followUpRemindersAllowedNow(): bool
{
    return (int) date('G') >= APPOINTMENT_FOLLOW_UP_REMINDER_HOUR;
}

function ensureAppointmentFollowUpsTable(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS appointment_follow_ups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            appointment_id INT NOT NULL,
            follow_up_date DATE NOT NULL,
            staff_note TEXT DEFAULT NULL,
            status ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'pending',
            cancel_reason VARCHAR(32) DEFAULT NULL,
            cancelled_appointment_id INT DEFAULT NULL,
            email VARCHAR(150) DEFAULT NULL,
            phone VARCHAR(30) DEFAULT NULL,
            notify_email TINYINT(1) NOT NULL DEFAULT 0,
            notify_sms TINYINT(1) NOT NULL DEFAULT 0,
            service_type VARCHAR(100) NOT NULL DEFAULT '',
            service_slug VARCHAR(80) DEFAULT NULL,
            reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
            sms_reminder_sent_at TIMESTAMP NULL DEFAULT NULL,
            created_by VARCHAR(50) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_follow_up_date (follow_up_date, status),
            INDEX idx_status (status),
            INDEX idx_appointment (appointment_id),
            INDEX idx_email (email),
            CONSTRAINT fk_follow_up_appointment
                FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB"
    );
}

function appointmentAllowsFollowUp(array $appointmentRow): bool
{
    return isStandaloneSpecialServiceAppointmentRow($appointmentRow);
}

function appointmentFollowUpBookUrl(string $serviceSlug): string
{
    $base = rtrim(appBaseUrl(), '/') . '/book_appointment.php';
    $serviceSlug = trim($serviceSlug);
    if ($serviceSlug === '') {
        return $base;
    }

    return $base . '?service=' . rawurlencode($serviceSlug);
}

/** @return array<string, mixed>|null */
function fetchAppointmentRowForFollowUp(PDO $pdo, int $appointmentId): ?array
{
    if ($appointmentId <= 0) {
        return null;
    }

    ensureSoftDeleteColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT * FROM appointments WHERE id = ? AND deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return array<string, mixed>|null */
function fetchPendingFollowUpByAppointmentId(PDO $pdo, int $appointmentId): ?array
{
    ensureAppointmentFollowUpsTable($pdo);
    if ($appointmentId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT * FROM appointment_follow_ups
         WHERE appointment_id = ? AND status = 'pending'
         ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return array<string, mixed>|null */
function fetchFollowUpById(PDO $pdo, int $followUpId): ?array
{
    ensureAppointmentFollowUpsTable($pdo);
    if ($followUpId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM appointment_follow_ups WHERE id = ? LIMIT 1');
    $stmt->execute([$followUpId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function appointmentFollowUpDateFromInterval(string $interval, ?string $baseDate = null): ?string
{
    $base = $baseDate ?? alcrosTodayDate();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $base)) {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($base);
    } catch (Throwable $e) {
        return null;
    }

    $dt = match ($interval) {
        '1m' => $dt->modify('+1 month'),
        '3m' => $dt->modify('+3 months'),
        '6m' => $dt->modify('+6 months'),
        default => null,
    };
    if ($dt === null) {
        return null;
    }

    return $dt->format('Y-m-d');
}

function validateFollowUpDate(string $followUpDate): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $followUpDate)) {
        return 'Choose a valid follow-up date.';
    }
    if ($followUpDate <= alcrosTodayDate()) {
        return 'Follow-up date must be after today.';
    }

    $blockReason = officeDateBlockReason($followUpDate);
    if ($blockReason === 'weekend') {
        return 'Follow-up date cannot fall on a weekend.';
    }
    if ($blockReason === 'holiday') {
        return 'Follow-up date cannot fall on a holiday.';
    }
    if ($blockReason !== null) {
        return 'Choose a valid follow-up date.';
    }

    return null;
}

/** @return array{0: string, 1: array<string, mixed>} */
function snapshotFollowUpCitizenFromAppointment(array $appointment): array
{
    $serviceType = (string) ($appointment['service_type'] ?? '');
    $slug = appointmentServiceRequirementsSlugFromServiceType($serviceType);

    return [
        'email'         => normalizeGmail(trim((string) ($appointment['email'] ?? ''))),
        'phone'         => normalizeSmsPhone(trim((string) ($appointment['phone'] ?? '')))
            ?: trim((string) ($appointment['phone'] ?? '')),
        'notify_email'  => (int) ($appointment['notify_email'] ?? 0),
        'notify_sms'    => (int) ($appointment['notify_sms'] ?? 0),
        'service_type'  => $serviceType,
        'service_slug'  => $slug !== '' ? $slug : null,
    ];
}

function saveAppointmentFollowUp(
    PDO $pdo,
    int $appointmentId,
    string $followUpDate,
    string $staffNote,
    string $createdBy
): array {
    ensureAppointmentFollowUpsTable($pdo);
    ensureSoftDeleteColumns($pdo);

    $appointment = fetchAppointmentRowForFollowUp($pdo, $appointmentId);
    if (!$appointment) {
        return ['ok' => false, 'message' => 'Appointment not found.'];
    }
    if (($appointment['status'] ?? '') !== 'completed') {
        return ['ok' => false, 'message' => 'Follow-up can only be set after the visit is marked completed.'];
    }
    if (!appointmentAllowsFollowUp($appointment)) {
        return ['ok' => false, 'message' => 'Follow-up reminders apply to special service appointments only.'];
    }

    $dateError = validateFollowUpDate($followUpDate);
    if ($dateError !== null) {
        return ['ok' => false, 'message' => $dateError];
    }

    $snapshot = snapshotFollowUpCitizenFromAppointment($appointment);
    $staffNote = trim($staffNote);
    $noteDb = $staffNote !== '' ? $staffNote : null;

    $existing = fetchPendingFollowUpByAppointmentId($pdo, $appointmentId);
    if ($existing) {
        $stmt = $pdo->prepare(
            "UPDATE appointment_follow_ups
             SET follow_up_date = ?, staff_note = ?, email = ?, phone = ?, notify_email = ?, notify_sms = ?,
                 service_type = ?, service_slug = ?, reminder_sent_at = NULL, sms_reminder_sent_at = NULL,
                 updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([
            $followUpDate,
            $noteDb,
            $snapshot['email'] !== '' ? $snapshot['email'] : null,
            $snapshot['phone'] !== '' ? $snapshot['phone'] : null,
            $snapshot['notify_email'],
            $snapshot['notify_sms'],
            $snapshot['service_type'],
            $snapshot['service_slug'],
            (int) $existing['id'],
        ]);

        return ['ok' => true, 'message' => 'Follow-up reminder updated.', 'id' => (int) $existing['id']];
    }

    $stmt = $pdo->prepare(
        "INSERT INTO appointment_follow_ups
         (appointment_id, follow_up_date, staff_note, email, phone, notify_email, notify_sms,
          service_type, service_slug, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $appointmentId,
        $followUpDate,
        $noteDb,
        $snapshot['email'] !== '' ? $snapshot['email'] : null,
        $snapshot['phone'] !== '' ? $snapshot['phone'] : null,
        $snapshot['notify_email'],
        $snapshot['notify_sms'],
        $snapshot['service_type'],
        $snapshot['service_slug'],
        $createdBy !== '' ? $createdBy : null,
    ]);

    return ['ok' => true, 'message' => 'Follow-up reminder saved.', 'id' => (int) $pdo->lastInsertId()];
}

function cancelAppointmentFollowUp(PDO $pdo, int $followUpId, string $reason = 'staff'): array
{
    ensureAppointmentFollowUpsTable($pdo);
    $row = fetchFollowUpById($pdo, $followUpId);
    if (!$row || ($row['status'] ?? '') !== 'pending') {
        return ['ok' => false, 'message' => 'No pending follow-up to cancel.'];
    }

    $stmt = $pdo->prepare(
        "UPDATE appointment_follow_ups
         SET status = 'cancelled', cancel_reason = ?, updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$reason, $followUpId]);

    return ['ok' => $stmt->rowCount() > 0, 'message' => 'Follow-up reminder cancelled.'];
}

function completeAppointmentFollowUp(PDO $pdo, int $followUpId): array
{
    ensureAppointmentFollowUpsTable($pdo);
    $stmt = $pdo->prepare(
        "UPDATE appointment_follow_ups
         SET status = 'completed', updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    );
    $stmt->execute([$followUpId]);

    return [
        'ok'      => $stmt->rowCount() > 0,
        'message' => $stmt->rowCount() > 0 ? 'Follow-up marked completed.' : 'No pending follow-up to complete.',
    ];
}

function cancelPendingFollowUpsForNewBooking(PDO $pdo, ?string $email, ?string $phone, int $newAppointmentId): int
{
    ensureAppointmentFollowUpsTable($pdo);
    $email = normalizeGmail(trim((string) $email));
    $rawPhone = trim((string) $phone);
    $phoneNorm = normalizeSmsPhone($rawPhone);
    if ($email === '' && $phoneNorm === '' && $rawPhone === '') {
        return 0;
    }

    $clauses = [];
    $params = [$newAppointmentId > 0 ? $newAppointmentId : null];
    if ($email !== '') {
        $clauses[] = 'email = ?';
        $params[] = $email;
    }
    if ($phoneNorm !== '') {
        $clauses[] = 'phone = ?';
        $params[] = $phoneNorm;
    }
    if ($rawPhone !== '' && $rawPhone !== $phoneNorm) {
        $clauses[] = 'phone = ?';
        $params[] = $rawPhone;
    }

    if ($clauses === []) {
        return 0;
    }

    $sql = "UPDATE appointment_follow_ups
            SET status = 'cancelled', cancel_reason = 'booked_early',
                cancelled_appointment_id = ?, updated_at = NOW()
            WHERE status = 'pending' AND (" . implode(' OR ', $clauses) . ')';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->rowCount();
}

function countPendingAppointmentFollowUps(PDO $pdo): int
{
    ensureAppointmentFollowUpsTable($pdo);

    return (int) $pdo->query(
        "SELECT COUNT(*) FROM appointment_follow_ups
         WHERE status = 'pending' AND follow_up_date >= CURDATE()"
    )->fetchColumn();
}

/** @return list<array<string, mixed>> */
function fetchUpcomingFollowUps(PDO $pdo, int $limit = 200): array
{
    ensureAppointmentFollowUpsTable($pdo);
    ensureSoftDeleteColumns($pdo);

    $limit = max(1, min(500, $limit));
    $stmt = $pdo->query(
        "SELECT f.*, a.appointment_code, a.first_name, a.middle_name, a.last_name,
                a.appointment_date AS visit_date, a.appointment_time AS visit_time
         FROM appointment_follow_ups f
         INNER JOIN appointments a ON a.id = f.appointment_id AND a.deleted_at IS NULL
         WHERE f.status = 'pending' AND f.follow_up_date >= CURDATE()
         ORDER BY f.follow_up_date ASC, f.id ASC
         LIMIT {$limit}"
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param array<string, mixed>|null $row */
function appointmentFollowUpViewData(?array $row): ?array
{
    if (!$row) {
        return null;
    }

    return [
        'id'               => (int) ($row['id'] ?? 0),
        'appointment_id'   => (int) ($row['appointment_id'] ?? 0),
        'follow_up_date'   => formatDateDisplay((string) ($row['follow_up_date'] ?? '')),
        'follow_up_date_iso' => (string) ($row['follow_up_date'] ?? ''),
        'staff_note'       => !empty($row['staff_note']) ? (string) $row['staff_note'] : '',
        'status'           => (string) ($row['status'] ?? 'pending'),
        'reminder_sent'    => !empty($row['reminder_sent_at']),
        'service_type'     => appointmentServiceLabel((string) ($row['service_type'] ?? '')),
    ];
}

function notifyFollowUpReminderEmail(array $row): bool
{
    if (empty($row['notify_email']) || empty($row['email'])) {
        return false;
    }
    if (!filter_var((string) $row['email'], FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $slug = trim((string) ($row['service_slug'] ?? ''));
    if ($slug === '') {
        $slug = appointmentServiceRequirementsSlugFromServiceType((string) ($row['service_type'] ?? ''));
    }

    $bookUrl = appointmentFollowUpBookUrl($slug);
    $followUpDisplay = formatDateDisplay((string) ($row['follow_up_date'] ?? ''));
    $serviceLabel = appointmentServiceLabel((string) ($row['service_type'] ?? ''));

    $details = [
        'Service'           => $serviceLabel,
        'Follow-up date'    => $followUpDisplay,
    ];

    $note = 'Our office set this date for your next visit. Please book an appointment when you are ready.';
    $staffNote = trim((string) ($row['staff_note'] ?? ''));
    if ($staffNote !== '') {
        $note .= "\n\nNote from the office: " . $staffNote;
    }
    $note .= "\n\nUse the button below to choose a time slot online.";

    $nameRow = [
        'first_name'  => (string) ($row['first_name'] ?? ''),
        'middle_name' => (string) ($row['middle_name'] ?? ''),
        'last_name'   => (string) ($row['last_name'] ?? ''),
    ];

    return sendCitizenNotice(
        (string) $row['email'],
        'ALCROS — Time to schedule your next visit',
        [
            'heading'      => 'Follow-up visit reminder',
            'name'         => personNameFromRow($nameRow),
            'intro'        => 'This is your follow-up reminder for the date our office recommended for your next visit.',
            'code_label'   => 'Previous appointment',
            'code'         => (string) ($row['appointment_code'] ?? ''),
            'details'      => $details,
            'note'         => $note,
            'button_label' => 'Book appointment',
            'button_url'   => $bookUrl,
            'accent'       => '#2563eb',
        ],
        'follow_up_reminder'
    );
}

function sendDueFollowUpReminders(PDO $pdo): int
{
    ensureAppointmentFollowUpsTable($pdo);
    if (!followUpRemindersAllowedNow()) {
        return 0;
    }

    $days = APPOINTMENT_FOLLOW_UP_REMINDER_DAYS;
    $sent = 0;

    $stmt = $pdo->prepare(
        "SELECT f.*, a.appointment_code, a.first_name, a.middle_name, a.last_name
         FROM appointment_follow_ups f
         INNER JOIN appointments a ON a.id = f.appointment_id AND a.deleted_at IS NULL
         WHERE f.status = 'pending'
           AND f.follow_up_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
           AND CURTIME() >= ?
           AND (f.notify_email = 1 OR f.notify_sms = 1)"
    );
    $sendAfter = sprintf('%02d:00:00', APPOINTMENT_FOLLOW_UP_REMINDER_HOUR);
    $stmt->execute([$days, $sendAfter]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        if (!empty($row['notify_email']) && empty($row['reminder_sent_at'])) {
            $claim = $pdo->prepare(
                "UPDATE appointment_follow_ups SET reminder_sent_at = NOW()
                 WHERE id = ? AND reminder_sent_at IS NULL"
            );
            $claim->execute([$id]);
            if ($claim->rowCount() > 0) {
                if (notifyFollowUpReminderEmail($row)) {
                    $sent++;
                } else {
                    $pdo->prepare(
                        'UPDATE appointment_follow_ups SET reminder_sent_at = NULL WHERE id = ?'
                    )->execute([$id]);
                }
            }
        }

        if (!empty($row['notify_sms']) && empty($row['sms_reminder_sent_at'])) {
            require_once __DIR__ . '/sms.php';
            $claimSms = $pdo->prepare(
                "UPDATE appointment_follow_ups SET sms_reminder_sent_at = NOW()
                 WHERE id = ? AND sms_reminder_sent_at IS NULL"
            );
            $claimSms->execute([$id]);
            if ($claimSms->rowCount() > 0) {
                if (notifyFollowUpReminderSms($row)) {
                    if (empty($row['notify_email']) || !empty($row['reminder_sent_at'])) {
                        $sent++;
                    }
                } else {
                    $pdo->prepare(
                        'UPDATE appointment_follow_ups SET sms_reminder_sent_at = NULL WHERE id = ?'
                    )->execute([$id]);
                }
            }
        }
    }

    return $sent;
}
