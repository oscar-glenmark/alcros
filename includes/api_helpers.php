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
    $sql = "SELECT id, ticket_number, purpose, status, window_number, citizen_name, reference_code, created_at, called_at
            FROM queue_tickets
            WHERE DATE(created_at) = CURDATE() AND status IN ('waiting','serving')";
    if (in_array($filter, ['walk_in', 'appointment', 'document_claim'], true)) {
        $sql .= ' AND purpose = ' . $pdo->quote($filter);
    }
    $sql .= " ORDER BY FIELD(purpose,'walk_in','appointment','document_claim'), FIELD(status,'serving','waiting'), created_at ASC";
    return $pdo->query($sql)->fetchAll();
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
    ];

    $recentRequests = $pdo->query(
        "SELECT tracking_code, citizen_name, document_type, status, submitted_at
         FROM document_requests ORDER BY submitted_at DESC LIMIT 5"
    )->fetchAll();

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

    $todayAppts = $pdo->query(
        "SELECT citizen_name, appointment_time, service_type, status FROM appointments
         WHERE appointment_date = CURDATE() ORDER BY appointment_time ASC LIMIT 8"
    )->fetchAll();

    return [
        'stats'           => $stats,
        'recent_requests' => $recentRequests,
        'activities'      => $activities,
        'today_appts'     => $todayAppts,
    ];
}

function fetchManageRequests(PDO $pdo, string $filterStatus, string $search, string $filterType = 'all', int $limit = 0, int $offset = 0): array
{
    $sql = 'SELECT * FROM document_requests WHERE 1=1';
    $params = [];
    if ($filterStatus !== 'all' && $filterStatus !== '') {
        $sql .= ' AND status = ?';
        $params[] = $filterStatus;
    }
    if ($filterType !== 'all' && $filterType !== '') {
        $sql .= ' AND document_type = ?';
        $params[] = $filterType;
    }
    if ($search !== '') {
        $sql .= ' AND (citizen_name LIKE ? OR tracking_code LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $term = "%$search%";
        array_push($params, $term, $term, $term, $term);
    }
    $sql .= ' ORDER BY submitted_at DESC';
    if ($limit > 0) {
        $sql .= ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function countManageRequests(PDO $pdo, string $filterStatus, string $search, string $filterType = 'all'): int
{
    $sql = 'SELECT COUNT(*) FROM document_requests WHERE 1=1';
    $params = [];
    if ($filterStatus !== 'all' && $filterStatus !== '') {
        $sql .= ' AND status = ?';
        $params[] = $filterStatus;
    }
    if ($filterType !== 'all' && $filterType !== '') {
        $sql .= ' AND document_type = ?';
        $params[] = $filterType;
    }
    if ($search !== '') {
        $sql .= ' AND (citizen_name LIKE ? OR tracking_code LIKE ? OR email LIKE ? OR phone LIKE ?)';
        $term = "%$search%";
        array_push($params, $term, $term, $term, $term);
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function fetchRequestStatusCounts(PDO $pdo): array
{
    $rows = $pdo->query("SELECT status, COUNT(*) AS cnt FROM document_requests GROUP BY status")->fetchAll();
    $counts = array_fill_keys(requestStatusOptions(), 0);
    foreach ($rows as $row) {
        $counts[$row['status']] = (int) $row['cnt'];
    }
    $counts['all'] = array_sum($counts);
    return $counts;
}

function fetchAppointments(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare(
        'SELECT id, appointment_code, citizen_name, service_type, appointment_time, status
         FROM appointments WHERE appointment_date = ? ORDER BY appointment_time ASC'
    );
    $stmt->execute([$date]);
    return $stmt->fetchAll();
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

function statusBadgeClass(string $status): string
{
    $classes = [
        'pending'    => 'bg-yellow-100 text-yellow-700',
        'verified'   => 'bg-blue-100 text-blue-700',
        'ready'      => 'bg-green-100 text-green-700',
        'completed'  => 'bg-gray-100 text-gray-600',
        'rejected'   => 'bg-red-100 text-red-700',
    ];
    return $classes[$status] ?? 'bg-gray-100 text-gray-600';
}

function fetchNotifications(PDO $pdo, int $limit = 20): array
{
    $items = [];
    $docLabels = documentTypeLabelsMap();

    $pending = $pdo->query(
        "SELECT tracking_code, citizen_name, document_type, submitted_at
         FROM document_requests WHERE status = 'pending'
         ORDER BY submitted_at DESC LIMIT 5"
    )->fetchAll();
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

    $ready = $pdo->query(
        "SELECT tracking_code, citizen_name, document_type, updated_at
         FROM document_requests WHERE status = 'ready'
         ORDER BY updated_at DESC LIMIT 5"
    )->fetchAll();
    foreach ($ready as $row) {
        $items[] = [
            'id'         => 'req-ready-' . $row['tracking_code'],
            'type'       => 'ready_pickup',
            'title'      => 'Ready for pickup',
            'message'    => $row['citizen_name'] . ' · ' . ($docLabels[$row['document_type']] ?? $row['document_type']),
            'detail'     => $row['tracking_code'],
            'created_at' => $row['updated_at'],
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

    $appts = $pdo->query(
        "SELECT appointment_code, citizen_name, service_type, appointment_time, appointment_date, created_at
         FROM appointments
         WHERE status IN ('scheduled', 'confirmed')
           AND (
                created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                OR appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
           )
         ORDER BY created_at DESC
         LIMIT 10"
    )->fetchAll();
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
