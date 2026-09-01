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

function fetchQueueStats(PDO $pdo): array
{
    $stats = [
        'waiting'   => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn(),
        'serving'   => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'serving' AND DATE(created_at) = CURDATE()")->fetchColumn(),
        'completed' => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'completed' AND DATE(created_at) = CURDATE()")->fetchColumn(),
    ];
    foreach (array_keys(queuePurposeConfig()) as $purpose) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND purpose = ? AND DATE(created_at) = CURDATE()"
        );
        $stmt->execute([$purpose]);
        $stats['waiting_' . $purpose] = (int) $stmt->fetchColumn();
    }
    return $stats;
}

function fetchActiveQueueTickets(PDO $pdo, string $filter = 'all'): array
{
    $sql = "SELECT id, ticket_number, purpose, status, window_number, first_name, middle_name, last_name, reference_code, created_at, called_at
            FROM queue_tickets
            WHERE DATE(created_at) = CURDATE() AND status IN ('waiting','serving')";
    if (in_array($filter, ['walk_in', 'appointment', 'document_claim'], true)) {
        $sql .= ' AND purpose = ' . $pdo->quote($filter);
    }
    $sql .= " ORDER BY FIELD(purpose,'walk_in','appointment','document_claim'), FIELD(status,'serving','waiting'), created_at ASC";
    return enrichCitizenNameRows($pdo->query($sql)->fetchAll());
}

function fetchQueueTicketsGrouped(PDO $pdo): array
{
    $grouped = [];
    foreach (array_keys(queuePurposeConfig()) as $purpose) {
        $grouped[$purpose] = [
            'serving' => null,
            'waiting' => [],
        ];
    }
    $tickets = fetchActiveQueueTickets($pdo, 'all');
    foreach ($tickets as $t) {
        $p = $t['purpose'];
        if (!isset($grouped[$p])) {
            continue;
        }
        if ($t['status'] === 'serving' && $grouped[$p]['serving'] === null) {
            $grouped[$p]['serving'] = $t;
        } elseif ($t['status'] === 'waiting') {
            $grouped[$p]['waiting'][] = $t;
        }
    }
    return $grouped;
}

function fetchPublicQueueDisplay(PDO $pdo): array
{
    $grouped = fetchQueueTicketsGrouped($pdo);
    $serving = $pdo->query(
        "SELECT ticket_number, window_number, purpose, called_at FROM queue_tickets
         WHERE status = 'serving' AND DATE(created_at) = CURDATE()
         ORDER BY called_at DESC LIMIT 1"
    )->fetch();

    $tables = [];
    foreach (queuePurposeConfig() as $purpose => $cfg) {
        $s = $grouped[$purpose]['serving'];
        $tables[$purpose] = [
            'table'   => $cfg['table'],
            'label'   => $cfg['label'],
            'serving' => $s ? $s['ticket_number'] : null,
            'waiting' => array_column($grouped[$purpose]['waiting'], 'ticket_number'),
        ];
    }

    $waiting = $pdo->query(
        "SELECT ticket_number FROM queue_tickets
         WHERE status = 'waiting' AND DATE(created_at) = CURDATE()
         ORDER BY created_at ASC LIMIT 8"
    )->fetchAll();

    return [
        'serving' => $serving ?: null,
        'waiting' => array_column($waiting, 'ticket_number'),
        'tables'  => $tables,
    ];
}

function fetchDashboardStats(PDO $pdo, bool $isAdmin, string $staffId): array
{
    $stats = [
        'pending_count'   => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status IN ('pending','verified')")->fetchColumn(),
        'queue_count'     => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn(),
        'today_appts'     => (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn(),
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
    ];
}

function fetchAppointmentDatesInMonth(PDO $pdo, string $yearMonth): array
{
    if (!preg_match('/^\d{4}-\d{2}$/', $yearMonth)) {
        $yearMonth = date('Y-m');
    }

    $start = $yearMonth . '-01';
    $end = date('Y-m-t', strtotime($start));
    $stmt = $pdo->prepare(
        "SELECT a.appointment_date, COUNT(*) AS cnt
         FROM appointments a
         LEFT JOIN document_requests r ON r.tracking_code = a.tracking_code
         WHERE a.appointment_date BETWEEN ? AND ?
           AND a.status NOT IN ('cancelled', 'no_show')
           AND NOT (
                (a.source = 'document_request' OR (a.tracking_code IS NOT NULL AND a.tracking_code != ''))
                AND r.status IN ('pending', 'rejected')
           )
         GROUP BY a.appointment_date"
    );
    $stmt->execute([$start, $end]);
    $map = [];
    foreach ($stmt->fetchAll() as $row) {
        $map[(string) $row['appointment_date']] = (int) $row['cnt'];
    }

    return $map;
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
        'dates'         => fetchAppointmentDatesInMonth($pdo, $calendarMonth),
        'schedule_date' => $scheduleDate,
        'appointments'  => array_slice(fetchAppointments($pdo, $scheduleDate), 0, 8),
    ];
}

function fetchAppointments(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        "SELECT a.id, a.appointment_code, a.first_name, a.middle_name, a.last_name, a.service_type,
                a.appointment_time, a.status
         FROM appointments a
         LEFT JOIN document_requests r ON r.tracking_code = a.tracking_code
         WHERE a.appointment_date = ?
           AND a.status NOT IN ('cancelled', 'no_show')
           AND NOT (
                (a.source = 'document_request' OR (a.tracking_code IS NOT NULL AND a.tracking_code != ''))
                AND r.status IN ('pending', 'rejected')
           )
         ORDER BY a.appointment_time ASC"
    );
    $stmt->execute([$date]);
    return enrichCitizenNameRows($stmt->fetchAll());
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
