<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/api_helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('dashboard.php');

$activePage = 'dashboard.php';
$pdo = getDB();
ensureCitizenNotifyColumns($pdo);
ensureSoftDeleteColumns($pdo);
$isAdminUser = isAdmin();
$staffDisplayName = staffName();
$staffRole = staffRole();
$todayLabel = date('l, F j, Y');

$pageTitle = 'Dashboard';
$pageSubtitle = $isAdminUser
    ? 'Overview of requests, queue activity, and appointments for your office.'
    : 'Your daily queue, requests, and appointment summary.';

$todayDate = alcrosTodayDate();
$pendingCount  = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status IN ('pending','verified')")->fetchColumn();
$queueCount    = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn();
$todayAppts    = countTodaySpecialAppointments($pdo);
$readyCount    = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'ready'")->fetchColumn();

$recentRequests = enrichCitizenNameRows($pdo->query(
    "SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at
     FROM document_requests ORDER BY submitted_at DESC LIMIT 6"
)->fetchAll());

if ($isAdminUser) {
    $activities = $pdo->query(
        "SELECT staff_id, action, details, created_at FROM activity_logs ORDER BY created_at DESC LIMIT 6"
    )->fetchAll();
} else {
    $activityStmt = $pdo->prepare(
        "SELECT staff_id, action, details, created_at FROM activity_logs WHERE staff_id = ? ORDER BY created_at DESC LIMIT 6"
    );
    $activityStmt->execute([staffId()]);
    $activities = $activityStmt->fetchAll();
}

$scheduleMonth = date('Y-m');
$scheduleDate = $todayDate;
$scheduleCalendar = fetchDashboardSchedule($pdo, $scheduleDate, $scheduleMonth);
$incomingDate = incomingAppointmentsDate();
$incomingAppointments = fetchIncomingAppointments($pdo, 8);

$quickActions = [
    ['href' => 'manage_request.php', 'label' => 'Manage Requests', 'desc' => 'Review & update statuses', 'icon' => 'file-text', 'color' => 'bg-blue-50 text-blue-600'],
    ['href' => 'live-queue.php',     'label' => 'Live Queue',     'desc' => 'Serve waiting citizens',  'icon' => 'users',      'color' => 'bg-purple-50 text-purple-600'],
    ['href' => 'appointment.php',   'label' => 'Appointments',   'desc' => "Today's schedule",        'icon' => 'calendar',   'color' => 'bg-teal-50 text-teal-600', 'query' => ['date' => $todayDate]],
    ['href' => 'records.php',        'label' => 'Civil Records',  'desc' => 'Search registry files',   'icon' => 'book-open',  'color' => 'bg-orange-50 text-orange-600'],
];

if ($isAdminUser) {
    $quickActions[] = ['href' => 'report.php', 'label' => 'Reports', 'desc' => 'Charts, stats & exports', 'icon' => 'bar-chart-2', 'color' => 'bg-slate-100 text-slate-600', 'query' => ['section' => 'analytics']];
    $quickActions[] = ['href' => 'system_settings.php', 'label' => 'Settings', 'desc' => 'System config', 'icon' => 'settings', 'color' => 'bg-slate-100 text-slate-600'];
}

function dashboardCountBetween(PDO $pdo, string $sql, string $start, string $end): int
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start, $end]);

    return (int) $stmt->fetchColumn();
}

function dashboardTrendPercent(int $current, int $previous): array
{
    if ($current === 0 && $previous === 0) {
        return ['pct' => 0, 'up' => true];
    }
    if ($previous === 0) {
        return ['pct' => 100, 'up' => $current > 0];
    }

    $change = (($current - $previous) / $previous) * 100;

    return ['pct' => max(0, (int) round(abs($change))), 'up' => $change >= 0];
}

$periodCurStart = date('Y-m-d 00:00:00', strtotime('-7 days'));
$periodCurEnd = date('Y-m-d 00:00:00', strtotime('+1 day'));
$periodPrevStart = date('Y-m-d 00:00:00', strtotime('-14 days'));
$periodPrevEnd = $periodCurStart;
$weekAgoDate = date('Y-m-d', strtotime($todayDate . ' -7 days'));

$statCards = [
    [
        'id' => 'stat-pending',
        'label' => 'Needs Review',
        'value' => $pendingCount,
        'icon' => 'clipboard-list',
        'tone' => 'amber',
        'page' => 'manage_request.php',
        'query' => ['status' => 'pending'],
        'trend' => dashboardTrendPercent(
            dashboardCountBetween($pdo, 'SELECT COUNT(*) FROM document_requests WHERE submitted_at >= ? AND submitted_at < ?', $periodCurStart, $periodCurEnd),
            dashboardCountBetween($pdo, 'SELECT COUNT(*) FROM document_requests WHERE submitted_at >= ? AND submitted_at < ?', $periodPrevStart, $periodPrevEnd)
        ),
    ],
    [
        'id' => 'stat-queue',
        'label' => 'Queue Waiting',
        'value' => $queueCount,
        'icon' => 'users',
        'tone' => 'blue',
        'page' => 'live-queue.php',
        'query' => [],
        'trend' => dashboardTrendPercent(
            dashboardCountBetween($pdo, 'SELECT COUNT(*) FROM queue_tickets WHERE created_at >= ? AND created_at < ?', $periodCurStart, $periodCurEnd),
            dashboardCountBetween($pdo, 'SELECT COUNT(*) FROM queue_tickets WHERE created_at >= ? AND created_at < ?', $periodPrevStart, $periodPrevEnd)
        ),
    ],
    [
        'id' => 'stat-appts',
        'label' => "Today's Appointments",
        'value' => $todayAppts,
        'icon' => 'calendar',
        'tone' => 'violet',
        'page' => 'appointment.php',
        'query' => ['date' => $todayDate, 'status' => 'all_appointments'],
        'trend' => dashboardTrendPercent($todayAppts, countSpecialAppointmentsOnDate($pdo, $weekAgoDate)),
    ],
    [
        'id' => 'stat-ready',
        'label' => 'Ready for Pickup',
        'value' => $readyCount,
        'icon' => 'package',
        'tone' => 'emerald',
        'page' => 'manage_request.php',
        'query' => ['status' => 'ready'],
        'trend' => dashboardTrendPercent(
            dashboardCountBetween($pdo, "SELECT COUNT(*) FROM document_requests WHERE status = 'ready' AND updated_at >= ? AND updated_at < ?", $periodCurStart, $periodCurEnd),
            dashboardCountBetween($pdo, "SELECT COUNT(*) FROM document_requests WHERE status = 'ready' AND updated_at >= ? AND updated_at < ?", $periodPrevStart, $periodPrevEnd)
        ),
    ],
];

function activityIcon(string $action): string
{
    return match (true) {
        str_contains($action, 'Login')    => 'log-in',
        str_contains($action, 'Created')    => 'plus-circle',
        str_contains($action, 'Updated')  => 'pencil',
        str_contains($action, 'Deleted')  => 'trash-2',
        str_contains($action, 'Import')   => 'upload',
        str_contains($action, 'Password') => 'key',
        default                             => 'activity',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorStylesheetTag('inter/inter.css') ?>
    <?= adminLayoutHeadStyles('dashboard') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen" data-realtime="dashboard" data-admin="<?= $isAdminUser ? '1' : '0' ?>">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="admin-content p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap space-y-6">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div class="flex items-start gap-4">
                    <?= alcrosFaviconImg(64, 'dash-brand-logo shrink-0') ?>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-blue-600 mb-1"><?= $isAdminUser ? 'Registry Admin' : 'Staff Portal' ?></p>
                        <h1 class="text-2xl lg:text-3xl font-black text-slate-900">Good day, <?= htmlspecialchars(explode(' ', $staffDisplayName)[0]) ?>!</h1>
                        <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($todayLabel) ?> · <?= htmlspecialchars(staffId()) ?> · <?= htmlspecialchars($staffRole) ?></p>
                    </div>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
                <?php foreach ($statCards as $card): ?>
                <?php $trend = $card['trend']; ?>
                <a href="<?= htmlspecialchars(buildAuthUrl($card['page'], $card['query'])) ?>"
                   class="dash-stat-card dash-stat-card--<?= htmlspecialchars($card['tone']) ?>">
                    <div class="dash-stat-card__head">
                        <span class="dash-stat-card__icon">
                            <i data-lucide="<?= htmlspecialchars($card['icon']) ?>" class="w-4 h-4"></i>
                        </span>
                        <span class="dash-stat-card__label"><?= htmlspecialchars($card['label']) ?></span>
                    </div>
                    <p id="<?= htmlspecialchars($card['id']) ?>" class="dash-stat-card__value"><?= number_format($card['value']) ?></p>
                    <p class="dash-stat-card__trend">
                        <span class="dash-stat-card__trend-value dash-stat-card__trend-value--<?= $trend['up'] ? 'up' : 'down' ?>">
                            <?= $trend['up'] ? '↑' : '↓' ?> <?= (int) $trend['pct'] ?>%
                        </span>
                        <span class="dash-stat-card__trend-note">vs. last 7 days</span>
                    </p>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Quick actions -->
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Quick Actions</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
                    <?php foreach ($quickActions as $action): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl($action['href'], $action['query'] ?? [])) ?>" class="dash-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 flex items-start gap-3">
                        <div class="p-2 rounded-lg shrink-0 <?= $action['color'] ?>">
                            <i data-lucide="<?= $action['icon'] ?>" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($action['label']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($action['desc']) ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Main content grid -->
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">

                <!-- Recent requests -->
                <div class="xl:col-span-2 bg-white rounded-2xl border border-gray-100 dash-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-sm text-slate-900">Recent Requests</h2>
                            <p class="text-[10px] text-gray-400 mt-0.5">Latest citizen document submissions</p>
                        </div>
                        <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="text-blue-600 text-[10px] font-bold uppercase hover:underline">View all</a>
                    </div>
                    <?php if (empty($recentRequests)): ?>
                    <div class="p-12 text-center">
                        <div class="bg-gray-50 w-12 h-12 rounded-xl flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="inbox" class="w-6 h-6 text-gray-300"></i>
                        </div>
                        <p class="text-sm font-semibold text-slate-700">No requests yet</p>
                        <p class="text-xs text-gray-400 mt-1">New submissions will appear here.</p>
                    </div>
                    <?php else: ?>
                    <div id="recent-requests-list" class="divide-y divide-gray-50">
                        <?php foreach ($recentRequests as $req): ?>
                        <div class="px-5 py-3.5 flex items-center justify-between gap-4 hover:bg-gray-50/50">
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($req['citizen_name']) ?></p>
                                <p class="text-[11px] text-gray-400 mt-0.5">
                                    <?= htmlspecialchars(documentTypeLabel($req['document_type'])) ?>
                                    · <span class="font-mono text-blue-600"><?= htmlspecialchars($req['tracking_code']) ?></span>
                                    · <?= formatRecordDate(substr($req['submitted_at'], 0, 10)) ?>
                                </p>
                            </div>
                            <?= requestStatusBadge($req['status']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Today's schedule + incoming -->
                <div class="space-y-6">
                <div class="bg-white rounded-2xl border border-gray-100 dash-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-sm text-slate-900">Today's Schedule</h2>
                            <p class="text-[10px] text-gray-400 mt-0.5"><span id="dash-schedule-count"><?= count($scheduleCalendar['appointments']) ?></span> scheduled visit(s) · <span id="dash-schedule-date-label"><?= formatDateDisplay($scheduleDate) ?></span></p>
                        </div>
                        <a id="dash-schedule-open-link" href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $scheduleDate])) ?>" class="text-blue-600 text-[10px] font-bold uppercase hover:underline">Open</a>
                    </div>

                    <div class="px-4 pt-4 pb-3 border-b border-gray-50">
                        <div class="dash-schedule-calendar" id="dashScheduleCalendar">
                            <div class="dash-cal-head">
                                <button type="button" id="dashCalPrev" class="dash-cal-nav" aria-label="Previous month">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </button>
                                <p id="dashCalMonthLabel" class="dash-cal-month"></p>
                                <button type="button" id="dashCalNext" class="dash-cal-nav" aria-label="Next month">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </button>
                            </div>
                            <div class="dash-cal-weekdays">
                                <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                            </div>
                            <div id="dashCalGrid" class="dash-cal-grid" role="grid" aria-label="Appointment calendar"></div>
                        </div>
                    </div>

                    <?php if (empty($scheduleCalendar['appointments'])): ?>
                    <div id="dash-schedule-empty" class="p-10 text-center">
                        <i data-lucide="calendar-off" class="w-8 h-8 text-gray-200 mx-auto mb-2"></i>
                        <p class="text-xs text-gray-400">No visits scheduled for this date.</p>
                    </div>
                    <div id="today-appts-list" class="divide-y divide-gray-50 max-h-[240px] overflow-y-auto hidden"></div>
                    <?php else: ?>
                    <div id="dash-schedule-empty" class="p-10 text-center hidden">
                        <i data-lucide="calendar-off" class="w-8 h-8 text-gray-200 mx-auto mb-2"></i>
                        <p class="text-xs text-gray-400">No visits scheduled for this date.</p>
                    </div>
                    <div id="today-appts-list" class="divide-y divide-gray-50 max-h-[240px] overflow-y-auto">
                        <?php foreach ($scheduleCalendar['appointments'] as $ap): ?>
                        <?php
                        $isCertificate = ($ap['schedule_kind'] ?? '') === 'certificate';
                        [$visitPath, $visitQuery] = scheduleVisitLinkParams($ap, $scheduleDate);
                        ?>
                        <a href="<?= htmlspecialchars(buildAuthUrl($visitPath, $visitQuery)) ?>" class="dash-schedule-row px-5 py-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?= $isCertificate ? 'bg-amber-50 text-amber-700' : 'bg-blue-50 text-blue-600' ?> flex flex-col items-center justify-center shrink-0 leading-none">
                                <span class="text-[9px] font-bold"><?= date('g:i', strtotime($ap['appointment_time'])) ?></span>
                                <span class="text-[8px] uppercase"><?= date('A', strtotime($ap['appointment_time'])) ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($ap['citizen_name']) ?></p>
                                <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($ap['service_type']) ?></p>
                            </div>
                            <span class="text-[9px] font-bold uppercase text-gray-400 shrink-0"><?= htmlspecialchars($ap['status']) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="bg-white rounded-2xl border border-gray-100 dash-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-sm text-slate-900">Incoming Appointments</h2>
                            <p class="text-[10px] text-gray-400 mt-0.5"><span id="incoming-appts-count"><?= count($incomingAppointments) ?></span> appointment(s) · Tomorrow · <span id="incoming-appts-date"><?= formatDateDisplay($incomingDate) ?></span></p>
                        </div>
                        <a id="incoming-appts-open-link" href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $incomingDate, 'status' => 'all_appointments'])) ?>" class="text-blue-600 text-[10px] font-bold uppercase hover:underline">Open</a>
                    </div>

                    <?php if (empty($incomingAppointments)): ?>
                    <div id="incoming-appts-empty" class="p-8 text-center">
                        <i data-lucide="calendar-clock" class="w-8 h-8 text-gray-200 mx-auto mb-2"></i>
                        <p class="text-xs text-gray-400">No appointments scheduled for tomorrow.</p>
                    </div>
                    <div id="incoming-appts-list" class="divide-y divide-gray-50 max-h-[220px] overflow-y-auto hidden"></div>
                    <?php else: ?>
                    <div id="incoming-appts-empty" class="p-8 text-center hidden">
                        <i data-lucide="calendar-clock" class="w-8 h-8 text-gray-200 mx-auto mb-2"></i>
                        <p class="text-xs text-gray-400">No appointments scheduled for tomorrow.</p>
                    </div>
                    <div id="incoming-appts-list" class="divide-y divide-gray-50 max-h-[220px] overflow-y-auto">
                        <?php foreach ($incomingAppointments as $ap): ?>
                        <?php
                        $isCertificate = ($ap['schedule_kind'] ?? '') === 'certificate';
                        [$visitPath, $visitQuery] = scheduleVisitLinkParams($ap, $incomingDate);
                        ?>
                        <a href="<?= htmlspecialchars(buildAuthUrl($visitPath, $visitQuery)) ?>" class="dash-schedule-row px-5 py-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg <?= $isCertificate ? 'bg-amber-50 text-amber-700' : 'bg-violet-50 text-violet-600' ?> flex flex-col items-center justify-center shrink-0 leading-none">
                                <span class="text-[9px] font-bold"><?= date('g:i', strtotime($ap['appointment_time'])) ?></span>
                                <span class="text-[8px] uppercase"><?= date('A', strtotime($ap['appointment_time'])) ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($ap['citizen_name']) ?></p>
                                <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($ap['service_type']) ?></p>
                            </div>
                            <span class="text-[9px] font-bold uppercase text-gray-400 shrink-0"><?= htmlspecialchars($ap['status']) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                </div>
            </div>

            <!-- Activity -->
            <div class="bg-white rounded-2xl border border-gray-100 dash-card overflow-hidden">
                <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
                    <div>
                        <h2 class="font-bold text-sm text-slate-900"><?= $isAdminUser ? 'System Activity' : 'My Recent Activity' ?></h2>
                        <p class="text-[10px] text-gray-400 mt-0.5"><?= $isAdminUser ? 'Latest actions across the registry office' : 'Your recent actions in the portal' ?></p>
                    </div>
                    <?php if ($isAdminUser): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('activity-log.php')) ?>" class="text-blue-600 text-[10px] font-bold uppercase hover:underline">Full log</a>
                    <?php endif; ?>
                </div>
                <?php if (empty($activities)): ?>
                <div class="p-10 text-center text-gray-400 text-xs">No activity recorded yet.</div>
                <?php else: ?>
                <div id="activity-feed-list" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 p-4">
                    <?php foreach ($activities as $act): ?>
                    <div class="flex items-start gap-3 p-3 rounded-xl bg-gray-50/80 border border-gray-100">
                        <div class="bg-white p-2 rounded-lg text-blue-600 border border-gray-100 shrink-0">
                            <i data-lucide="<?= activityIcon($act['action']) ?>" class="w-4 h-4"></i>
                        </div>
                        <div class="min-w-0">
                            <p class="text-xs font-bold text-slate-800"><?= htmlspecialchars($act['action']) ?></p>
                            <?php if (!empty($act['details'])): ?>
                            <p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2"><?= htmlspecialchars($act['details']) ?></p>
                            <?php endif; ?>
                            <p class="text-[10px] text-gray-400 mt-1">
                                <?= $isAdminUser ? htmlspecialchars($act['staff_id'] ?? 'System') . ' · ' : '' ?><?= formatTimeAgo($act['created_at']) ?>
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

        </div>
    </main>

    <?= pageConfigJson([
        'scheduleMonth'     => $scheduleMonth,
        'scheduleDate'      => $scheduleDate,
        'todayDate'         => $todayDate,
        'appointmentDates'  => $scheduleCalendar['dates'],
        'appointmentPage'   => buildAuthUrl('appointment.php'),
    ], 'dashboard-schedule-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/dashboard.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
