<?php

function analyticsCountMap(array $rows, string $key, string $countKey = 'cnt'): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row[$key]] = (int) $row[$countKey];
    }

    return $map;
}

function analyticsEmptyMonthSeries(): array
{
    $counts = [];
    $labels = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
        $counts[$key] = 0;
        $labels[] = date('M', strtotime($key . '-01'));
    }

    return [
        'labels' => $labels,
        'counts' => $counts,
        'max'    => 0,
    ];
}

function analyticsMonthSeries(PDO $pdo, string $sql): array
{
    $series = analyticsEmptyMonthSeries();
    $counts = $series['counts'];

    $stmt = $pdo->query($sql);
    foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
        if (isset($counts[$row['month']])) {
            $counts[$row['month']] = (int) $row['cnt'];
        }
    }

    return [
        'labels' => $series['labels'],
        'counts' => $counts,
        'max'    => max($counts ?: [0]),
    ];
}

function analyticsCertificationStats(PDO $pdo): array
{
    $empty = [
        'total'       => 0,
        'today'       => 0,
        'week'        => 0,
        'byType'      => ['birth' => 0, 'death' => 0, 'marriage' => 0],
        'monthSeries' => analyticsEmptyMonthSeries(),
        'available'   => false,
    ];

    try {
        $pdo->query('SELECT document_kind FROM print_templates LIMIT 1');
        $pdo->query('SELECT id FROM print_jobs LIMIT 1');
    } catch (Throwable $e) {
        return $empty;
    }

    $baseWhere = "pt.document_kind = 'certification'
        AND pj.print_mode = 'production'
        AND pj.status = 'completed'";

    $total = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere}"
    )->fetchColumn();

    $today = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere} AND DATE(pj.printed_at) = CURDATE()"
    )->fetchColumn();

    $week = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere} AND pj.printed_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)"
    )->fetchColumn();

    $typeMap = analyticsCountMap(
        $pdo->query(
            "SELECT pj.certificate_type, COUNT(*) AS cnt
             FROM print_jobs pj
             INNER JOIN print_templates pt ON pt.id = pj.template_id
             WHERE {$baseWhere}
             GROUP BY pj.certificate_type"
        )->fetchAll(),
        'certificate_type'
    );

    $monthSeries = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(pj.printed_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere}
           AND pj.printed_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );

    return [
        'total'       => $total,
        'today'       => $today,
        'week'        => $week,
        'byType'      => [
            'birth'    => (int) ($typeMap['birth'] ?? 0),
            'death'    => (int) ($typeMap['death'] ?? 0),
            'marriage' => (int) ($typeMap['marriage'] ?? 0),
        ],
        'monthSeries' => $monthSeries,
        'available'   => true,
    ];
}

function fetchAnalyticsDashboard(PDO $pdo): array
{
    $totalRequests = (int) $pdo->query('SELECT COUNT(*) FROM document_requests')->fetchColumn();
    $todayRequests = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE DATE(submitted_at) = CURDATE()")->fetchColumn();
    $weekRequests  = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $walkInTotal = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE purpose = 'walk_in'")->fetchColumn();
    $walkInToday = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE purpose = 'walk_in' AND DATE(created_at) = CURDATE()")->fetchColumn();
    $walkInWeek  = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE purpose = 'walk_in' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

    $totalIntake = $totalRequests + $walkInTotal;
    $todayIntake = $todayRequests + $walkInToday;
    $weekIntake  = $weekRequests + $walkInWeek;

    $statusMap = analyticsCountMap(
        $pdo->query("SELECT status, COUNT(*) AS cnt FROM document_requests GROUP BY status")->fetchAll(),
        'status'
    );

    $pendingCount   = (int) ($statusMap['pending'] ?? 0);
    $verifiedCount  = (int) ($statusMap['verified'] ?? 0) + (int) ($statusMap['processing'] ?? 0);
    $readyCount     = (int) ($statusMap['ready'] ?? 0);
    $completedCount = (int) ($statusMap['completed'] ?? 0);

    $requestMonths = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM document_requests
         WHERE submitted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );
    $walkInMonths = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM queue_tickets
         WHERE purpose = 'walk_in'
           AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );

    $monthLabels = $requestMonths['labels'];
    $monthCounts = $requestMonths['counts'];
    $walkInMonthCounts = $walkInMonths['counts'];
    $combinedMonthCounts = [];
    foreach ($monthCounts as $key => $count) {
        $combinedMonthCounts[$key] = $count + ($walkInMonthCounts[$key] ?? 0);
    }
    $maxMonth = max($combinedMonthCounts ?: [0]);

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

    $certStats = analyticsCertificationStats($pdo);
    $maxCertMonth = $certStats['monthSeries']['max'];

    $recordTypeChart = [
        'labels' => ['Birth', 'Death', 'Marriage'],
        'counts' => [$birthRecords, $deathRecords, $marriageRecords],
        'colors' => ['#3b82f6', '#64748b', '#ec4899'],
    ];

    $certTypeChart = [
        'labels' => ['Birth', 'Death', 'Marriage'],
        'counts' => [
            $certStats['byType']['birth'],
            $certStats['byType']['death'],
            $certStats['byType']['marriage'],
        ],
        'colors' => ['#7c3aed', '#64748b', '#db2777'],
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
            'online' => array_values($monthCounts),
            'walkIn' => array_values($walkInMonthCounts),
        ],
        'intakeChannels' => [
            'labels' => ['Online requests', 'Walk-in queue'],
            'counts' => [$totalRequests, $walkInTotal],
            'colors' => ['#2563eb', '#f97316'],
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
        'certifications' => [
            'types'  => $certTypeChart,
            'months' => [
                'labels' => $certStats['monthSeries']['labels'],
                'counts' => array_values($certStats['monthSeries']['counts']),
            ],
        ],
    ];

    return [
        'totalRequests'   => $totalRequests,
        'todayRequests'   => $todayRequests,
        'weekRequests'    => $weekRequests,
        'walkInTotal'     => $walkInTotal,
        'walkInToday'     => $walkInToday,
        'walkInWeek'      => $walkInWeek,
        'totalIntake'     => $totalIntake,
        'todayIntake'     => $todayIntake,
        'weekIntake'      => $weekIntake,
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
        'certTotal'       => $certStats['total'],
        'certToday'       => $certStats['today'],
        'certWeek'        => $certStats['week'],
        'maxMonth'        => $maxMonth,
        'maxRecordMonth'  => $maxRecordMonth,
        'maxCertMonth'    => $maxCertMonth,
        'chartPayload'    => $chartPayload,
    ];
}
