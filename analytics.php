<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
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

function analyticsPct(int $part, int $total): int
{
    return $total > 0 ? (int) round(($part / $total) * 100) : 0;
}

$totalRequests = (int) $pdo->query('SELECT COUNT(*) FROM document_requests')->fetchColumn();
$todayRequests = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE DATE(submitted_at) = CURDATE()")->fetchColumn();
$weekRequests  = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)")->fetchColumn();

$statusMap = analyticsCountMap(
    $pdo->query("SELECT status, COUNT(*) AS cnt FROM document_requests GROUP BY status")->fetchAll(),
    'status'
);
$typeMap = analyticsCountMap(
    $pdo->query("SELECT document_type, COUNT(*) AS cnt FROM document_requests GROUP BY document_type")->fetchAll(),
    'document_type'
);

$pendingCount   = (int) ($statusMap['pending'] ?? 0);
$verifiedCount  = (int) ($statusMap['verified'] ?? 0) + (int) ($statusMap['processing'] ?? 0);
$readyCount     = (int) ($statusMap['ready'] ?? 0);
$completedCount = (int) ($statusMap['completed'] ?? 0);
$rejectedCount  = (int) ($statusMap['rejected'] ?? 0);

$monthRows = $pdo->query(
    "SELECT DATE_FORMAT(submitted_at, '%Y-%m') AS month, COUNT(*) AS cnt
     FROM document_requests
     WHERE submitted_at >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
     GROUP BY month
     ORDER BY month ASC"
)->fetchAll();
$monthCounts = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $monthCounts[$key] = 0;
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
$recordsTotal = (int) $pdo->query('SELECT COUNT(*) FROM civil_records')->fetchColumn();

$pipeline = [
    ['key' => 'pending',   'count' => $pendingCount,   'icon' => 'clock',          'bar' => 'bg-amber-400',   'bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'hint' => 'Needs staff review'],
    ['key' => 'verified',  'count' => $verifiedCount,  'icon' => 'shield-check',    'bar' => 'bg-blue-500',    'bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'hint' => 'Being prepared'],
    ['key' => 'ready',     'count' => $readyCount,     'icon' => 'package',        'bar' => 'bg-emerald-500', 'bg' => 'bg-emerald-50', 'text' => 'text-emerald-700', 'hint' => 'Waiting for pickup'],
    ['key' => 'completed', 'count' => $completedCount, 'icon' => 'circle-check',   'bar' => 'bg-slate-500',   'bg' => 'bg-slate-50',   'text' => 'text-slate-700',   'hint' => 'Finished and released'],
];

$typeRows = [];
foreach (getDocumentTypes() as $type) {
    $count = (int) ($typeMap[$type['slug']] ?? 0);
    $typeRows[] = [
        'label' => $type['label'],
        'count' => $count,
        'pct'   => analyticsPct($count, $totalRequests),
        'icon'  => $type['icon'],
    ];
}
if (isset($typeMap['cenomar'])) {
    $count = (int) $typeMap['cenomar'];
    $typeRows[] = [
        'label' => documentTypeLabel('cenomar'),
        'count' => $count,
        'pct'   => analyticsPct($count, $totalRequests),
        'icon'  => 'file-text',
    ];
}

$apptStatuses = [
    'scheduled' => 'Scheduled',
    'confirmed' => 'Confirmed',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
    'no_show'   => 'No show',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .sidebar-item:hover { background-color: #f1f5f9; }
        .active-nav { background-color: #2563eb; color: white !important; }
        .stat-card { box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .stat-card:hover { box-shadow: 0 4px 12px rgba(15, 23, 42, 0.06); }
    </style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="admin-content p-6 lg:p-8 max-w-7xl w-full mx-auto space-y-8">

            <section id="overview">
                <div class="mb-6">
                    <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                        <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                    </a>
                    <h1 class="text-2xl lg:text-3xl font-black text-slate-900 tracking-tight">Analytics</h1>
                    <p class="text-gray-500 text-sm font-medium mt-1">Simple counts of requests, appointments, and today’s queue. <?= date('F j, Y') ?></p>
                </div>

                <?php if ($pendingCount > 0 || $readyCount > 0 || $queueWaiting > 0): ?>
                <div class="mb-6 bg-amber-50 border border-amber-100 rounded-2xl p-4 flex flex-wrap items-center gap-3">
                    <div class="bg-white text-amber-600 p-2 rounded-xl"><i data-lucide="bell" class="w-4 h-4"></i></div>
                    <p class="text-sm font-semibold text-amber-900 flex-1 min-w-[200px]">Needs attention today</p>
                    <?php if ($pendingCount > 0): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'pending'])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-3 py-1.5 rounded-lg hover:bg-amber-100"><?= $pendingCount ?> pending review</a>
                    <?php endif; ?>
                    <?php if ($readyCount > 0): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'ready'])) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-3 py-1.5 rounded-lg hover:bg-amber-100"><?= $readyCount ?> ready for pickup</a>
                    <?php endif; ?>
                    <?php if ($queueWaiting > 0): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="text-xs font-bold bg-white border border-amber-200 text-amber-800 px-3 py-1.5 rounded-lg hover:bg-amber-100"><?= $queueWaiting ?> waiting in queue</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4">
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="stat-card bg-white rounded-2xl border border-gray-100 p-5 hover:border-blue-200 block">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">All requests</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $totalRequests ?></p>
                        <p class="text-[11px] text-gray-500 mt-1"><?= $todayRequests ?> submitted today · <?= $weekRequests ?> this week</p>
                    </a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php')) ?>" class="stat-card bg-white rounded-2xl border border-gray-100 p-5 hover:border-blue-200 block">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Appointments</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $apptToday ?></p>
                        <p class="text-[11px] text-gray-500 mt-1">Scheduled for today · <?= $apptTotal ?> all-time</p>
                    </a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="stat-card bg-white rounded-2xl border border-gray-100 p-5 hover:border-blue-200 block">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Queue now</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $queueWaiting ?></p>
                        <p class="text-[11px] text-gray-500 mt-1"><?= $queueServing ?> being served · <?= $queueServed ?> finished today</p>
                    </a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('records.php')) ?>" class="stat-card bg-white rounded-2xl border border-gray-100 p-5 hover:border-blue-200 block">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Civil records</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $recordsTotal ?></p>
                        <p class="text-[11px] text-gray-500 mt-1">Stored in the registry</p>
                    </a>
                </div>
            </section>

            <section id="requests" class="space-y-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">Document requests</h2>
                        <p class="text-sm text-gray-500">Where each request is in the process. Click a stage to open that list.</p>
                    </div>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="text-blue-600 text-[11px] font-bold uppercase hover:underline shrink-0">Manage requests</a>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                    <?php foreach ($pipeline as $step): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => $step['key']])) ?>" class="stat-card bg-white rounded-2xl border border-gray-100 p-4 hover:border-blue-200 block">
                        <div class="flex items-center justify-between mb-3">
                            <div class="p-2 rounded-lg <?= $step['bg'] ?> <?= $step['text'] ?>">
                                <i data-lucide="<?= $step['icon'] ?>" class="w-4 h-4"></i>
                            </div>
                            <span class="text-[10px] font-bold text-gray-400"><?= analyticsPct($step['count'], $totalRequests) ?>%</span>
                        </div>
                        <p class="text-2xl font-black text-slate-900"><?= $step['count'] ?></p>
                        <p class="text-xs font-bold text-slate-700 mt-1"><?= htmlspecialchars(requestStatusLabel($step['key'])) ?></p>
                        <p class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($step['hint']) ?></p>
                        <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full <?= $step['bar'] ?> rounded-full" style="width: <?= analyticsPct($step['count'], $totalRequests) ?>%"></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($rejectedCount > 0): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'rejected'])) ?>" class="stat-card flex items-center justify-between bg-white border border-gray-100 rounded-2xl px-5 py-3 hover:border-red-200">
                    <span class="text-sm font-semibold text-slate-700">Rejected requests</span>
                    <span class="text-sm font-black text-red-600"><?= $rejectedCount ?></span>
                </a>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div class="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 class="font-bold text-sm text-slate-900 mb-1">By document type</h3>
                        <p class="text-[11px] text-gray-400 mb-4">What citizens are requesting most</p>
                        <?php if ($totalRequests === 0): ?>
                        <p class="text-sm text-gray-400 py-8 text-center">No requests yet.</p>
                        <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($typeRows as $row): ?>
                            <div>
                                <div class="flex items-center justify-between text-sm mb-1.5">
                                    <span class="font-medium text-slate-700"><?= htmlspecialchars($row['label']) ?></span>
                                    <span class="font-bold text-slate-900"><?= $row['count'] ?> <span class="text-[10px] font-semibold text-gray-400"><?= $row['pct'] ?>%</span></span>
                                </div>
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full bg-blue-500 rounded-full" style="width: <?= $row['pct'] ?>%"></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="bg-white rounded-2xl border border-gray-100 p-5">
                        <h3 class="font-bold text-sm text-slate-900 mb-1">Last 6 months</h3>
                        <p class="text-[11px] text-gray-400 mb-4">How many requests were submitted each month</p>
                        <?php if ($maxMonth === 0): ?>
                        <p class="text-sm text-gray-400 py-8 text-center">No submissions in this period.</p>
                        <?php else: ?>
                        <div class="flex items-end gap-2 h-40">
                            <?php foreach ($monthCounts as $monthKey => $count): ?>
                            <?php $height = (int) round(($count / $maxMonth) * 100); ?>
                            <div class="flex-1 flex flex-col items-center h-full justify-end">
                                <span class="text-[10px] font-bold text-slate-700 mb-1"><?= $count ?></span>
                                <div class="w-full bg-blue-500 rounded-t-md min-h-[4px]" style="height: <?= max($height, $count > 0 ? 8 : 4) ?>%"></div>
                                <span class="text-[9px] font-bold text-gray-400 mt-2"><?= date('M', strtotime($monthKey . '-01')) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section id="appointments" class="space-y-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">Appointments</h2>
                        <p class="text-sm text-gray-500">Today’s schedule and overall booking status.</p>
                    </div>
                    <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php')) ?>" class="text-blue-600 text-[11px] font-bold uppercase hover:underline shrink-0">Open calendar</a>
                </div>
                <div class="bg-white rounded-2xl border border-gray-100 p-5">
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                        <?php foreach ($apptStatuses as $key => $label): ?>
                        <?php $count = (int) ($apptMap[$key] ?? 0); ?>
                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-4">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= htmlspecialchars($label) ?></p>
                            <p class="text-2xl font-black text-slate-900 mt-1"><?= $count ?></p>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-gray-500 mt-4"><?= $apptToday ?> appointment<?= $apptToday === 1 ? '' : 's' ?> on the calendar for today.</p>
                </div>
            </section>

            <section id="queue" class="space-y-4">
                <div class="flex items-end justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-black text-slate-900">Live queue today</h2>
                        <p class="text-sm text-gray-500">Walk-in and appointment tickets issued today.</p>
                    </div>
                    <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="text-blue-600 text-[11px] font-bold uppercase hover:underline shrink-0">Open live queue</a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="bg-white rounded-2xl border border-gray-100 p-5">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Waiting</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $queueWaiting ?></p>
                        <p class="text-[11px] text-gray-500 mt-1">Citizens still in line</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-gray-100 p-5">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Now serving</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $queueServing ?></p>
                        <p class="text-[11px] text-gray-500 mt-1">At a window right now</p>
                    </div>
                    <div class="bg-white rounded-2xl border border-gray-100 p-5">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Served today</p>
                        <p class="text-3xl font-black text-slate-900 mt-2"><?= $queueServed ?></p>
                        <p class="text-[11px] text-gray-500 mt-1">Tickets already completed</p>
                    </div>
                </div>
            </section>

        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
