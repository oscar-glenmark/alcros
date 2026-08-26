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
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .sidebar-item:hover { background-color: #f1f5f9; }
        .active-nav { background-color: #2563eb; color: white !important; }
        .stat-card { box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06); }
        .chart-box { position: relative; height: 220px; }
    </style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="admin-content p-6 lg:p-8 max-w-6xl w-full mx-auto space-y-6">

            <div>
                <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                    <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                </a>
                <h1 class="text-2xl font-black text-slate-900">Analytics</h1>
                <p class="text-gray-500 text-sm mt-1"><?= date('F j, Y') ?> · For detailed exports, use Operational Reports</p>
            </div>

            <?php if ($pendingCount > 0 || $readyCount > 0 || $queueWaiting > 0): ?>
            <div class="bg-amber-50 border border-amber-100 rounded-xl px-4 py-3 flex flex-wrap items-center gap-2 text-sm">
                <span class="font-semibold text-amber-900 mr-1">Needs attention:</span>
                <?php if ($pendingCount > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'pending'])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-2.5 py-1 rounded-lg hover:bg-amber-100"><?= $pendingCount ?> pending</a>
                <?php endif; ?>
                <?php if ($readyCount > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'ready'])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-2.5 py-1 rounded-lg hover:bg-amber-100"><?= $readyCount ?> ready</a>
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

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-xl border border-gray-100 p-5">
                    <h2 class="text-sm font-bold text-slate-900">Request pipeline</h2>
                    <p class="text-[11px] text-gray-400 mb-3">All requests by stage</p>
                    <?php if ($totalRequests === 0): ?>
                    <p class="text-sm text-gray-400 py-14 text-center">No requests yet.</p>
                    <?php else: ?>
                    <div class="chart-box"><canvas id="chartPipeline"></canvas></div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-5">
                    <h2 class="text-sm font-bold text-slate-900">Monthly requests</h2>
                    <p class="text-[11px] text-gray-400 mb-3">Last 6 months</p>
                    <?php if ($maxMonth === 0): ?>
                    <p class="text-sm text-gray-400 py-14 text-center">No data yet.</p>
                    <?php else: ?>
                    <div class="chart-box"><canvas id="chartMonths"></canvas></div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-5">
                    <h2 class="text-sm font-bold text-slate-900">Appointments</h2>
                    <p class="text-[11px] text-gray-400 mb-3">All bookings by status</p>
                    <?php if ($apptTotal === 0): ?>
                    <p class="text-sm text-gray-400 py-14 text-center">No appointments yet.</p>
                    <?php else: ?>
                    <div class="chart-box"><canvas id="chartAppointments"></canvas></div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-xl border border-gray-100 p-5 flex flex-col justify-center">
                    <h2 class="text-sm font-bold text-slate-900">Queue today</h2>
                    <p class="text-[11px] text-gray-400 mb-4">Live ticket counts</p>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-center">
                        <div class="rounded-lg bg-amber-50 py-4 px-2">
                            <p class="text-2xl font-black text-amber-700"><?= $queueWaiting ?></p>
                            <p class="text-[10px] font-bold text-amber-800/70 mt-1 uppercase">Waiting</p>
                        </div>
                        <div class="rounded-lg bg-blue-50 py-4 px-2">
                            <p class="text-2xl font-black text-blue-700"><?= $queueServing ?></p>
                            <p class="text-[10px] font-bold text-blue-800/70 mt-1 uppercase">Serving</p>
                        </div>
                        <div class="rounded-lg bg-emerald-50 py-4 px-2">
                            <p class="text-2xl font-black text-emerald-700"><?= $queueServed ?></p>
                            <p class="text-[10px] font-bold text-emerald-800/70 mt-1 uppercase">Done</p>
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
