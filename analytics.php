<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireAdmin();

$activePage = 'analytics.php';
$pdo = getDB();

function analyticsCountMap(array $rows, string $key, string $countKey = 'cnt'): array
{
    $map = [];
    foreach ($rows as $row) {
        $map[(string) $row[$key]] = (int) $row[$countKey];
    }
    return $map;
}

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
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?= adminLayoutHeadStyles('analytics') ?>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="admin-content p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap space-y-6">

            <div class="admin-page-head">
                <h1>Analytics</h1>
                <p><?= date('F j, Y') ?> · For detailed exports, use Operational Reports</p>
            </div>

            <?php if ($pendingCount > 0 || $readyCount > 0 || $queueWaiting > 0): ?>
            <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold text-amber-900 mr-1">Needs attention:</span>
                <?php if ($pendingCount > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'pending'])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-2.5 py-1 rounded-lg hover:bg-amber-100"><?= $pendingCount ?> pending</a>
                <?php endif; ?>
                <?php if ($readyCount > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => date('Y-m-d')])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-2.5 py-1 rounded-lg hover:bg-amber-100"><?= $readyCount ?> ready</a>
                <?php endif; ?>
                <?php if ($queueWaiting > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-2.5 py-1 rounded-lg hover:bg-amber-100"><?= $queueWaiting ?> in queue</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 block">
                    <p class="text-[10px] font-bold uppercase text-gray-400">Requests</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= $totalRequests ?></p>
                    <p class="text-[11px] text-gray-400 mt-0.5"><?= $todayRequests ?> today · <?= $weekRequests ?> this week</p>
                </a>
                <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php')) ?>" class="stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 block">
                    <p class="text-[10px] font-bold uppercase text-gray-400">Appointments today</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= $apptToday ?></p>
                    <p class="text-[11px] text-gray-400 mt-0.5"><?= $apptTotal ?> all-time</p>
                </a>
                <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 block">
                    <p class="text-[10px] font-bold uppercase text-gray-400">Queue waiting</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= $queueWaiting ?></p>
                    <p class="text-[11px] text-gray-400 mt-0.5"><?= $queueServed ?> served today</p>
                </a>
                <a href="<?= htmlspecialchars(buildAuthUrl('records.php')) ?>" class="stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 block">
                    <p class="text-[10px] font-bold uppercase text-gray-400">Civil records</p>
                    <p class="text-2xl font-black text-slate-900 mt-1"><?= $recordsTotal ?></p>
                    <p class="text-[11px] text-gray-400 mt-0.5">On file</p>
                </a>
            </div>

            <div class="analytics-charts">
                <div class="analytics-chart-card analytics-chart-card--featured">
                    <div class="analytics-chart-head">
                        <h2>Monthly requests</h2>
                        <p>Submission trend · last 6 months</p>
                    </div>
                    <?php if ($maxMonth === 0): ?>
                    <div class="analytics-empty">No data yet.</div>
                    <?php else: ?>
                    <div class="chart-box chart-box--tall"><canvas id="chartMonths"></canvas></div>
                    <?php endif; ?>
                </div>

                <div class="analytics-chart-grid">
                    <div class="analytics-chart-card">
                        <div class="analytics-chart-head">
                            <h2>Request pipeline</h2>
                            <p>Current stage breakdown</p>
                        </div>
                        <?php if ($totalRequests === 0): ?>
                        <div class="analytics-empty">No requests yet.</div>
                        <?php else: ?>
                        <div class="chart-box chart-box--compact"><canvas id="chartPipeline"></canvas></div>
                        <?php endif; ?>
                    </div>

                    <div class="analytics-chart-card">
                        <div class="analytics-chart-head">
                            <h2>Appointments</h2>
                            <p>All bookings by status</p>
                        </div>
                        <?php if ($apptTotal === 0): ?>
                        <div class="analytics-empty">No appointments yet.</div>
                        <?php else: ?>
                        <div class="chart-box chart-box--compact"><canvas id="chartAppointments"></canvas></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="analytics-chart-card">
                    <div class="analytics-chart-head">
                        <h2>Queue today</h2>
                        <p>Live ticket counts</p>
                    </div>
                    <div class="analytics-queue-strip">
                        <div class="analytics-queue-item bg-amber-50">
                            <strong class="text-amber-700"><?= $queueWaiting ?></strong>
                            <span class="text-amber-800/70">Waiting</span>
                        </div>
                        <div class="analytics-queue-item bg-blue-50">
                            <strong class="text-blue-700"><?= $queueServing ?></strong>
                            <span class="text-blue-800/70">Serving</span>
                        </div>
                        <div class="analytics-queue-item bg-emerald-50">
                            <strong class="text-emerald-700"><?= $queueServed ?></strong>
                            <span class="text-emerald-800/70">Done</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </main>

    <?= pageConfigJson($chartPayload, 'analytics-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/analytics.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
