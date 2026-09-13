<?php

function analyticsCountMap(array $rows, string $key, string $countKey = 'cnt'): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row[$key]] = (int) $row[$countKey];
    }

    return $map;
}

function fetchAnalyticsDashboard(PDO $pdo): array
{
    $totalRequests = (int) $pdo->query('SELECT COUNT(*) FROM document_requests')->fetchColumn();
    $todayRequests = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE DATE(submitted_at) = CURDATE()")->fetchColumn();
    $weekRequests  = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $statusMap = analyticsCountMap(
        $pdo->query("SELECT status, COUNT(*) AS cnt FROM document_requests GROUP BY status")->fetchAll(),
        'status'
    );

    $pendingCount   = (int) ($statusMap['pending'] ?? 0);
    $verifiedCount  = (int) ($statusMap['verified'] ?? 0) + (int) ($statusMap['processing'] ?? 0);
    $readyCount     = (int) ($statusMap['ready'] ?? 0);
    $completedCount = (int) ($statusMap['completed'] ?? 0);

    $monthRows = $pdo->query(
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM document_requests
         WHERE submitted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    )->fetchAll();
    $monthCounts = [];
    $monthLabels = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $monthCounts[$key] = 0;
        $monthLabels[] = date('M', strtotime($key . '-01'));
    }
    foreach ($monthRows as $row) {
        if (isset($monthCounts[$row['month']])) {
            $monthCounts[$row['month']] = (int) $row['cnt'];
        }
    }
    $maxMonth = max($monthCounts ?: [0]);

    $apptTotal = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();
    $apptToday = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
    $apptMap = analyticsCountMap(
        $pdo->query("SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status")->fetchAll(),
        'status'
    );

    $queueWaiting = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $queueServing = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'serving' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $queueServed  = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'completed' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $recordsTotal = (int) $pdo->query('SELECT COUNT(*) FROM civil_records WHERE deleted_at IS NULL')->fetchColumn();
    $recordTypeMap = analyticsCountMap(
        $pdo->query("SELECT record_type, COUNT(*) AS cnt FROM civil_records WHERE deleted_at IS NULL GROUP BY record_type")->fetchAll(),
        'record_type'
    );
    $birthRecords    = (int) ($recordTypeMap['birth'] ?? 0);
    $deathRecords    = (int) ($recordTypeMap['death'] ?? 0);
    $marriageRecords = (int) ($recordTypeMap['marriage'] ?? 0);

    $recordMonthRows = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM civil_records
         WHERE deleted_at IS NULL
           AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    )->fetchAll();
    $recordMonthCounts = [];
    $recordMonthLabels = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $recordMonthCounts[$key] = 0;
        $recordMonthLabels[] = date('M', strtotime($key . '-01'));
    }
    foreach ($recordMonthRows as $row) {
        if (isset($recordMonthCounts[$row['month']])) {
            $recordMonthCounts[$row['month']] = (int) $row['cnt'];
        }
    }
    $maxRecordMonth = max($recordMonthCounts ?: [0]);

    $recordTypeChart = [
        'labels' => ['Birth', 'Death', 'Marriage'],
        'counts' => [$birthRecords, $deathRecords, $marriageRecords],
        'colors' => ['#3b82f6', '#64748b', '#ec4899'],
    ];

    $pipelineStages = [
        ['key' => 'pending',   'label' => 'Pending',   'count' => $pendingCount,   'color' => '#f59e0b'],
        ['key' => 'verified',  'label' => 'Verified',  'count' => $verifiedCount,  'color' => '#3b82f6'],
        ['key' => 'ready',     'label' => 'Ready',     'count' => $readyCount,     'color' => '#10b981'],
        ['key' => 'completed', 'label' => 'Completed', 'count' => $completedCount, 'color' => '#64748b'],
    ];

    $apptChartLabels = [];
    $apptChartCounts = [];
    $apptChartColors = ['#3b82f6', '#8b5cf6', '#64748b', '#ef4444', '#f59e0b'];
    foreach (['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'] as $i => $key) {
        if ($apptTotal === 0) {
            break;
        }
        $apptChartLabels[] = appointmentStatusLabel($key);
        $apptChartCounts[] = (int) ($apptMap[$key] ?? 0);
    }

    $chartPayload = [
        'pipeline' => [
            'labels' => array_column($pipelineStages, 'label'),
            'counts' => array_column($pipelineStages, 'count'),
            'colors' => array_column($pipelineStages, 'color'),
        ],
        'months' => [
            'labels' => $monthLabels,
            'counts' => array_values($monthCounts),
        ],
        'appointments' => [
            'labels' => $apptChartLabels,
            'counts' => $apptChartCounts,
            'colors' => array_slice($apptChartColors, 0, count($apptChartLabels)),
        ],
        'records' => [
            'types'  => $recordTypeChart,
            'months' => [
                'labels' => $recordMonthLabels,
                'counts' => array_values($recordMonthCounts),
            ],
        ],
    ];

    return [
        'totalRequests'   => $totalRequests,
        'todayRequests'   => $todayRequests,
        'weekRequests'    => $weekRequests,
        'pendingCount'    => $pendingCount,
        'readyCount'      => $readyCount,
        'queueWaiting'    => $queueWaiting,
        'apptTotal'       => $apptTotal,
        'apptToday'       => $apptToday,
        'queueServing'    => $queueServing,
        'queueServed'     => $queueServed,
        'recordsTotal'    => $recordsTotal,
        'birthRecords'    => $birthRecords,
        'deathRecords'    => $deathRecords,
        'marriageRecords' => $marriageRecords,
        'maxMonth'        => $maxMonth,
        'maxRecordMonth'  => $maxRecordMonth,
        'chartPayload'    => $chartPayload,
    ];
}
