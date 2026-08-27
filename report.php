<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('report.php');

$activePage = 'report.php';
$pdo = getDB();

$range = $_GET['range'] ?? 'today';
$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';
[$fromDate, $toDate] = resolveReportDateRange($range, $fromInput, $toInput);
$rangeLabel = reportRangeLabel($range, $fromDate, $toDate);

$downloadType = $_GET['download'] ?? '';
$validDownloads = ['full', 'summary', 'requests', 'appointments', 'queue', 'activity', 'records_quarterly'];
$reportYear = resolveReportYear($_GET['year'] ?? null);

if (isset($_GET['action']) && $_GET['action'] === 'download' && in_array($downloadType, $validDownloads, true)) {
    if ($downloadType === 'records_quarterly') {
        $recordsReport = buildQuarterlyCivilRecordsReport($pdo, $reportYear);
        logActivity(staffId(), 'Report Downloaded', 'Quarterly civil records report for ' . $reportYear);
        exportQuarterlyCivilRecordsCsv($recordsReport);
        exit;
    }

    $exportReport = buildOperationalReport($pdo, $fromDate, $toDate);
    logActivity(staffId(), 'Report Downloaded', 'Operational report (' . $downloadType . ') for ' . $rangeLabel);
    exportOperationalReportCsv($exportReport, $downloadType);
    exit;
}

$report = buildOperationalReport($pdo, $fromDate, $toDate);
$recordsReport = buildQuarterlyCivilRecordsReport($pdo, $reportYear);
$summary = $report['summary'];

function reportDownloadUrl(string $type, string $range, string $from, string $to, ?int $year = null): string
{
    return buildAuthUrl('report.php', array_filter([
        'action' => 'download',
        'download' => $type,
        'range' => $range,
        'from' => $range === 'custom' ? $from : null,
        'to' => $range === 'custom' ? $to : null,
        'year' => $year,
    ]));
}

function reportPageUrl(string $range, string $from, string $to, string $section = 'overview', ?int $year = null): string
{
    return buildAuthUrl('report.php', array_filter([
        'section' => $section !== 'overview' ? $section : null,
        'range' => $range !== 'today' ? $range : null,
        'from' => $range === 'custom' ? $from : null,
        'to' => $range === 'custom' ? $to : null,
        'year' => $year !== null && $year !== (int) date('Y') ? $year : null,
    ]));
}

$purposeLabels = ['walk_in' => 'Walk-in', 'appointment' => 'Appointment', 'document_claim' => 'Document claim'];

$validSections = ['overview', 'requests', 'appointments', 'queue', 'activity', 'records'];
$section = $_GET['section'] ?? 'overview';
if (!in_array($section, $validSections, true)) {
    $section = 'overview';
}

$yearOptions = [];
for ($y = (int) date('Y'); $y >= (int) date('Y') - 4; $y--) {
    $yearOptions[] = $y;
}

$recordTypeStyles = [
    'birth'    => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'icon' => 'users'],
    'death'    => ['bg' => 'bg-teal-50', 'text' => 'text-teal-700', 'icon' => 'activity'],
    'marriage' => ['bg' => 'bg-pink-50', 'text' => 'text-pink-700', 'icon' => 'heart'],
];

$periodMetrics = [
    ['label' => 'Requests Submitted', 'value' => $summary['requests_submitted'], 'hint' => 'New submissions in period', 'icon' => 'file-text', 'iconBg' => 'bg-blue-50', 'iconText' => 'text-blue-600'],
    ['label' => 'Requests Completed', 'value' => $summary['requests_completed'], 'hint' => 'Marked completed in period', 'icon' => 'circle-check', 'iconBg' => 'bg-emerald-50', 'iconText' => 'text-emerald-600'],
    ['label' => 'Appointments', 'value' => $summary['appointments_scheduled'], 'hint' => 'Scheduled in period', 'icon' => 'calendar', 'iconBg' => 'bg-purple-50', 'iconText' => 'text-purple-600'],
    ['label' => 'Queue Served', 'value' => $summary['queue_served'], 'hint' => 'Tickets completed in period', 'icon' => 'users', 'iconBg' => 'bg-green-50', 'iconText' => 'text-green-600'],
];

$liveSnapshot = [
    ['label' => 'Pending Review', 'value' => $summary['pending_requests'], 'hint' => 'Needs staff action now', 'icon' => 'clock', 'iconBg' => 'bg-amber-50', 'iconText' => 'text-amber-600'],
    ['label' => 'Ready for Pickup', 'value' => $summary['ready_for_pickup'], 'hint' => 'Awaiting citizen release', 'icon' => 'package', 'iconBg' => 'bg-teal-50', 'iconText' => 'text-teal-600'],
];

$reportTabs = [
    'overview'     => ['label' => 'Overview',     'icon' => 'layout-dashboard', 'count' => null],
    'requests'     => ['label' => 'Requests',     'icon' => 'file-text',        'count' => count($report['requests'])],
    'appointments' => ['label' => 'Appointments', 'icon' => 'calendar',         'count' => count($report['appointments'])],
    'queue'        => ['label' => 'Queue',        'icon' => 'users',            'count' => count($report['queue_tickets'])],
    'records'      => ['label' => 'Civil Records', 'icon' => 'book-open',       'count' => (int) $recordsReport['year_totals']['total']],
    'activity'     => ['label' => 'Activity',     'icon' => 'activity',         'count' => count($report['activities'])],
];

$rangeOptions = [
    'today' => 'Today',
    'week' => 'Last 7 Days',
    'month' => 'This Month',
    'custom' => 'Custom',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operational Reports - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <?= adminLayoutHeadStyles('report') ?>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap space-y-5">
            <!-- Header -->
            <div class="no-print admin-page-head">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4">
                    <div>
                        <h1>Operational Reports</h1>
                        <p><?= htmlspecialchars($report['office_name']) ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Showing data for <strong class="text-slate-600 font-semibold"><?= htmlspecialchars($rangeLabel) ?></strong><?= $section === 'records' ? ' · Civil records use calendar year <strong class="text-slate-600 font-semibold">' . (int) $reportYear . '</strong>' : '' ?></p>
                    </div>
                    <div class="flex flex-wrap gap-2 shrink-0">
                        <div class="relative" id="reportPrintMenu">
                            <button type="button" id="reportPrintBtn" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 text-slate-700 px-3.5 py-2 rounded-lg text-xs font-bold">
                                <i data-lucide="printer" class="w-3.5 h-3.5"></i> Print
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 opacity-70"></i>
                            </button>
                            <div id="reportPrintPanel" class="hidden absolute right-0 mt-2 w-64 bg-white border border-gray-100 rounded-xl shadow-lg z-20 p-4 text-xs">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-3">Sections to print</p>
                                <div class="space-y-2 mb-3">
                                    <?php foreach ($reportTabs as $tabKey => $tab): ?>
                                    <label class="flex items-center gap-2.5 cursor-pointer text-slate-700 font-medium">
                                        <input type="checkbox" class="report-print-check rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                               value="<?= htmlspecialchars($tabKey) ?>"
                                               <?= $section === $tabKey ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($tab['label']) ?><?= $tabKey === 'records' ? ' (' . (int) $reportYear . ')' : '' ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="flex items-center justify-between gap-2 pt-3 border-t border-gray-100 mb-3">
                                    <button type="button" id="reportPrintSelectAll" class="text-blue-600 font-bold hover:underline">Select all</button>
                                    <button type="button" id="reportPrintClearAll" class="text-slate-500 font-bold hover:underline">Clear</button>
                                </div>
                                <button type="button" id="reportPrintSubmit" class="w-full bg-slate-800 hover:bg-slate-900 text-white px-3 py-2 rounded-lg text-xs font-bold">
                                    Print selected
                                </button>
                            </div>
                        </div>
                        <div class="relative" id="reportDownloadMenu">
                            <button type="button" id="reportDownloadBtn" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Export CSV
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 opacity-80"></i>
                            </button>
                            <div id="reportDownloadPanel" class="hidden absolute right-0 mt-2 w-52 bg-white border border-gray-100 rounded-xl shadow-lg z-20 py-1 text-xs">
                                <p class="px-3 py-1.5 text-[9px] font-bold uppercase tracking-wider text-gray-400">Combined</p>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('full', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Full report</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('summary', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Summary only</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <p class="px-3 py-1.5 text-[9px] font-bold uppercase tracking-wider text-gray-400">By section</p>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('requests', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Document requests</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('appointments', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Appointments</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('queue', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Queue tickets</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('activity', $range, $fromDate, $toDate)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Staff activity</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <p class="px-3 py-1.5 text-[9px] font-bold uppercase tracking-wider text-gray-400">Civil registry</p>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('records_quarterly', $range, $fromDate, $toDate, $reportYear)) ?>" class="block px-3 py-2 font-semibold text-slate-700 hover:bg-gray-50">Quarterly records (<?= (int) $reportYear ?>)</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Period filter + section tabs -->
            <div class="no-print admin-toolbar flex-col xl:flex-row xl:items-center xl:justify-between gap-4">
                <div class="flex flex-wrap gap-1.5 shrink-0">
                    <?php foreach ($rangeOptions as $key => $label): ?>
                    <a href="<?= htmlspecialchars(reportPageUrl($key, $fromDate, $toDate, $section, $section === 'records' ? $reportYear : null)) ?>"
                       class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors whitespace-nowrap <?= $range === $key ? 'bg-blue-600 border-blue-600 text-white' : 'bg-gray-50 border-gray-200 text-slate-600 hover:border-blue-200' ?>">
                        <?= htmlspecialchars($label) ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <form method="GET" class="flex flex-wrap items-center gap-2 shrink-0">
                    <?php if ($token = staffAuthToken()): ?>
                    <input type="hidden" name="alcros_auth" value="<?= htmlspecialchars($token) ?>">
                    <?php endif; ?>
                    <?php if ($section !== 'overview'): ?>
                    <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                    <?php endif; ?>
                    <?php if ($section === 'records' && $reportYear !== (int) date('Y')): ?>
                    <input type="hidden" name="year" value="<?= (int) $reportYear ?>">
                    <?php endif; ?>
                    <input type="hidden" name="range" value="custom">
                    <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" aria-label="From date" class="border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs">
                    <span class="text-gray-300 text-xs">to</span>
                    <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" aria-label="To date" class="border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs">
                    <button type="submit" class="bg-slate-800 hover:bg-slate-900 text-white px-3 py-1.5 rounded-lg text-xs font-bold">Apply</button>
                </form>
                <nav class="report-tabs flex gap-1 overflow-x-auto shrink-0 w-full xl:w-auto" aria-label="Report sections">
                    <?php foreach ($reportTabs as $tabKey => $tab): ?>
                    <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, $tabKey, $tabKey === 'records' ? $reportYear : null)) ?>"
                       class="report-tab flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-bold border border-transparent whitespace-nowrap text-slate-600 hover:text-slate-900 hover:bg-gray-50 <?= $section === $tabKey ? 'is-active' : '' ?>">
                        <i data-lucide="<?= $tab['icon'] ?>" class="w-3.5 h-3.5"></i>
                        <?= htmlspecialchars($tab['label']) ?>
                        <?php if ($tab['count'] !== null): ?>
                        <span class="min-w-[1.25rem] h-5 px-1.5 rounded-full text-[10px] font-black flex items-center justify-center <?= $section === $tabKey ? 'bg-blue-600 text-white' : 'bg-gray-100 text-slate-600' ?>"><?= (int) $tab['count'] ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <!-- Print header -->
            <div class="hidden print:block mb-6 pb-4 border-b border-gray-200">
                <h1 class="text-xl font-black text-slate-900"><?= htmlspecialchars($report['site_name']) ?> — Operational Report</h1>
                <p class="text-sm text-gray-600"><?= htmlspecialchars($report['office_name']) ?> · <?= htmlspecialchars($rangeLabel) ?></p>
                <p class="text-xs text-gray-400">Generated <?= htmlspecialchars($report['generated_at']) ?></p>
            </div>

            <!-- Overview -->
            <div class="report-panel" data-print-section="overview" <?= $section !== 'overview' ? 'hidden' : '' ?>>
                <div class="space-y-5">
                    <section class="report-section">
                        <h2 class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-3">Activity in this period</h2>
                        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                            <?php foreach ($periodMetrics as $card): ?>
                            <div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm">
                                <div class="flex items-center gap-2 mb-2">
                                    <div class="p-1.5 <?= $card['iconBg'] ?> rounded-lg">
                                        <i data-lucide="<?= $card['icon'] ?>" class="w-4 h-4 <?= $card['iconText'] ?>"></i>
                                    </div>
                                    <p class="text-[10px] font-bold uppercase text-gray-400 leading-tight"><?= htmlspecialchars($card['label']) ?></p>
                                </div>
                                <p class="text-2xl font-black text-slate-900"><?= (int) $card['value'] ?></p>
                                <p class="text-[10px] text-gray-400 mt-1"><?= htmlspecialchars($card['hint']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="report-section bg-white rounded-xl border border-gray-100 shadow-sm p-5">
                        <h2 class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-3">Live snapshot (right now)</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                            <?php foreach ($liveSnapshot as $card): ?>
                            <div class="flex items-center gap-3 p-3 rounded-lg bg-gray-50">
                                <div class="p-2 <?= $card['iconBg'] ?> rounded-lg shrink-0">
                                    <i data-lucide="<?= $card['icon'] ?>" class="w-4 h-4 <?= $card['iconText'] ?>"></i>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold text-slate-700"><?= htmlspecialchars($card['label']) ?></p>
                                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($card['hint']) ?></p>
                                </div>
                                <p class="text-xl font-black text-slate-900"><?= (int) $card['value'] ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <p class="text-xs text-gray-500 pt-3 border-t border-gray-100">
                            Civil registry records on file: <strong class="text-slate-800"><?= number_format((int) $summary['total_records']) ?></strong>
                            · Registered in <?= (int) $reportYear ?>: <strong class="text-slate-800"><?= number_format((int) $recordsReport['year_totals']['total']) ?></strong>
                            <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, 'records', $reportYear)) ?>" class="text-blue-600 font-semibold hover:underline ml-1">View quarterly breakdown</a>
                        </p>
                    </section>

                    <section class="report-section no-print">
                        <h2 class="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-3">Jump to detail</h2>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <?php
                            $detailLinks = [
                                ['key' => 'requests', 'desc' => 'Track submissions, status, and document types', 'color' => 'border-blue-100 hover:border-blue-200 hover:bg-blue-50/50'],
                                ['key' => 'appointments', 'desc' => 'Scheduled visits and appointment status', 'color' => 'border-purple-100 hover:border-purple-200 hover:bg-purple-50/50'],
                                ['key' => 'queue', 'desc' => 'Queue tickets served, waiting, and by purpose', 'color' => 'border-green-100 hover:border-green-200 hover:bg-green-50/50'],
                                ['key' => 'records', 'desc' => 'Birth, death, and marriage registrations by quarter', 'color' => 'border-amber-100 hover:border-amber-200 hover:bg-amber-50/50'],
                                ['key' => 'activity', 'desc' => 'Staff actions logged during this period', 'color' => 'border-slate-200 hover:border-slate-300 hover:bg-slate-50'],
                            ];
                            foreach ($detailLinks as $link):
                                $tab = $reportTabs[$link['key']];
                            ?>
                            <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, $link['key'], $link['key'] === 'records' ? $reportYear : null)) ?>"
                               class="flex items-start gap-3 p-4 bg-white rounded-xl border <?= $link['color'] ?> transition-colors">
                                <div class="p-2 bg-gray-50 rounded-lg shrink-0">
                                    <i data-lucide="<?= $tab['icon'] ?>" class="w-4 h-4 text-slate-600"></i>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                        <?= htmlspecialchars($tab['label']) ?>
                                        <span class="text-[10px] font-black bg-gray-100 text-slate-600 px-1.5 py-0.5 rounded-full"><?= (int) $tab['count'] ?></span>
                                    </p>
                                    <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($link['desc']) ?></p>
                                </div>
                                <i data-lucide="chevron-right" class="w-4 h-4 text-gray-300 shrink-0 mt-1"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Document Requests -->
            <div class="report-panel" data-print-section="requests" <?= $section !== 'requests' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Document Requests</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= count($report['requests']) ?> record(s) in <?= htmlspecialchars(strtolower($rangeLabel)) ?></p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('requests', $range, $fromDate, $toDate)) ?>" class="no-print shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export
                        </a>
                    </div>
                    <?php if (!empty($report['requests_by_status']) || !empty($report['requests_by_type'])): ?>
                    <div class="px-5 py-4 grid sm:grid-cols-2 gap-6 border-b border-gray-50 bg-gray-50/40">
                        <div>
                            <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By status</p>
                            <?php if (empty($report['requests_by_status'])): ?>
                            <p class="text-xs text-gray-400">—</p>
                            <?php else: foreach ($report['requests_by_status'] as $status => $count): ?>
                            <div class="stat-row text-sm"><span class="text-slate-600"><?= htmlspecialchars(requestStatusLabel($status)) ?></span><span class="font-bold text-slate-900"><?= $count ?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By document type</p>
                            <?php if (empty($report['requests_by_type'])): ?>
                            <p class="text-xs text-gray-400">—</p>
                            <?php else: foreach ($report['requests_by_type'] as $type => $count): ?>
                            <div class="stat-row text-sm"><span class="text-slate-600"><?= htmlspecialchars(documentTypeLabel($type)) ?></span><span class="font-bold text-slate-900"><?= $count ?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Tracking</th>
                                    <th class="px-5 py-3">Citizen</th>
                                    <th class="px-5 py-3 hidden md:table-cell">Type</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 hidden sm:table-cell">Submitted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['requests'])): ?>
                                <tr><td colspan="5" class="px-5 py-12 text-center text-gray-400 text-sm">No document requests for this period.</td></tr>
                                <?php else: foreach ($report['requests'] as $row): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($row['tracking_code']) ?></td>
                                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars(personNameFromRow($row)) ?></td>
                                    <td class="px-5 py-3 text-gray-500 hidden md:table-cell"><?= htmlspecialchars(documentTypeLabel($row['document_type'])) ?></td>
                                    <td class="px-5 py-3"><span class="text-[10px] font-bold uppercase text-slate-600"><?= htmlspecialchars(requestStatusLabel($row['status'])) ?></span></td>
                                    <td class="px-5 py-3 text-gray-400 text-xs hidden sm:table-cell"><?= htmlspecialchars(formatDateDisplay(substr($row['submitted_at'], 0, 10))) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Appointments -->
            <div class="report-panel" data-print-section="appointments" <?= $section !== 'appointments' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Appointments</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= count($report['appointments']) ?> visit(s) in <?= htmlspecialchars(strtolower($rangeLabel)) ?></p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('appointments', $range, $fromDate, $toDate)) ?>" class="no-print shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export
                        </a>
                    </div>
                    <?php if (!empty($report['appointments_by_status'])): ?>
                    <div class="px-5 py-4 border-b border-gray-50 bg-gray-50/40">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By status</p>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($report['appointments_by_status'] as $status => $count): ?>
                            <span class="inline-flex items-center gap-2 bg-white border border-gray-100 rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-700">
                                <?= htmlspecialchars(appointmentStatusLabel($status)) ?>
                                <span class="font-black text-slate-900"><?= $count ?></span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Code</th>
                                    <th class="px-5 py-3">Citizen</th>
                                    <th class="px-5 py-3 hidden md:table-cell">Service</th>
                                    <th class="px-5 py-3">Schedule</th>
                                    <th class="px-5 py-3">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['appointments'])): ?>
                                <tr><td colspan="5" class="px-5 py-12 text-center text-gray-400 text-sm">No appointments for this period.</td></tr>
                                <?php else: foreach ($report['appointments'] as $row): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($row['appointment_code']) ?></td>
                                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars(personNameFromRow($row)) ?></td>
                                    <td class="px-5 py-3 text-gray-500 hidden md:table-cell"><?= htmlspecialchars(appointmentServiceLabel($row['service_type'])) ?></td>
                                    <td class="px-5 py-3 text-gray-600 text-xs whitespace-nowrap"><?= htmlspecialchars(formatDateDisplay($row['appointment_date'])) ?> · <?= date('g:i A', strtotime($row['appointment_time'])) ?></td>
                                    <td class="px-5 py-3"><span class="text-[10px] font-bold uppercase text-slate-600"><?= htmlspecialchars(appointmentStatusLabel($row['status'])) ?></span></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Queue -->
            <div class="report-panel" data-print-section="queue" <?= $section !== 'queue' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Queue Performance</h2>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <?= (int) $summary['queue_served'] ?> served · <?= (int) $summary['queue_waiting'] ?> waiting · <?= (int) $summary['queue_skipped'] ?> no-show
                            </p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('queue', $range, $fromDate, $toDate)) ?>" class="no-print shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export
                        </a>
                    </div>
                    <?php if (!empty($report['queue_by_purpose'])): ?>
                    <div class="px-5 py-4 border-b border-gray-50 bg-gray-50/40">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By purpose</p>
                        <?php foreach ($report['queue_by_purpose'] as $purpose => $count): ?>
                        <div class="stat-row text-sm"><span class="text-slate-600"><?= htmlspecialchars($purposeLabels[$purpose] ?? ucfirst($purpose)) ?></span><span class="font-bold text-slate-900"><?= $count ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Ticket</th>
                                    <th class="px-5 py-3">Purpose</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 hidden sm:table-cell">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['queue_tickets'])): ?>
                                <tr><td colspan="4" class="px-5 py-12 text-center text-gray-400 text-sm">No queue tickets for this period.</td></tr>
                                <?php else: foreach ($report['queue_tickets'] as $row): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 font-mono text-xs font-bold text-slate-800"><?= htmlspecialchars($row['ticket_number']) ?></td>
                                    <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($purposeLabels[$row['purpose']] ?? $row['purpose']) ?></td>
                                    <td class="px-5 py-3 capitalize text-slate-600"><?= htmlspecialchars($row['status']) ?></td>
                                    <td class="px-5 py-3 text-gray-400 text-xs hidden sm:table-cell"><?= htmlspecialchars(formatDateDisplay(substr($row['created_at'], 0, 10))) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Civil Records (Quarterly) -->
            <div class="report-panel" data-print-section="records" <?= $section !== 'records' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-slate-900">Civil Records — Quarterly Registration</h2>
                                <p class="text-xs text-gray-400 mt-0.5">How many birth, death, and marriage records were registered each quarter.</p>
                            </div>
                            <a href="<?= htmlspecialchars(reportDownloadUrl('records_quarterly', $range, $fromDate, $toDate, $reportYear)) ?>" class="no-print shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Export <?= (int) $reportYear ?> CSV
                            </a>
                        </div>
                        <div class="no-print flex flex-wrap gap-1.5 mt-4">
                            <?php foreach ($yearOptions as $yearOption): ?>
                            <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, 'records', $yearOption)) ?>"
                               class="px-3 py-1.5 rounded-lg text-xs font-bold border transition-colors <?= $reportYear === $yearOption ? 'bg-blue-600 border-blue-600 text-white' : 'bg-gray-50 border-gray-200 text-slate-600 hover:border-blue-200' ?>">
                                <?= (int) $yearOption ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="px-5 py-4 grid grid-cols-2 lg:grid-cols-4 gap-3 border-b border-gray-50 bg-gray-50/40">
                        <?php foreach ($recordsReport['record_types'] as $type): ?>
                        <?php $style = $recordTypeStyles[$type]; ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-100">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="p-1.5 <?= $style['bg'] ?> rounded-lg">
                                    <i data-lucide="<?= $style['icon'] ?>" class="w-4 h-4 <?= $style['text'] ?>"></i>
                                </div>
                                <p class="text-[10px] font-bold uppercase text-gray-400"><?= htmlspecialchars(civilRecordTypeLabel($type)) ?></p>
                            </div>
                            <p class="text-2xl font-black text-slate-900"><?= number_format((int) $recordsReport['year_totals'][$type]) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= (int) $reportYear ?> total</p>
                        </div>
                        <?php endforeach; ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-100">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="p-1.5 bg-slate-100 rounded-lg">
                                    <i data-lucide="layers" class="w-4 h-4 text-slate-600"></i>
                                </div>
                                <p class="text-[10px] font-bold uppercase text-gray-400">All types</p>
                            </div>
                            <p class="text-2xl font-black text-slate-900"><?= number_format((int) $recordsReport['year_totals']['total']) ?></p>
                            <p class="text-[10px] text-gray-400 mt-1"><?= (int) $reportYear ?> total</p>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Quarter</th>
                                    <th class="px-5 py-3 text-right">Birth</th>
                                    <th class="px-5 py-3 text-right">Death</th>
                                    <th class="px-5 py-3 text-right">Marriage</th>
                                    <th class="px-5 py-3 text-right">Quarter total</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php foreach ($recordsReport['quarters'] as $quarter): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3.5 font-semibold text-slate-800"><?= htmlspecialchars($quarter['label']) ?></td>
                                    <td class="px-5 py-3.5 text-right font-bold text-blue-700"><?= number_format((int) $quarter['birth']) ?></td>
                                    <td class="px-5 py-3.5 text-right font-bold text-teal-700"><?= number_format((int) $quarter['death']) ?></td>
                                    <td class="px-5 py-3.5 text-right font-bold text-pink-700"><?= number_format((int) $quarter['marriage']) ?></td>
                                    <td class="px-5 py-3.5 text-right font-black text-slate-900"><?= number_format((int) $quarter['total']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="bg-slate-50 font-bold">
                                    <td class="px-5 py-3.5 text-slate-900"><?= (int) $reportYear ?> total</td>
                                    <td class="px-5 py-3.5 text-right text-blue-800"><?= number_format((int) $recordsReport['year_totals']['birth']) ?></td>
                                    <td class="px-5 py-3.5 text-right text-teal-800"><?= number_format((int) $recordsReport['year_totals']['death']) ?></td>
                                    <td class="px-5 py-3.5 text-right text-pink-800"><?= number_format((int) $recordsReport['year_totals']['marriage']) ?></td>
                                    <td class="px-5 py-3.5 text-right text-slate-900"><?= number_format((int) $recordsReport['year_totals']['total']) ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-3 border-t border-gray-100 bg-gray-50/50">
                        <p class="text-[11px] text-gray-500">Counts use the record’s <strong class="text-slate-600">registration date</strong> when available, otherwise the event date or date the record was entered.</p>
                    </div>
                </section>
            </div>

            <!-- Activity -->
            <div class="report-panel" data-print-section="activity" <?= $section !== 'activity' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Staff Activity Log</h2>
                            <p class="text-xs text-gray-400 mt-0.5">Actions recorded in this period (up to 500 entries)</p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('activity', $range, $fromDate, $toDate)) ?>" class="no-print shrink-0 inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export
                        </a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Staff</th>
                                    <th class="px-5 py-3">Action</th>
                                    <th class="px-5 py-3 hidden md:table-cell">Details</th>
                                    <th class="px-5 py-3 whitespace-nowrap">When</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['activities'])): ?>
                                <tr><td colspan="4" class="px-5 py-12 text-center text-gray-400 text-sm">No staff activity logged for this period.</td></tr>
                                <?php else: foreach ($report['activities'] as $row): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 font-mono text-xs text-slate-600"><?= htmlspecialchars($row['staff_id'] ?? '—') ?></td>
                                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars($row['action']) ?></td>
                                    <td class="px-5 py-3 text-gray-500 text-xs max-w-md truncate hidden md:table-cell"><?= htmlspecialchars($row['details'] ?? '') ?></td>
                                    <td class="px-5 py-3 text-gray-400 text-xs whitespace-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </main>
    <?= scriptTag('admin/report.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
