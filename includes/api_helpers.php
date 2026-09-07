<?php

require_once __DIR__ . '/helpers.php';

function apiJsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    if (!isset($data['ok'])) {
        $data['ok'] = true;
    }
    if (!isset($data['updated_at'])) {
        $data['updated_at'] = date('c');
    }
    echo json_encode($data);
    exit;
}

function apiError(string $message, int $code = 400): void
{
    apiJsonResponse(['ok' => false, 'error' => $message], $code);
}

function queuePurposeLabels(): array
{
    $labels = [];
    foreach (queuePurposeConfig() as $key => $cfg) {
        $labels[$key] = $cfg['label'];
    }
    return $labels;
}

function fetchTodayQueueActiveRows(PDO $pdo): array
{
    static $cache = null;
    static $cachePdo = null;
    if ($cache !== null && $cachePdo === $pdo) {
        return $cache;
    }

    ensureQueuePerformanceIndexes($pdo);
    $stmt = $pdo->query(
        "SELECT id, ticket_number, purpose, status, window_number, first_name, middle_name, last_name, reference_code, created_at, called_at
         FROM queue_tickets
         WHERE created_at >= CURDATE() AND created_at < CURDATE() + INTERVAL 1 DAY
           AND status IN ('waiting', 'serving')
         ORDER BY FIELD(purpose,'walk_in','appointment','document_claim'), FIELD(status,'serving','waiting'), created_at ASC"
    );
    $cache = enrichCitizenNameRows($stmt ? $stmt->fetchAll() : []);
    $cachePdo = $pdo;

    return $cache;
}

function queueStateRevision(array $rows): string
{
    $maxTs = 0;
    $active = 0;
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'waiting' || ($row['status'] ?? '') === 'serving') {
            $active++;
        }
        foreach (['called_at', 'created_at'] as $column) {
            if (empty($row[$column])) {
                continue;
            }
            $ts = strtotime((string) $row[$column]);
            if ($ts !== false && $ts > $maxTs) {
                $maxTs = $ts;
            }
        }
    }

    return $maxTs . ':' . $active;
}

function buildQueueGroupedFromRows(array $rows): array
{
    $grouped = [];
    foreach (array_keys(queuePurposeConfig()) as $purpose) {
        $grouped[$purpose] = [
            'serving' => null,
            'waiting' => [],
        ];
    }

    foreach ($rows as $row) {
        $purpose = (string) ($row['purpose'] ?? '');
        if (!isset($grouped[$purpose])) {
            continue;
        }
        if (($row['status'] ?? '') === 'serving' && $grouped[$purpose]['serving'] === null) {
            $grouped[$purpose]['serving'] = $row;
        } elseif (($row['status'] ?? '') === 'waiting') {
            $grouped[$purpose]['waiting'][] = $row;
        }
    }

    return $grouped;
}

function fetchQueueTicketsGrouped(PDO $pdo): array
{
    return buildQueueGroupedFromRows(fetchTodayQueueActiveRows($pdo));
}

function fetchPublicQueueDisplay(PDO $pdo): array
{
    $rows = fetchTodayQueueActiveRows($pdo);
    $grouped = buildQueueGroupedFromRows($rows);

    $serving = null;
    foreach ($rows as $row) {
        if (($row['status'] ?? '') !== 'serving') {
            continue;
        }
        if ($serving === null || (string) ($row['called_at'] ?? '') > (string) ($serving['called_at'] ?? '')) {
            $serving = $row;
        }
    }

    $tables = [];
    foreach (queuePurposeConfig() as $purpose => $cfg) {
        $slot = $grouped[$purpose];
        $tables[$purpose] = [
            'table'   => $cfg['table'],
            'label'   => $cfg['label'],
            'serving' => $slot['serving'] ? $slot['serving']['ticket_number'] : null,
            'waiting' => array_column($slot['waiting'], 'ticket_number'),
        ];
    }

    $waiting = [];
    foreach ($rows as $row) {
        if (($row['status'] ?? '') === 'waiting') {
            $waiting[] = $row['ticket_number'];
            if (count($waiting) >= 8) {
                break;
            }
        }
    }

    return [
        'serving' => $serving ? [
            'ticket_number'  => $serving['ticket_number'],
            'window_number'  => $serving['window_number'],
            'purpose'        => $serving['purpose'],
            'called_at'      => $serving['called_at'] ?? null,
        ] : null,
        'waiting' => $waiting,
        'tables'  => $tables,
    ];
}

function fetchQueueSnapshot(PDO $pdo, string $mode = 'full'): array
{
    $rows = fetchTodayQueueActiveRows($pdo);
    $revision = queueStateRevision($rows);
    $payload = [
        'revision' => $revision,
    ];

    if ($mode === 'display') {
        $payload['display'] = fetchPublicQueueDisplay($pdo);
        return $payload;
    }

    $payload['grouped'] = buildQueueGroupedFromRows($rows);
    $payload['tables'] = queuePurposeConfig();

    return $payload;
}

function fetchDashboardStats(PDO $pdo, bool $isAdmin, string $staffId): array
{
    $todayDate = alcrosTodayDate();
    $stats = [
        'pending_count'   => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status IN ('pending','verified')")->fetchColumn(),
        'queue_count'     => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn(),
        'today_appts'     => countTodaySpecialAppointments($pdo),
        'pipeline_count'  => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'verified'")->fetchColumn(),
        'ready_count'     => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'ready'")->fetchColumn(),
        'completed_today' => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'completed' AND DATE(updated_at) = CURDATE()")->fetchColumn(),
    ];

    $recentRequests = enrichCitizenNameRows($pdo->query(
        "SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at
         FROM document_requests ORDER BY submitted_at DESC LIMIT 5"
    )->fetchAll());

    if ($isAdmin) {
        $activities = $pdo->query(
            "SELECT staff_id, action, details, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 4"
        )->fetchAll();
    } else {
        $stmt = $pdo->prepare(
            "SELECT staff_id, action, details, created_at FROM activity_logs WHERE staff_id = ? ORDER BY created_at DESC LIMIT 4"
        );
        $stmt->execute([$staffId]);
        $activities = $stmt->fetchAll();
    }

    $todayAppts = enrichCitizenNameRows($pdo->query(
        "SELECT first_name, middle_name, last_name, appointment_time, service_type, status FROM appointments
         WHERE appointment_date = CURDATE() AND status NOT IN ('cancelled', 'no_show')
         ORDER BY appointment_time ASC LIMIT 8"
    )->fetchAll());

    return [
        'stats'           => $stats,
        'recent_requests' => $recentRequests,
        'activities'      => $activities,
        'today_appts'     => $todayAppts,
        'incoming_appts'  => fetchIncomingAppointments($pdo, 8),
        'incoming_date'   => incomingAppointmentsDate(),
        'today_date'      => $todayDate,
    ];
}

function fetchScheduleDatesInMonth(PDO $pdo, string $yearMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        $yearMonth = date('Y-m');
    }

    $start = $yearMonth . '-01';
    $end = date('Y-m-t', strtotime($start));
    $map = [];

    $stmt = $pdo->prepare(
        "SELECT a.appointment_date, COUNT(*) AS cnt
         FROM appointments a
         WHERE a.appointment_date BETWEEN ? AND ?
           AND " . scheduleVisitAppointmentSql('a') . "
           AND a.deleted_at IS NULL
         GROUP BY a.appointment_date"
    );
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['appointment_date']] = (int) $row['cnt'];
    }

    $stmt = $pdo->prepare(
        "SELECT dr.appointment_date, COUNT(*) AS cnt
         FROM document_requests dr
         WHERE dr.appointment_date BETWEEN ? AND ?
           AND dr.appointment_time IS NOT NULL
           AND dr.appointment_time != ''
           AND dr.deleted_at IS NULL
           AND dr.status NOT IN ('rejected', 'completed')
           AND NOT EXISTS (
                SELECT 1 FROM appointments a
                WHERE a.tracking_code = dr.tracking_code
                  AND a.deleted_at IS NULL
                  AND a.appointment_date = dr.appointment_date
           )
         GROUP BY dr.appointment_date"
    );
    $stmt->execute([$start, $end]);
    foreach ($stmt->fetchAll() as $row) {
        $date = (string) $row['appointment_date'];
        $map[$date] = ($map[$date] ?? 0) + (int) $row['cnt'];
    }

    return $map;
}

function enrichScheduleVisitRow(array $row): array
{
    if (!empty($row['tracking_code']) || ($row['source'] ?? '') === 'document_request') {
        $row['schedule_kind'] = 'certificate';
        $row['service_type'] = trim(appointmentServiceLabel((string) ($row['service_type'] ?? '')));
        if ($row['service_type'] === '') {
            $row['service_type'] = 'Certificate';
        }
        if (!str_contains($row['service_type'], 'Pickup')) {
            $row['service_type'] .= ' · Pickup';
        }
        $row['status'] = appointmentDisplayStatusLabel($row);
    } else {
        $row['schedule_kind'] = 'appointment';
        $row['service_type'] = appointmentServiceLabel((string) ($row['service_type'] ?? ''));
        $row['status'] = appointmentStatusLabel((string) ($row['status'] ?? 'scheduled'));
    }

    return $row;
}

function scheduleVisitLinkParams(array $row, string $scheduleDate): array
{
    if (($row['schedule_kind'] ?? '') === 'certificate' || !empty($row['tracking_code'])) {
        $code = trim((string) ($row['tracking_code'] ?? ''));
        if ($code !== '') {
            return ['manage_request.php', ['q' => $code]];
        }
    }

    $code = trim((string) ($row['appointment_code'] ?? ''));
    $date = (string) ($row['appointment_date'] ?? $scheduleDate);
    $query = ['date' => $date];
    if ($code !== '') {
        $query['q'] = $code;
    }

    return ['appointment.php', $query];
}

function scheduleVisitHref(array $row, string $scheduleDate): string
{
    [$path, $query] = scheduleVisitLinkParams($row, $scheduleDate);

    return $path . '?' . http_build_query($query);
}

function attachScheduleVisitMeta(array $row, string $scheduleDate): array
{
    $row['href'] = scheduleVisitHref($row, $scheduleDate);

    return $row;
}

function fetchScheduleVisits(PDO $pdo, string $date, int $limit = 8): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    $rows = [];

    $stmt = $pdo->prepare(
        "SELECT a.appointment_code, a.appointment_date, a.first_name, a.middle_name, a.last_name,
                a.appointment_time, a.service_type, a.status, a.source, a.tracking_code
         FROM appointments a
         WHERE a.appointment_date = ?
           AND " . scheduleVisitAppointmentSql('a') . "
           AND a.deleted_at IS NULL
         ORDER BY a.appointment_time ASC"
    );
    $stmt->execute([$date]);
    foreach (enrichCitizenNameRows($stmt->fetchAll()) as $row) {
        $rows[] = attachScheduleVisitMeta(enrichScheduleVisitRow($row), $date);
    }

    $stmt = $pdo->prepare(
        "SELECT dr.tracking_code, dr.first_name, dr.middle_name, dr.last_name,
                dr.appointment_date, dr.appointment_time, dr.document_type, dr.status
         FROM document_requests dr
         WHERE dr.appointment_date = ?
           AND dr.appointment_time IS NOT NULL
           AND dr.appointment_time != ''
           AND dr.deleted_at IS NULL
           AND dr.status NOT IN ('rejected', 'completed')
           AND NOT EXISTS (
                SELECT 1 FROM appointments a
                WHERE a.tracking_code = dr.tracking_code
                  AND a.deleted_at IS NULL
                  AND a.appointment_date = dr.appointment_date
           )
         ORDER BY dr.appointment_time ASC"
    );
    $stmt->execute([$date]);
    foreach (enrichCitizenNameRows($stmt->fetchAll()) as $req) {
        $rows[] = attachScheduleVisitMeta([
            'citizen_name'     => $req['citizen_name'],
            'appointment_time' => $req['appointment_time'],
            'appointment_date' => $req['appointment_date'],
            'service_type'     => documentTypeLabel((string) ($req['document_type'] ?? '')) . ' · Pickup',
            'status'           => requestStatusLabel(normalizeRequestStatus((string) ($req['status'] ?? 'pending'))),
            'schedule_kind'    => 'certificate',
            'tracking_code'    => $req['tracking_code'] ?? '',
        ], $date);
    }

    usort($rows, static function (array $a, array $b): int {
        return strcmp((string) ($a['appointment_time'] ?? ''), (string) ($b['appointment_time'] ?? ''));
    });

    return array_slice($rows, 0, max(0, $limit));
}

function fetchDashboardSchedule(PDO $pdo, string $scheduleDate, string $calendarMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $scheduleDate)) {
        $scheduleDate = date('Y-m-d');
    }
    if (!preg_match('/^\d{4}-\d{2}$/', $calendarMonth)) {
        $calendarMonth = date('Y-m', strtotime($scheduleDate));
    }

    return [
        'month'         => $calendarMonth,
        'dates'         => fetchScheduleDatesInMonth($pdo, $calendarMonth),
        'schedule_date' => $scheduleDate,
        'appointments'  => fetchScheduleVisits($pdo, $scheduleDate, 8),
    ];
}

function fetchAppointments(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_code, a.first_name, a.middle_name, a.last_name, a.service_type,
                a.appointment_time, a.status
         FROM appointments a
         WHERE a.appointment_date = ?
           AND a.status NOT IN ('cancelled', 'no_show')
           AND " . appointmentStandaloneSql('a') . "
           AND " . appointmentActiveSql('a') . "
         ORDER BY a.appointment_time ASC"
    );
    $stmt->execute([$date]);
    return enrichCitizenNameRows($stmt->fetchAll());
}

function fetchIncomingAppointments(PDO $pdo, int $limit = 8): array
{
    return fetchScheduleVisits($pdo, incomingAppointmentsDate(), $limit);
}

function incomingAppointmentsDate(): string
{
    return date('Y-m-d', strtotime(alcrosTodayDate() . ' +1 day'));
}

function documentTypeLabelsMap(): array
{
    return [
        'birth'    => 'Birth Certificate',
        'death'    => 'Death Certificate',
        'marriage' => 'Marriage Certificate',
        'cenomar'  => 'CENOMAR',
    ];
}

function fetchNotifications(PDO $pdo, int $limit = 20): array
{
    $items = [];
    $docLabels = documentTypeLabelsMap();

    $pending = enrichCitizenNameRows($pdo->query(
        "SELECT tracking_code, first_name, middle_name, last_name, document_type, submitted_at
         FROM document_requests WHERE status = 'pending'
         ORDER BY submitted_at DESC LIMIT 5"
    )->fetchAll());
    foreach ($pending as $row) {
        $items[] = [
            'id'         => 'req-pending-' . $row['tracking_code'],
            'type'       => 'pending_request',
            'title'      => 'Pending request',
            'message'    => $row['citizen_name'] . ' · ' . ($docLabels[$row['document_type']] ?? $row['document_type']),
            'detail'     => $row['tracking_code'],
            'created_at' => $row['submitted_at'],
            'href'       => 'manage_request.php',
        ];
    }

    $queueCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    if ($queueCount > 0) {
        $latestQueue = $pdo->query(
            "SELECT created_at FROM queue_tickets
             WHERE status = 'waiting' AND DATE(created_at) = CURDATE()
             ORDER BY created_at DESC LIMIT 1"
        )->fetchColumn();
        $items[] = [
            'id'         => 'queue-waiting-' . date('Y-m-d'),
            'type'       => 'queue',
            'title'      => 'Queue alert',
            'message'    => $queueCount . ' citizen(s) waiting in line',
            'detail'     => 'Live queue',
            'created_at' => $latestQueue ?: date('Y-m-d H:i:s'),
            'href'       => 'live-queue.php',
        ];
    }

    $appts = enrichCitizenNameRows($pdo->query(
        "SELECT appointment_code, first_name, middle_name, last_name, service_type,
                appointment_time, appointment_date, created_at
         FROM appointments
         WHERE status IN ('scheduled', 'confirmed')
           AND COALESCE(source, 'standalone') = 'standalone'
           AND (tracking_code IS NULL OR tracking_code = '')
           AND (
                created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                OR appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
           )
         ORDER BY created_at DESC
         LIMIT 10"
    )->fetchAll());
    foreach ($appts as $row) {
        $timeLabel = date('g:i A', strtotime($row['appointment_time']));
        $dateLabel = formatDateDisplay($row['appointment_date']);
        $isRecent = strtotime($row['created_at']) >= strtotime('-48 hours');
        $isToday = $row['appointment_date'] === date('Y-m-d');
        $items[] = [
            'id'         => 'appt-' . $row['appointment_code'],
            'type'       => 'appointment',
            'title'      => $isRecent ? 'New appointment booked' : ($isToday ? 'Today\'s appointment' : 'Upcoming appointment'),
            'message'    => $row['citizen_name'] . ' · ' . appointmentServiceLabel($row['service_type']) . ' · ' . $dateLabel . ' ' . $timeLabel,
            'detail'     => $row['appointment_code'],
            'created_at' => $row['created_at'],
            'href'       => 'appointment.php?date=' . $row['appointment_date'],
        ];
    }

    usort($items, static function (array $a, array $b): int {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    return array_slice($items, 0, $limit);
}
