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
$isAdminUser = isAdmin();
$staffDisplayName = staffName();
$staffRole = staffRole();
$todayLabel = date('l, F j, Y');

$pendingCount  = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status IN ('pending','verified')")->fetchColumn();
$queueCount    = (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'waiting' AND DATE(created_at) = CURDATE()")->fetchColumn();
$todayAppts    = (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn();
$pipelineCount = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'verified'")->fetchColumn();
$readyCount    = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'ready'")->fetchColumn();
$completedToday = (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'completed' AND DATE(updated_at) = CURDATE()")->fetchColumn();

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
$scheduleDate = date('Y-m-d');
$scheduleCalendar = fetchDashboardSchedule($pdo, $scheduleDate, $scheduleMonth);

$quickActions = [
    ['href' => 'manage_request.php', 'label' => 'Manage Requests', 'desc' => 'Review & update statuses', 'icon' => 'file-text', 'color' => 'bg-blue-50 text-blue-600'],
    ['href' => 'live-queue.php',     'label' => 'Live Queue',     'desc' => 'Serve waiting citizens',  'icon' => 'users',      'color' => 'bg-purple-50 text-purple-600'],
    ['href' => 'appointment.php',   'label' => 'Appointments',   'desc' => "Today's schedule",        'icon' => 'calendar',   'color' => 'bg-teal-50 text-teal-600'],
    ['href' => 'records.php',        'label' => 'Civil Records',  'desc' => 'Search registry files',   'icon' => 'book-open',  'color' => 'bg-orange-50 text-orange-600'],
];

if ($isAdminUser) {
    $quickActions[] = ['href' => 'analytics.php', 'label' => 'Analytics', 'desc' => 'Reports & trends', 'icon' => 'bar-chart-2', 'color' => 'bg-slate-100 text-slate-600'];
    $quickActions[] = ['href' => 'system_settings.php', 'label' => 'Settings', 'desc' => 'System config', 'icon' => 'settings', 'color' => 'bg-slate-100 text-slate-600'];
}

$statCards = [
    ['id' => 'stat-pending',  'label' => 'Needs Review',      'hint' => 'Pending & verified',     'value' => $pendingCount,  'icon' => 'clipboard-list', 'accent' => 'border-amber-400', 'iconColor' => 'text-amber-500', 'href' => 'manage_request.php?status=pending'],
    ['id' => 'stat-queue',    'label' => 'Queue Waiting',     'hint' => 'Citizens in line today', 'value' => $queueCount,    'icon' => 'users',          'accent' => 'border-violet-400', 'iconColor' => 'text-violet-500', 'href' => 'live-queue.php'],
    ['id' => 'stat-appts',    'label' => "Today's Appointments", 'hint' => 'Scheduled for today', 'value' => $todayAppts,    'icon' => 'calendar',       'accent' => 'border-blue-400',  'iconColor' => 'text-blue-500',   'href' => 'appointment.php'],
    ['id' => 'stat-pipeline', 'label' => 'Verified',          'hint' => 'Being prepared',       'value' => $pipelineCount, 'icon' => 'loader-2',       'accent' => 'border-indigo-400', 'iconColor' => 'text-indigo-500', 'href' => 'manage_request.php?status=verified'],
    ['id' => 'stat-ready',    'label' => 'Ready for Pickup',  'hint' => 'Awaiting release',     'value' => $readyCount,    'icon' => 'package',        'accent' => 'border-emerald-400', 'iconColor' => 'text-emerald-500', 'href' => 'appointment.php?date=' . date('Y-m-d')],
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
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?= adminLayoutHeadStyles('dashboard') ?>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex min-h-screen" data-realtime="dashboard" data-admin="<?= $isAdminUser ? '1' : '0' ?>">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="admin-content p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap space-y-6">

            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                <div class="flex items-start gap-4">
                    <?= renderStaffAvatar(staffPhotoPath(), $staffDisplayName, 'w-16 h-16 text-xl', 'rounded-2xl') ?>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-blue-600 mb-1"><?= $isAdminUser ? 'Registry Admin' : 'Staff Portal' ?></p>
                        <h1 class="text-2xl lg:text-3xl font-black text-slate-900">Good day, <?= htmlspecialchars(explode(' ', $staffDisplayName)[0]) ?>!</h1>
                        <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($todayLabel) ?> · <?= htmlspecialchars(staffId()) ?> · <?= htmlspecialchars($staffRole) ?></p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold inline-flex items-center gap-2">
                        <i data-lucide="file-text" class="w-3.5 h-3.5"></i> Manage Requests
                    </a>
                    <a href="<?= htmlspecialchars(buildAuthUrl('live-queue.php')) ?>" class="bg-white border border-gray-200 hover:border-blue-300 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold inline-flex items-center gap-2">
                        <i data-lucide="users" class="w-3.5 h-3.5"></i> Open Queue
                    </a>
                </div>
            </div>

            <!-- Live status strip -->
            <div class="bg-white border border-gray-100 rounded-2xl px-5 py-4 flex flex-wrap items-center gap-6 dash-card">
                <div class="flex items-center gap-2">
                    <span class="relative flex h-2.5 w-2.5">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-60"></span>
                        <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-green-500"></span>
                    </span>
                    <span class="text-xs font-semibold text-slate-700">System online</span>
                </div>
                <div class="h-4 w-px bg-gray-200 hidden sm:block"></div>
                <div class="text-xs text-gray-500"><span class="font-bold text-slate-800" id="cmd-queue-count"><?= $queueCount ?></span> in queue</div>
                <div class="text-xs text-gray-500"><span class="font-bold text-slate-800" id="cmd-appts-count"><?= $todayAppts ?></span> appointments today</div>
                <div class="text-xs text-gray-500"><span class="font-bold text-emerald-600"><?= $completedToday ?></span> completed today</div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 lg:gap-4">
                <?php foreach ($statCards as $card): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl($card['href'])) ?>" class="stat-link dash-card bg-white rounded-2xl border border-gray-100 border-l-4 <?= $card['accent'] ?> p-4 block">
                    <div class="flex items-start justify-between mb-3">
                        <div class="p-2 rounded-lg bg-gray-50 <?= $card['iconColor'] ?>">
                            <i data-lucide="<?= $card['icon'] ?>" class="w-4 h-4"></i>
                        </div>
                    </div>
                    <p id="<?= $card['id'] ?>" class="text-2xl lg:text-3xl font-black text-slate-900 leading-none"><?= $card['value'] ?></p>
                    <p class="text-[11px] font-bold text-slate-700 mt-2"><?= htmlspecialchars($card['label']) ?></p>
                    <p class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($card['hint']) ?></p>
                </a>
                <?php endforeach; ?>
            </div>

            <!-- Quick actions -->
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-gray-400 mb-3">Quick Actions</h2>
                <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-6 gap-3">
                    <?php foreach ($quickActions as $action): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl($action['href'])) ?>" class="dash-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 flex items-start gap-3">
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

                <!-- Today's appointments -->
                <div class="bg-white rounded-2xl border border-gray-100 dash-card overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-50 flex items-center justify-between">
                        <div>
                            <h2 class="font-bold text-sm text-slate-900">Today's Schedule</h2>
                            <p class="text-[10px] text-gray-400 mt-0.5"><span id="dash-schedule-count"><?= count($scheduleCalendar['appointments']) ?></span> appointment(s) · <span id="dash-schedule-date-label"><?= formatDateDisplay($scheduleDate) ?></span></p>
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
                        <p class="text-xs text-gray-400">No appointments scheduled for this date.</p>
                    </div>
                    <div id="today-appts-list" class="divide-y divide-gray-50 max-h-[240px] overflow-y-auto hidden"></div>
                    <?php else: ?>
                    <div id="dash-schedule-empty" class="p-10 text-center hidden">
                        <i data-lucide="calendar-off" class="w-8 h-8 text-gray-200 mx-auto mb-2"></i>
                        <p class="text-xs text-gray-400">No appointments scheduled for this date.</p>
                    </div>
                    <div id="today-appts-list" class="divide-y divide-gray-50 max-h-[240px] overflow-y-auto">
                        <?php foreach ($scheduleCalendar['appointments'] as $ap): ?>
                        <div class="px-5 py-3 flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex flex-col items-center justify-center shrink-0 leading-none">
                                <span class="text-[9px] font-bold"><?= date('g:i', strtotime($ap['appointment_time'])) ?></span>
                                <span class="text-[8px] uppercase"><?= date('A', strtotime($ap['appointment_time'])) ?></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-slate-800 truncate"><?= htmlspecialchars($ap['citizen_name']) ?></p>
                                <p class="text-[10px] text-gray-400 truncate"><?= htmlspecialchars($ap['service_type']) ?></p>
                            </div>
                            <span class="text-[9px] font-bold uppercase text-gray-400 shrink-0"><?= htmlspecialchars($ap['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
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
                    <a href="<?= htmlspecialchars(buildAuthUrl('Activity-log.php')) ?>" class="text-blue-600 text-[10px] font-bold uppercase hover:underline">Full log</a>
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
        'appointmentDates'  => $scheduleCalendar['dates'],
        'appointmentPage'   => buildAuthUrl('appointment.php'),
    ], 'dashboard-schedule-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/dashboard.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
