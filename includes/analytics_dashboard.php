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

/** @return array{labels: list<string>, birth: list<int>, death: list<int>, marriage: list<int>, max: int} */
function analyticsRecordTypeMonthlyTrend(PDO $pdo): array
{
    $series = analyticsEmptyMonthSeries();
    $keys = array_keys($series['counts']);
    $birth = $death = $marriage = [];
    foreach ($keys as $key) {
        $birth[$key] = 0;
        $death[$key] = 0;
        $marriage[$key] = 0;
    }

    $stmt = $pdo->query(
        "SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, record_type, COUNT(*) AS cnt
         FROM civil_records
         WHERE deleted_at IS NULL
           AND created_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month, record_type
         ORDER BY month ASC"
    );
    foreach ($stmt ? $stmt->fetchAll() : [] as $row) {
        $month = (string) $row['month'];
        if (!isset($birth[$month])) {
            continue;
        }
        $cnt = (int) $row['cnt'];
        $type = (string) $row['record_type'];
        if ($type === 'birth') {
            $birth[$month] = $cnt;
        } elseif ($type === 'death') {
            $death[$month] = $cnt;
        } elseif ($type === 'marriage') {
            $marriage[$month] = $cnt;
        }
    }

    $all = array_merge(array_values($birth), array_values($death), array_values($marriage));

    return [
        'labels'   => $series['labels'],
        'birth'    => array_values($birth),
        'death'    => array_values($death),
        'marriage' => array_values($marriage),
        'max'      => $all === [] ? 0 : max($all),
    ];
}

function analyticsRecordsTotalGrowthPercent(PDO $pdo, int $recordsTotal): array
{
    $periodStart = date('Y-m-d 00:00:00', strtotime(date('Y-m-01') . ' -5 months'));
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM civil_records WHERE deleted_at IS NULL AND created_at < ?'
    );
    $stmt->execute([$periodStart]);
    $baseline = (int) $stmt->fetchColumn();
    if ($baseline <= 0) {
        return ['pct' => $recordsTotal > 0 ? 100 : 0, 'up' => true];
    }
    $change = (($recordsTotal - $baseline) / $baseline) * 100;

    return ['pct' => max(0, (int) round(abs($change))), 'up' => $change >= 0];
}

/** @return array{birth: int, death: int, marriage: int} */
function analyticsRecordTypeCountsInMonth(PDO $pdo, string $yearMonth): array
{
    $start = $yearMonth . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    $stmt = $pdo->prepare(
        "SELECT record_type, COUNT(*) AS cnt FROM civil_records
         WHERE deleted_at IS NULL AND created_at >= ? AND created_at < ?
         GROUP BY record_type"
    );
    $stmt->execute([$start . ' 00:00:00', $end . ' 00:00:00']);
    $map = analyticsCountMap($stmt->fetchAll(), 'record_type');

    return [
        'birth'    => (int) ($map['birth'] ?? 0),
        'death'    => (int) ($map['death'] ?? 0),
        'marriage' => (int) ($map['marriage'] ?? 0),
    ];
}

function analyticsPrintJobStats(PDO $pdo, string $documentKind): array
{
    $empty = [
        'total'       => 0,
        'today'       => 0,
        'week'        => 0,
        'byType'      => ['birth' => 0, 'death' => 0, 'marriage' => 0],
        'monthSeries' => analyticsEmptyMonthSeries(),
        'available'   => false,
    ];

    if (!in_array($documentKind, ['certification', 'certificate'], true)) {
        return $empty;
    }

    try {
        $pdo->query('SELECT document_kind FROM print_templates LIMIT 1');
        $pdo->query('SELECT id FROM print_jobs LIMIT 1');
    } catch (Throwable $e) {
        return $empty;
    }

    $baseWhere = "pt.document_kind = '{$documentKind}'
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

function analyticsCertificationStats(PDO $pdo): array
{
    return analyticsPrintJobStats($pdo, 'certification');
}

function analyticsCertificateStats(PDO $pdo): array
{
    return analyticsPrintJobStats($pdo, 'certificate');
}

/**
 * @param array<string, int> $map
 * @return array{labels: list<string>, counts: list<int>, colors: list<string>}
 */
function analyticsChartSeriesFromCountMap(array $map, callable $labelFn, array $palette): array
{
    if ($map === []) {
        return ['labels' => [], 'counts' => [], 'colors' => []];
    }
    arsort($map);
    $labels = [];
    $counts = [];
    $colors = [];
    $i = 0;
    foreach ($map as $key => $cnt) {
        $cnt = (int) $cnt;
        if ($cnt <= 0) {
            continue;
        }
        $labels[] = $labelFn((string) $key);
        $counts[] = $cnt;
        $colors[] = $palette[$i % count($palette)];
        $i++;
    }

    return ['labels' => $labels, 'counts' => $counts, 'colors' => $colors];
}

/** @return array{labels: list<string>, counts: list<int>} */
function analyticsTrendBucketsForRange(PDO $pdo, string $from, string $to, string $table, string $dateColumn): array
{
    $allowed = ['document_requests' => 'submitted_at', 'appointments' => 'appointment_date'];
    if (!isset($allowed[$table]) || $allowed[$table] !== $dateColumn) {
        return ['labels' => [], 'counts' => []];
    }

    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
        return ['labels' => [], 'counts' => []];
    }

    $daySpan = (int) floor(($toTs - $fromTs) / 86400) + 1;
    $labels = [];
    $counts = [];

    if ($daySpan <= 35) {
        $stmt = $pdo->prepare(
            "SELECT DATE($dateColumn) AS bucket, COUNT(*) AS cnt
             FROM $table
             WHERE DATE($dateColumn) BETWEEN ? AND ?
             GROUP BY bucket ORDER BY bucket ASC"
        );
        $stmt->execute([$from, $to]);
        $byDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDay[(string) $row['bucket']] = (int) $row['cnt'];
        }
        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $key = date('Y-m-d', $ts);
            $labels[] = date('M j', $ts);
            $counts[] = (int) ($byDay[$key] ?? 0);
        }

        return ['labels' => $labels, 'counts' => $counts];
    }

    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT($dateColumn, '%Y-%m') AS bucket, COUNT(*) AS cnt
         FROM $table
         WHERE DATE($dateColumn) BETWEEN ? AND ?
         GROUP BY bucket ORDER BY bucket ASC"
    );
    $stmt->execute([$from, $to]);
    $byMonth = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $byMonth[(string) $row['bucket']] = (int) $row['cnt'];
    }
    $cursor = strtotime(date('Y-m-01', $fromTs));
    $endMonth = strtotime(date('Y-m-01', $toTs));
    while ($cursor !== false && $cursor <= $endMonth) {
        $key = date('Y-m', $cursor);
        $labels[] = date('M Y', $cursor);
        $counts[] = (int) ($byMonth[$key] ?? 0);
        $cursor = strtotime('+1 month', $cursor);
    }

    return ['labels' => $labels, 'counts' => $counts];
}

/** Last 6 months — analytics tab preview. */
function buildAnalyticsRequestsChartSummary(PDO $pdo): array
{
    $monthSeries = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM document_requests
         WHERE submitted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );
    $statusMap = analyticsCountMap(
        $pdo->query('SELECT status, COUNT(*) AS cnt FROM document_requests GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'status'
    );
    $typeMap = analyticsCountMap(
        $pdo->query('SELECT document_type, COUNT(*) AS cnt FROM document_requests GROUP BY document_type')->fetchAll(PDO::FETCH_ASSOC),
        'document_type'
    );
    $total = (int) $pdo->query('SELECT COUNT(*) FROM document_requests')->fetchColumn();

    return [
        'hasData'  => $total > 0,
        'total'    => $total,
        'monthly'  => [
            'labels' => $monthSeries['labels'],
            'totals' => array_values($monthSeries['counts']),
        ],
        'byStatus' => analyticsChartSeriesFromCountMap(
            $statusMap,
            static fn (string $k): string => requestStatusLabel($k),
            ['#f59e0b', '#3b82f6', '#8b5cf6', '#6366f1', '#0ea5e9', '#10b981', '#22c55e', '#64748b', '#ef4444']
        ),
        'byType'   => analyticsChartSeriesFromCountMap(
            $typeMap,
            static fn (string $k): string => documentTypeLabel($k),
            ['#2563eb', '#64748b', '#ec4899', '#8b5cf6']
        ),
    ];
}

/** Last 6 months — analytics tab preview. */
function buildAnalyticsPrintsChartSummary(PDO $pdo): array
{
    $empty = [
        'hasData' => false,
        'total'   => 0,
        'monthly' => ['labels' => [], 'totals' => []],
        'byKind'  => ['labels' => [], 'counts' => [], 'colors' => []],
    ];

    try {
        $pdo->query('SELECT id FROM print_jobs LIMIT 1');
    } catch (Throwable $e) {
        return $empty;
    }

    $baseWhere = "pj.print_mode = 'production' AND pj.status = 'completed'";

    $monthSeries = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(pj.printed_at, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere}
           AND pj.printed_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );

    $certStats = analyticsCertificationStats($pdo);
    $certificateStats = analyticsCertificateStats($pdo);
    $total = (int) $certStats['total'] + (int) $certificateStats['total'];

    $byKindMap = [
        'certification' => (int) $certStats['total'],
        'certificate'   => (int) $certificateStats['total'],
    ];

    return [
        'hasData' => $total > 0,
        'total'   => $total,
        'monthly' => [
            'labels' => $monthSeries['labels'],
            'totals' => array_values($monthSeries['counts']),
        ],
        'byKind'  => analyticsChartSeriesFromCountMap(
            $byKindMap,
            static fn (string $k): string => printJobDocumentKindLabel($k),
            ['#2563eb', '#14b8a6']
        ),
    ];
}

/** Last 6 months — analytics tab preview. */
function buildAnalyticsAppointmentsChartSummary(PDO $pdo): array
{
    $monthSeries = analyticsMonthSeries(
        $pdo,
        "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month, COUNT(*) AS cnt
         FROM appointments
         WHERE appointment_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
         GROUP BY month ORDER BY month ASC"
    );
    $statusMap = analyticsCountMap(
        $pdo->query('SELECT status, COUNT(*) AS cnt FROM appointments GROUP BY status')->fetchAll(PDO::FETCH_ASSOC),
        'status'
    );
    $total = (int) $pdo->query('SELECT COUNT(*) FROM appointments')->fetchColumn();

    return [
        'hasData'  => $total > 0,
        'total'    => $total,
        'monthly'  => [
            'labels' => $monthSeries['labels'],
            'totals' => array_values($monthSeries['counts']),
        ],
        'byStatus' => analyticsChartSeriesFromCountMap(
            $statusMap,
            static fn (string $k): string => appointmentStatusLabel($k),
            ['#3b82f6', '#8b5cf6', '#64748b', '#ef4444', '#f59e0b']
        ),
    ];
}

/** Report detail section — selected date range. */
function buildRequestsReportChartPayload(PDO $pdo, string $from, string $to, array $byStatus, array $byType): array
{
    $period = analyticsTrendBucketsForRange($pdo, $from, $to, 'document_requests', 'submitted_at');
    $status = analyticsChartSeriesFromCountMap(
        $byStatus,
        static fn (string $k): string => requestStatusLabel($k),
        ['#f59e0b', '#3b82f6', '#8b5cf6', '#6366f1', '#0ea5e9', '#10b981', '#22c55e', '#64748b', '#ef4444']
    );
    $types = analyticsChartSeriesFromCountMap(
        $byType,
        static fn (string $k): string => documentTypeLabel($k),
        ['#2563eb', '#64748b', '#ec4899', '#8b5cf6']
    );
    $hasData = array_sum($byStatus) > 0 || array_sum($period['counts']) > 0;

    return [
        'hasData'  => $hasData,
        'period'   => $period,
        'byStatus' => $status,
        'byType'   => $types,
    ];
}

/** Report detail section — selected date range. */
function buildAppointmentsReportChartPayload(PDO $pdo, string $from, string $to, array $byStatus): array
{
    $period = analyticsTrendBucketsForRange($pdo, $from, $to, 'appointments', 'appointment_date');
    $status = analyticsChartSeriesFromCountMap(
        $byStatus,
        static fn (string $k): string => appointmentStatusLabel($k),
        ['#3b82f6', '#8b5cf6', '#64748b', '#ef4444', '#f59e0b']
    );
    $hasData = array_sum($byStatus) > 0 || array_sum($period['counts']) > 0;

    return [
        'hasData'  => $hasData,
        'period'   => $period,
        'byStatus' => $status,
    ];
}

/**
 * @return array{
 *     labels: list<string>,
 *     certification: list<int>,
 *     certificate: list<int>,
 *     total: list<int>
 * }
 */
function analyticsPrintPeriodSeries(PDO $pdo, string $from, string $to): array
{
    $empty = ['labels' => [], 'certification' => [], 'certificate' => [], 'total' => []];

    try {
        $pdo->query('SELECT id FROM print_jobs LIMIT 1');
    } catch (Throwable $e) {
        return $empty;
    }

    $fromTs = strtotime($from);
    $toTs = strtotime($to);
    if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
        return $empty;
    }

    $baseWhere = "pj.print_mode = 'production'
        AND pj.status = 'completed'
        AND DATE(pj.printed_at) BETWEEN ? AND ?";
    $daySpan = (int) floor(($toTs - $fromTs) / 86400) + 1;
    $labels = [];
    $certification = [];
    $certificate = [];
    $total = [];

    if ($daySpan <= 35) {
        $stmt = $pdo->prepare(
            "SELECT DATE(pj.printed_at) AS bucket, pt.document_kind, COUNT(*) AS cnt
             FROM print_jobs pj
             INNER JOIN print_templates pt ON pt.id = pj.template_id
             WHERE {$baseWhere}
             GROUP BY bucket, pt.document_kind
             ORDER BY bucket ASC"
        );
        $stmt->execute([$from, $to]);
        $byDay = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bucket = (string) ($row['bucket'] ?? '');
            $kind = (string) ($row['document_kind'] ?? '');
            if (!isset($byDay[$bucket])) {
                $byDay[$bucket] = ['certification' => 0, 'certificate' => 0];
            }
            if (isset($byDay[$bucket][$kind])) {
                $byDay[$bucket][$kind] = (int) ($row['cnt'] ?? 0);
            }
        }
        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $key = date('Y-m-d', $ts);
            $labels[] = date('M j', $ts);
            $certCount = (int) ($byDay[$key]['certification'] ?? 0);
            $certPrintCount = (int) ($byDay[$key]['certificate'] ?? 0);
            $certification[] = $certCount;
            $certificate[] = $certPrintCount;
            $total[] = $certCount + $certPrintCount;
        }

        return compact('labels', 'certification', 'certificate', 'total');
    }

    $stmt = $pdo->prepare(
        "SELECT DATE_FORMAT(pj.printed_at, '%Y-%m') AS bucket, pt.document_kind, COUNT(*) AS cnt
         FROM print_jobs pj
         INNER JOIN print_templates pt ON pt.id = pj.template_id
         WHERE {$baseWhere}
         GROUP BY bucket, pt.document_kind
         ORDER BY bucket ASC"
    );
    $stmt->execute([$from, $to]);
    $byMonth = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bucket = (string) ($row['bucket'] ?? '');
        $kind = (string) ($row['document_kind'] ?? '');
        if (!isset($byMonth[$bucket])) {
            $byMonth[$bucket] = ['certification' => 0, 'certificate' => 0];
        }
        if (isset($byMonth[$bucket][$kind])) {
            $byMonth[$bucket][$kind] = (int) ($row['cnt'] ?? 0);
        }
    }
    $cursor = strtotime(date('Y-m-01', $fromTs));
    $endMonth = strtotime(date('Y-m-01', $toTs));
    while ($cursor !== false && $cursor <= $endMonth) {
        $key = date('Y-m', $cursor);
        $labels[] = date('M Y', $cursor);
        $certCount = (int) ($byMonth[$key]['certification'] ?? 0);
        $certPrintCount = (int) ($byMonth[$key]['certificate'] ?? 0);
        $certification[] = $certCount;
        $certificate[] = $certPrintCount;
        $total[] = $certCount + $certPrintCount;
        $cursor = strtotime('+1 month', $cursor);
    }

    return compact('labels', 'certification', 'certificate', 'total');
}

/** Report detail section — selected date range. */
function buildPrintsReportChartPayload(PDO $pdo, string $from, string $to, array $byKind, array $byType): array
{
    $period = analyticsPrintPeriodSeries($pdo, $from, $to);
    $kind = analyticsChartSeriesFromCountMap(
        $byKind,
        static fn (string $k): string => printJobDocumentKindLabel($k),
        ['#2563eb', '#14b8a6']
    );
    $types = analyticsChartSeriesFromCountMap(
        $byType,
        static fn (string $k): string => civilRecordTypeLabel($k),
        ['#2563eb', '#64748b', '#ec4899']
    );
    $hasData = array_sum($byKind) > 0 || array_sum($period['total']) > 0;

    return [
        'hasData'  => $hasData,
        'period'   => $period,
        'byKind'   => $kind,
        'byType'   => $types,
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

    $certStats = analyticsCertificationStats($pdo);
    $certificateStats = analyticsCertificateStats($pdo);
    $maxCertMonth = $certStats['monthSeries']['max'];
    $currentMonthKey = date('Y-m');
    $certMonthCount = (int) ($certStats['monthSeries']['counts'][$currentMonthKey] ?? 0);
    $certificateMonthCount = (int) ($certificateStats['monthSeries']['counts'][$currentMonthKey] ?? 0);

    $recordTypeTrend = analyticsRecordTypeMonthlyTrend($pdo);
    $recordsGrowth = analyticsRecordsTotalGrowthPercent($pdo, $recordsTotal);
    $thisMonthTypes = analyticsRecordTypeCountsInMonth($pdo, $currentMonthKey);
    $lastMonthKey = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    $lastMonthTypes = analyticsRecordTypeCountsInMonth($pdo, $lastMonthKey);

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

    $queueWaitChart = fetchQueueWaitChartAnalytics($pdo, 7);
    $requestsReportChart = buildAnalyticsRequestsChartSummary($pdo);
    $appointmentsReportChart = buildAnalyticsAppointmentsChartSummary($pdo);
    $printsReportChart = buildAnalyticsPrintsChartSummary($pdo);

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
        'certifications' => [
            'types'  => $certTypeChart,
            'months' => [
                'labels' => $certStats['monthSeries']['labels'],
                'counts' => array_values($certStats['monthSeries']['counts']),
            ],
        ],
        'certificates' => [
            'months' => [
                'labels' => $certificateStats['monthSeries']['labels'],
                'counts' => array_values($certificateStats['monthSeries']['counts']),
            ],
        ],
        'recordTypeTrend' => $recordTypeTrend,
        'printVolume' => [
            'certificationToday'    => $certStats['today'],
            'certificationMonth'    => $certMonthCount,
            'certificateToday'      => $certificateStats['today'],
            'certificateMonth'      => $certificateMonthCount,
        ],
        'dailyIntakeQueue' => [
            'labels' => [
                'Online intake (today)',
                'In-person intake (today)',
                'Queue · waiting',
                'Queue · being served',
                'Queue · completed today',
            ],
            'counts' => [
                $todayRequests,
                $walkInToday,
                $queueWaiting,
                $queueServing,
                $queueServed,
            ],
            'colors' => ['#2563eb', '#f97316', '#f59e0b', '#3b82f6', '#10b981'],
        ],
        'queueWait' => $queueWaitChart,
        'requestsReport' => $requestsReportChart,
        'appointmentsReport' => $appointmentsReportChart,
        'printsReport' => $printsReportChart,
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
        'certTotal'          => $certStats['total'],
        'certToday'          => $certStats['today'],
        'certWeek'           => $certStats['week'],
        'certificateTotal'   => $certificateStats['total'],
        'certificateToday'   => $certificateStats['today'],
        'certificateWeek'    => $certificateStats['week'],
        'maxMonth'        => $maxMonth,
        'maxCertMonth'         => $maxCertMonth,
        'certMonthCount'       => $certMonthCount,
        'certificateMonthCount'=> $certificateMonthCount,
        'recordsGrowth'        => $recordsGrowth,
        'recordTypeTrend'      => $recordTypeTrend,
        'thisMonthTypes'       => $thisMonthTypes,
        'lastMonthTypes'       => $lastMonthTypes,
        'queueWaitChart'          => $queueWaitChart,
        'requestsReportChart'     => $requestsReportChart,
        'appointmentsReportChart' => $appointmentsReportChart,
        'printsReportChart'       => $printsReportChart,
        'chartPayload'            => $chartPayload,
    ];
}
