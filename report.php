<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/analytics_dashboard.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('report.php');
releaseSessionLock();

$activePage = 'report.php';
$pdo = getDB();

$range = $_GET['range'] ?? 'today';
$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';
[$fromDate, $toDate] = resolveReportDateRange($range, $fromInput, $toInput);
$rangeLabel = reportRangeLabel($range, $fromDate, $toDate);

$reportYear = resolveReportYear($_GET['year'] ?? null);

$report = buildOperationalReport($pdo, $fromDate, $toDate);
$recordsReport = buildQuarterlyCivilRecordsReport($pdo, $reportYear);
$summary = $report['summary'];
$requestsReportCharts = buildRequestsReportChartPayload(
    $pdo,
    $fromDate,
    $toDate,
    $report['requests_by_status'],
    $report['requests_by_type']
);
$appointmentsReportCharts = buildAppointmentsReportChartPayload(
    $pdo,
    $fromDate,
    $toDate,
    $report['appointments_by_status']
);
$printsReportCharts = buildPrintsReportChartPayload(
    $pdo,
    $fromDate,
    $toDate,
    $report['prints_by_document_kind'] ?? ['certification' => 0, 'certificate' => 0],
    $report['prints_by_certificate_type'] ?? ['birth' => 0, 'death' => 0, 'marriage' => 0]
);

if (isset($_GET['action']) && $_GET['action'] === 'export') {
    @ini_set('memory_limit', '1024M');
    @set_time_limit(0);
    ignore_user_abort(true);

    if (strtolower((string) ($_GET['format'] ?? 'csv')) !== 'csv') {
        http_response_code(400);
        exit('Only CSV export is available.');
    }

    $exportSections = parseReportExportSections($_GET['sections'] ?? '');
    if ($exportSections === []) {
        http_response_code(400);
        exit('Select at least one report section to export.');
    }

    $exportRecordsType = reportExportRecordsType($_GET['records_type'] ?? 'all');
    $sectionLabels = array_map(
        static fn (string $key): string => match ($key) {
            'overview' => 'Overview',
            'requests' => 'Requests',
            'appointments' => 'Appointments',
            'queue' => 'Queue',
            'prints' => 'Prints',
            'records' => 'Civil Records',
            default => $key,
        },
        $exportSections
    );

    logActivity(
        staffId(),
        'CSV Export',
        'Exported operational report (' . implode(', ', $sectionLabels) . ')'
    );
    exportOperationalReportCsv(
        $pdo,
        $report,
        $recordsReport,
        $reportYear,
        $exportSections,
        $exportRecordsType,
        $rangeLabel
    );
    exit;
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
$queueReportCharts = buildQueueWaitChartPayload(
    $report['queue_tickets'],
    $purposeLabels,
    $fromDate,
    $toDate
);

$validSections = ['overview', 'analytics', 'requests', 'appointments', 'queue', 'prints', 'records'];
$section = $_GET['section'] ?? 'overview';
if (!in_array($section, $validSections, true)) {
    $section = 'overview';
}

$analytics = $section === 'analytics' ? fetchAnalyticsDashboard($pdo) : null;

$pageTitle = 'Reports';
$pageSubtitle = $section === 'analytics'
    ? 'Same live office analytics as the administrator dashboard — civil records, print volume, intake, and queue.'
    : 'Summaries for requests, appointments, queue, print volume, and civil records with CSV export.';
$pageHeaderMeta = $section === 'analytics'
    ? '<p class="admin-header__meta">' . htmlspecialchars($report['office_name']) . ' · Live office analytics</p>'
    : '<p class="admin-header__meta">' . htmlspecialchars($report['office_name']) . ' · Showing <strong>'
        . htmlspecialchars($rangeLabel) . '</strong>'
        . ($section === 'records'
            ? ' · Civil records use calendar year <strong>' . (int) $reportYear . '</strong>'
            : '')
        . '</p>';

$yearOptions = reportCivilRecordsYearOptions($pdo);

$recordTypeStyles = [
    'birth'    => ['bg' => 'bg-blue-50', 'text' => 'text-blue-700', 'icon' => 'users'],
    'death'    => ['bg' => 'bg-teal-50', 'text' => 'text-teal-700', 'icon' => 'activity'],
    'marriage' => ['bg' => 'bg-pink-50', 'text' => 'text-pink-700', 'icon' => 'heart'],
];

$periodMetrics = [
    ['label' => 'Requests Submitted', 'value' => $summary['requests_submitted'], 'hint' => 'New submissions in period', 'icon' => 'file-text', 'iconBg' => 'bg-blue-50', 'iconText' => 'text-blue-600'],
    ['label' => 'Requests Completed', 'value' => $summary['requests_completed'], 'hint' => 'Marked completed in period', 'icon' => 'circle-check', 'iconBg' => 'bg-emerald-50', 'iconText' => 'text-emerald-600'],
    ['label' => 'Appointments', 'value' => $summary['appointments_scheduled'], 'hint' => 'Scheduled in period', 'icon' => 'calendar', 'iconBg' => 'bg-purple-50', 'iconText' => 'text-purple-600'],
    ['label' => 'Queue Served', 'value' => $summary['queue_served'], 'hint' => 'Tickets completed in period', 'icon' => 'users', 'iconBg' => 'bg-teal-50', 'iconText' => 'text-teal-600'],
    ['label' => 'Certifications Printed', 'value' => $summary['certifications_printed'] ?? 0, 'hint' => 'Completed certification jobs in period', 'icon' => 'stamp', 'iconBg' => 'bg-indigo-50', 'iconText' => 'text-indigo-600'],
    ['label' => 'Certificates Printed', 'value' => $summary['certificates_printed'] ?? 0, 'hint' => 'Completed certificate jobs in period', 'icon' => 'printer', 'iconBg' => 'bg-cyan-50', 'iconText' => 'text-cyan-600'],
];

$reportTabs = [
    'overview'     => ['label' => 'Overview',     'icon' => 'layout-dashboard', 'count' => null],
    'analytics'    => ['label' => 'Analytics',    'icon' => 'bar-chart-2',      'count' => null, 'export' => false],
    'requests'     => ['label' => 'Requests',     'icon' => 'file-text',        'count' => count($report['requests'])],
    'appointments' => ['label' => 'Appointments', 'icon' => 'calendar',         'count' => count($report['appointments'])],
    'queue'        => ['label' => 'Queue',        'icon' => 'users',            'count' => count($report['queue_tickets'])],
    'prints'       => ['label' => 'Prints',       'icon' => 'printer',          'count' => count($report['print_jobs'] ?? [])],
    'records'      => ['label' => 'Civil Records', 'icon' => 'book-open',       'count' => (int) $recordsReport['year_totals']['total']],
];

$rangeOptions = [
    'today' => 'Today',
    'week' => 'Last 7 days',
    'month' => 'This month',
    'custom' => 'Custom',
];

$reportDetailSection = !in_array($section, ['overview', 'analytics'], true);
$recordsRegistryUrl = buildAuthUrl('records.php');
$recordsYearTotals = $recordsReport['year_totals'];
$recordsJumpDesc = sprintf(
    '%d registrations in %d · %s birth · %s death · %s marriage',
    (int) $recordsYearTotals['total'],
    (int) $reportYear,
    number_format((int) $recordsYearTotals['birth']),
    number_format((int) $recordsYearTotals['death']),
    number_format((int) $recordsYearTotals['marriage'])
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('report') ?>
    <?php if ($section === 'analytics'): ?>
    <?= stylesheetTag('admin/dashboard.css') ?>
    <?php endif; ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?php if (in_array($section, ['analytics', 'records', 'requests', 'appointments', 'queue', 'prints'], true)): ?>
    <?= vendorScriptTag('chart.umd.min.js') ?>
    <?php endif; ?>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap space-y-5">
            <?php
            ob_start();
            ?>
                        <form id="reportExportForm" method="GET" action="<?= htmlspecialchars(buildAuthUrl('report.php')) ?>" class="hidden">
                            <input type="hidden" name="action" value="export">
                            <input type="hidden" name="format" value="csv">
                            <input type="hidden" name="sections" id="reportExportSectionsField" value="">
                            <input type="hidden" name="records_type" id="reportExportRecordsTypeField" value="all">
                            <?php if ($range !== 'today'): ?>
                            <input type="hidden" name="range" value="<?= htmlspecialchars($range) ?>">
                            <?php endif; ?>
                            <?php if ($range === 'custom'): ?>
                            <input type="hidden" name="from" value="<?= htmlspecialchars($fromDate) ?>">
                            <input type="hidden" name="to" value="<?= htmlspecialchars($toDate) ?>">
                            <?php endif; ?>
                            <?php if ($section !== 'overview'): ?>
                            <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                            <?php endif; ?>
                            <?php if ($reportYear !== (int) date('Y')): ?>
                            <input type="hidden" name="year" value="<?= (int) $reportYear ?>">
                            <?php endif; ?>
                        </form>
            <?php
            $reportExportFormHtml = ob_get_clean();

            ob_start();
            if ($section === 'overview'):
            ?>
                        <div class="relative" id="reportExportMenu">
                            <button type="button" id="reportExportBtn" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Export CSV
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5 opacity-80"></i>
                            </button>
                            <div id="reportExportPanel" class="hidden absolute right-0 mt-2 w-72 bg-white border border-gray-100 rounded-xl shadow-lg z-20 p-4 text-xs">
                                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-3">Sections to export</p>
                                <div class="space-y-2 mb-3">
                                    <?php foreach ($reportTabs as $tabKey => $tab): ?>
                                    <?php if (($tab['export'] ?? true) === false) continue; ?>
                                    <label class="flex items-center gap-2.5 cursor-pointer text-slate-700 font-medium">
                                        <input type="checkbox" class="report-export-check rounded border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                               value="<?= htmlspecialchars($tabKey) ?>"
                                               <?= $tabKey === 'overview' ? 'checked' : '' ?>>
                                        <span><?= htmlspecialchars($tab['label']) ?><?= $tabKey === 'records' ? ' (' . (int) $reportYear . ')' : '' ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <div id="reportExportRecordsFilter" class="mb-3 pt-3 border-t border-gray-100 hidden">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-2">Civil records type</p>
                                    <div class="space-y-1.5">
                                        <?php
                                        $recordsExportTypes = [
                                            'all' => 'All record types',
                                            'birth' => 'Birth records',
                                            'death' => 'Death records',
                                            'marriage' => 'Marriage records',
                                        ];
                                        foreach ($recordsExportTypes as $typeKey => $typeLabel):
                                        ?>
                                        <label class="flex items-center gap-2 cursor-pointer text-slate-700">
                                            <input type="radio" name="reportExportRecordsType" class="report-export-records-type rounded-full border-gray-300 text-emerald-600 focus:ring-emerald-500"
                                                   value="<?= htmlspecialchars($typeKey) ?>" <?= $typeKey === 'all' ? 'checked' : '' ?>>
                                            <span><?= htmlspecialchars($typeLabel) ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-2 leading-snug">Includes quarterly summary plus full record rows for <?= (int) $reportYear ?>.</p>
                                </div>
                                <div class="flex items-center justify-between gap-2 pt-3 border-t border-gray-100 mb-3">
                                    <button type="button" id="reportExportSelectAll" class="text-emerald-600 font-bold hover:underline">Select all</button>
                                    <button type="button" id="reportExportClearAll" class="text-slate-500 font-bold hover:underline">Clear</button>
                                </div>
                                <button type="button" id="reportExportSubmit" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-2 rounded-lg text-xs font-bold">
                                    Download CSV
                                </button>
                            </div>
                        </div>
            <?php
            elseif ($reportDetailSection):
                $detailExportLabel = $reportTabs[$section]['label'] ?? ucfirst($section);
            ?>
                        <button type="button"
                                id="reportExportDirectBtn"
                                class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold"
                                data-export-section="<?= htmlspecialchars($section) ?>"
                                data-loading-text="Exporting…">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i> Export CSV
                        </button>
            <?php endif;
            $reportExportControlHtml = ob_get_clean();
            $reportExportToolbarHtml = $reportExportControlHtml . $reportExportFormHtml;
            ?>

            <?php if ($section === 'overview'): ?>
            <div class="no-print report-overview-toolbar">
                <div class="admin-toolbar report-range-toolbar !mb-0 !rounded-xl !border !border-slate-200 !shadow-sm min-w-0 flex-1 bg-white">
                    <div class="admin-toolbar-filters !flex-1 min-w-0">
                        <?php foreach ($rangeOptions as $key => $label): ?>
                        <a href="<?= htmlspecialchars(reportPageUrl($key, $fromDate, $toDate, 'overview', null)) ?>"
                           class="range-pill px-3 py-1.5 rounded-lg text-xs font-bold border border-slate-200 text-slate-600 hover:bg-slate-50 whitespace-nowrap shrink-0 <?= $range === $key ? 'is-active' : '' ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <form id="reportCustomRangeForm" method="GET" action="<?= htmlspecialchars(buildAuthUrl('report.php')) ?>" class="flex flex-wrap items-center gap-2 shrink-0 <?= $range === 'custom' ? '' : 'hidden' ?>">
                        <?php if ($token = staffAuthToken()): ?>
                        <input type="hidden" name="alcros_auth" value="<?= htmlspecialchars($token) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="section" value="overview">
                        <input type="hidden" name="range" value="custom">
                        <input type="date" name="from" id="reportRangeFrom" value="<?= htmlspecialchars($fromDate) ?>" aria-label="From date" class="border border-slate-200 rounded-lg px-2.5 py-2 text-xs bg-white">
                        <span class="text-slate-300 text-xs font-bold">to</span>
                        <input type="date" name="to" id="reportRangeTo" value="<?= htmlspecialchars($toDate) ?>" aria-label="To date" class="border border-slate-200 rounded-lg px-2.5 py-2 text-xs bg-white">
                    </form>
                </div>
                <div class="shrink-0 flex flex-wrap items-center justify-end gap-2">
                    <?= $reportExportToolbarHtml ?>
                </div>
            </div>
            <?php elseif ($section === 'records'): ?>
            <div class="no-print report-toolbar-row flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-2">
                <p class="text-xs text-slate-500 bg-white border border-slate-100 rounded-xl px-3 py-2.5 shadow-sm">
                    Civil records use <strong class="text-slate-700">calendar year <?= (int) $reportYear ?></strong> (registration date). Change year below on the report.
                </p>
                <div class="shrink-0 flex flex-wrap items-center justify-end gap-2">
                    <a href="<?= htmlspecialchars($recordsRegistryUrl) ?>" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-blue-200 text-slate-700 px-3.5 py-2 rounded-lg text-xs font-bold">
                        <i data-lucide="book-open" class="w-3.5 h-3.5"></i> Open registry
                    </a>
                    <?= $reportExportToolbarHtml ?>
                </div>
            </div>
            <?php elseif ($reportDetailSection): ?>
            <div class="no-print report-toolbar-row flex flex-col lg:flex-row lg:items-center lg:justify-between gap-3 mb-2">
                <div class="admin-toolbar report-range-toolbar !mb-0 !rounded-xl !border !border-slate-100 !shadow-sm min-w-0 flex-1">
                    <div class="admin-toolbar-filters !flex-1 min-w-0">
                        <?php foreach ($rangeOptions as $key => $label): ?>
                        <a href="<?= htmlspecialchars(reportPageUrl($key, $fromDate, $toDate, $section, null)) ?>"
                           class="range-pill px-3 py-1.5 rounded-lg text-xs font-bold border border-slate-200 text-slate-600 hover:bg-slate-50 whitespace-nowrap shrink-0 <?= $range === $key ? 'is-active' : '' ?>">
                            <?= htmlspecialchars($label) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <form id="reportCustomRangeForm" method="GET" action="<?= htmlspecialchars(buildAuthUrl('report.php')) ?>" class="flex flex-wrap items-center gap-2 shrink-0 <?= $range === 'custom' ? '' : 'hidden' ?>">
                        <?php if ($token = staffAuthToken()): ?>
                        <input type="hidden" name="alcros_auth" value="<?= htmlspecialchars($token) ?>">
                        <?php endif; ?>
                        <input type="hidden" name="section" value="<?= htmlspecialchars($section) ?>">
                        <input type="hidden" name="range" value="custom">
                        <input type="date" name="from" id="reportRangeFrom" value="<?= htmlspecialchars($fromDate) ?>" aria-label="From date" class="border border-slate-200 rounded-lg px-2.5 py-2 text-xs bg-white">
                        <span class="text-slate-300 text-xs font-bold">to</span>
                        <input type="date" name="to" id="reportRangeTo" value="<?= htmlspecialchars($toDate) ?>" aria-label="To date" class="border border-slate-200 rounded-lg px-2.5 py-2 text-xs bg-white">
                    </form>
                </div>
                <div class="shrink-0 flex justify-end">
                    <?= $reportExportToolbarHtml ?>
                </div>
            </div>
            <?php elseif ($section === 'analytics'): ?>
            <div class="no-print flex flex-wrap gap-2 justify-end mb-2">
                <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, 'overview')) ?>"
                   class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 text-slate-700 px-3.5 py-2 rounded-lg text-xs font-bold">
                    <i data-lucide="layout-dashboard" class="w-3.5 h-3.5"></i> Report overview
                </a>
            </div>
            <?php endif; ?>

            <?php if ($section !== 'overview'): ?>
            <div class="no-print">
                <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, 'overview', $section === 'records' ? $reportYear : null)) ?>" class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline">
                    <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i> Back to overview
                </a>
            </div>
            <?php endif; ?>

            <!-- Overview -->
            <div class="report-panel" <?= $section !== 'overview' ? 'hidden' : '' ?>>
                <div class="report-overview">
                    <section class="report-section">
                        <div class="report-section-head">
                            <h2 class="report-section-head__title">Period summary</h2>
                            <p class="report-section-head__hint"><?= htmlspecialchars($rangeLabel) ?></p>
                        </div>
                        <div class="report-metric-grid">
                            <?php foreach ($periodMetrics as $card): ?>
                            <div class="report-metric-card">
                                <div class="report-metric-card__icon <?= $card['iconBg'] ?>">
                                    <i data-lucide="<?= $card['icon'] ?>" class="w-4 h-4 <?= $card['iconText'] ?>"></i>
                                </div>
                                <p class="report-metric-card__label"><?= htmlspecialchars($card['label']) ?></p>
                                <p class="report-metric-card__value"><?= number_format((int) $card['value']) ?></p>
                                <p class="report-metric-card__hint"><?= htmlspecialchars($card['hint']) ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="report-section no-print">
                        <div class="report-section-head">
                            <h2 class="report-section-head__title">Jump to detail</h2>
                            <p class="report-section-head__hint">Charts, tables, and CSV export by area</p>
                        </div>
                        <div class="report-nav-grid">
                            <?php
                            $detailLinks = [
                                ['key' => 'analytics', 'desc' => 'Dashboard-style charts — records, print volume, intake, and queue', 'iconBg' => 'bg-indigo-50', 'iconText' => 'text-indigo-600', 'navBorder' => '#c7d2fe', 'wide' => false],
                                ['key' => 'requests', 'desc' => 'Track submissions, status, and document types', 'iconBg' => 'bg-blue-50', 'iconText' => 'text-blue-600', 'navBorder' => '#bfdbfe', 'wide' => false],
                                ['key' => 'appointments', 'desc' => 'Scheduled visits and appointment status', 'iconBg' => 'bg-purple-50', 'iconText' => 'text-purple-600', 'navBorder' => '#ddd6fe', 'wide' => false],
                                ['key' => 'queue', 'desc' => 'Queue tickets served, waiting, and by purpose', 'iconBg' => 'bg-teal-50', 'iconText' => 'text-teal-600', 'navBorder' => '#99f6e4', 'wide' => false],
                                ['key' => 'prints', 'desc' => sprintf(
                                    '%s certification · %s certificate print jobs in %s',
                                    number_format((int) ($summary['certifications_printed'] ?? 0)),
                                    number_format((int) ($summary['certificates_printed'] ?? 0)),
                                    strtolower($rangeLabel)
                                ), 'iconBg' => 'bg-cyan-50', 'iconText' => 'text-cyan-700', 'navBorder' => '#a5f3fc', 'wide' => false],
                                ['key' => 'records', 'desc' => $recordsJumpDesc, 'iconBg' => 'bg-amber-50', 'iconText' => 'text-amber-700', 'navBorder' => '#fde68a', 'wide' => false],
                            ];
                            foreach ($detailLinks as $link):
                                $tab = $reportTabs[$link['key']];
                                $wideClass = !empty($link['wide']) ? ' report-nav-card--wide' : '';
                            ?>
                            <a href="<?= htmlspecialchars(reportPageUrl($range, $fromDate, $toDate, $link['key'], $link['key'] === 'records' ? $reportYear : null)) ?>"
                               class="report-nav-card<?= $wideClass ?>"
                               style="--report-nav-border: <?= htmlspecialchars($link['navBorder']) ?>">
                                <div class="report-nav-card__icon <?= $link['iconBg'] ?>">
                                    <i data-lucide="<?= $tab['icon'] ?>" class="w-5 h-5 <?= $link['iconText'] ?>"></i>
                                </div>
                                <div class="report-nav-card__body">
                                    <div class="report-nav-card__title-row">
                                        <p class="report-nav-card__title"><?= htmlspecialchars($tab['label']) ?></p>
                                        <?php if ($tab['count'] !== null): ?>
                                        <span class="report-nav-card__badge"><?= number_format((int) $tab['count']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="report-nav-card__desc"><?= htmlspecialchars($link['desc']) ?></p>
                                </div>
                                <i data-lucide="chevron-right" class="report-nav-card__chevron"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </div>

            <!-- Analytics (same charts as admin dashboard) -->
            <?php if ($analytics !== null): ?>
            <div class="report-panel no-print" <?= $section !== 'analytics' ? 'hidden' : '' ?>>
                <?php
                $adminAnalytics = $analytics;
                $adminAnalyticsContext = 'report';
                require __DIR__ . '/includes/admin_dashboard_analytics.php';
                ?>
            </div>
            <?php endif; ?>

            <!-- Document Requests -->
            <div class="report-panel" <?= $section !== 'requests' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Document Requests</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= count($report['requests']) ?> record(s) in <?= htmlspecialchars(strtolower($rangeLabel)) ?></p>
                        </div>
                    </div>
                    <div class="no-print px-5 py-4 border-b border-gray-100">
                        <?php if (empty($requestsReportCharts['hasData'])): ?>
                        <p class="text-sm text-gray-400 text-center py-8 rounded-xl bg-slate-50 border border-slate-100">No document requests for this period.</p>
                        <?php else: ?>
                        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                            <div class="analytics-chart-card xl:col-span-3">
                                <div class="analytics-chart-head">
                                    <h2>Submissions in period</h2>
                                    <p><?= htmlspecialchars($rangeLabel) ?> · by submission date</p>
                                </div>
                                <div class="chart-box chart-box--compact"><canvas id="chartRequestsPeriodTrend"></canvas></div>
                            </div>
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>By status</h2>
                                    <p>Current period</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartRequestsByStatus"></canvas></div>
                            </div>
                            <div class="analytics-chart-card xl:col-span-2">
                                <div class="analytics-chart-head">
                                    <h2>By document type</h2>
                                    <p>Current period</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartRequestsByType"></canvas></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto print-table-wrap print-landscape">
                        <table class="w-full text-sm text-left print-table">
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
            <div class="report-panel" <?= $section !== 'appointments' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Appointments</h2>
                            <p class="text-xs text-gray-400 mt-0.5"><?= count($report['appointments']) ?> visit(s) in <?= htmlspecialchars(strtolower($rangeLabel)) ?></p>
                        </div>
                    </div>
                    <div class="no-print px-5 py-4 border-b border-gray-100">
                        <?php if (empty($appointmentsReportCharts['hasData'])): ?>
                        <p class="text-sm text-gray-400 text-center py-8 rounded-xl bg-slate-50 border border-slate-100">No appointments for this period.</p>
                        <?php else: ?>
                        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                            <div class="analytics-chart-card xl:col-span-2">
                                <div class="analytics-chart-head">
                                    <h2>Visits in period</h2>
                                    <p><?= htmlspecialchars($rangeLabel) ?> · by appointment date</p>
                                </div>
                                <div class="chart-box chart-box--compact"><canvas id="chartAppointmentsPeriodTrend"></canvas></div>
                            </div>
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>By status</h2>
                                    <p>Current period</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartAppointmentsByStatus"></canvas></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto print-table-wrap print-landscape">
                        <table class="w-full text-sm text-left print-table">
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
            <div class="report-panel" <?= $section !== 'queue' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Queue Performance</h2>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <?= (int) $summary['queue_served'] ?> served · <?= (int) $summary['queue_waiting'] ?> waiting · <?= (int) $summary['queue_skipped'] ?> no-show
                            </p>
                        </div>
                    </div>
                    <div class="no-print px-5 py-4 border-b border-gray-100">
                        <?php if (empty($queueReportCharts['hasData'])): ?>
                        <p class="text-sm text-gray-400 text-center py-8 rounded-xl bg-slate-50 border border-slate-100">No called queue tickets in this period — wait-time charts need at least one ticket that was called.</p>
                        <?php else: ?>
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>Average wait by line</h2>
                                    <p><?= htmlspecialchars($rangeLabel) ?> · minutes before first call</p>
                                </div>
                                <?php if (empty($queueReportCharts['byPurpose']['labels'])): ?>
                                <p class="text-sm text-gray-400 text-center py-6">No wait breakdown by purpose.</p>
                                <?php else: ?>
                                <div class="chart-box chart-box--compact"><canvas id="chartQueueReportWaitByPurpose"></canvas></div>
                                <?php endif; ?>
                            </div>
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>Daily wait trend</h2>
                                    <p>Average minutes before first call</p>
                                </div>
                                <?php if (empty($queueReportCharts['daily']['labels'])): ?>
                                <p class="text-sm text-gray-400 text-center py-6"><?= $fromDate !== $toDate ? 'Need more than one day with called tickets for a trend line.' : 'Select a multi-day range to see a daily trend.' ?></p>
                                <?php else: ?>
                                <div class="chart-box chart-box--compact"><canvas id="chartQueueReportWaitDaily"></canvas></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php
                    $queueWaitSummary = $report['queue_wait_summary'] ?? [];
                    if (!empty($queueWaitSummary['called_count'])):
                    ?>
                    <div class="px-5 py-4 border-b border-gray-50 bg-slate-50/60">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Wait before first call</p>
                        <p class="text-sm text-slate-700 mb-3">
                            Average across <strong><?= (int) $queueWaitSummary['called_count'] ?></strong> called ticket(s) in this period:
                            <strong class="text-slate-900"><?= htmlspecialchars(formatQueueWaitDuration($queueWaitSummary['avg_seconds'] ?? null)) ?></strong>
                        </p>
                        <?php foreach ($queueWaitSummary['by_purpose'] ?? [] as $purposeKey => $purposeStats): ?>
                        <div class="stat-row text-sm">
                            <span class="text-slate-600"><?= htmlspecialchars($purposeLabels[$purposeKey] ?? ucfirst($purposeKey)) ?></span>
                            <span class="font-bold text-slate-900">
                                <?= htmlspecialchars(formatQueueWaitDuration($purposeStats['avg_seconds'] ?? null)) ?>
                                <span class="text-[10px] font-semibold text-gray-400 ml-1">(<?= (int) ($purposeStats['called_count'] ?? 0) ?> called)</span>
                            </span>
                        </div>
                        <?php endforeach; ?>
                        <p class="text-[10px] text-gray-400 mt-3 leading-snug">Time from ticket issued until staff first calls the number (not affected by “Call again”).</p>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($report['queue_by_purpose'])): ?>
                    <div class="px-5 py-4 border-b border-gray-50 bg-gray-50/40">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By purpose</p>
                        <?php foreach ($report['queue_by_purpose'] as $purpose => $count): ?>
                        <div class="stat-row text-sm"><span class="text-slate-600"><?= htmlspecialchars($purposeLabels[$purpose] ?? ucfirst($purpose)) ?></span><span class="font-bold text-slate-900"><?= $count ?></span></div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="overflow-x-auto print-table-wrap print-landscape">
                        <table class="w-full text-sm text-left print-table">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Ticket</th>
                                    <th class="px-5 py-3">Purpose</th>
                                    <th class="px-5 py-3">Status</th>
                                    <th class="px-5 py-3 hidden md:table-cell">Issued</th>
                                    <th class="px-5 py-3 hidden lg:table-cell">First called</th>
                                    <th class="px-5 py-3 text-right">Wait</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['queue_tickets'])): ?>
                                <tr><td colspan="6" class="px-5 py-12 text-center text-gray-400 text-sm">No queue tickets for this period.</td></tr>
                                <?php else: foreach ($report['queue_tickets'] as $row): ?>
                                <?php $firstCalled = $row['first_called_at'] ?? $row['called_at'] ?? null; ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 font-mono text-xs font-bold text-slate-800"><?= htmlspecialchars($row['ticket_number']) ?></td>
                                    <td class="px-5 py-3 text-slate-600"><?= htmlspecialchars($purposeLabels[$row['purpose']] ?? $row['purpose']) ?></td>
                                    <td class="px-5 py-3 capitalize text-slate-600"><?= htmlspecialchars($row['status']) ?></td>
                                    <td class="px-5 py-3 text-gray-400 text-xs hidden md:table-cell whitespace-nowrap"><?= htmlspecialchars(formatReportDateTime($row['created_at'] ?? null)) ?></td>
                                    <td class="px-5 py-3 text-gray-400 text-xs hidden lg:table-cell whitespace-nowrap"><?= $firstCalled ? htmlspecialchars(formatReportDateTime($firstCalled)) : '—' ?></td>
                                    <td class="px-5 py-3 text-right font-semibold text-slate-800 text-xs whitespace-nowrap"><?= htmlspecialchars($row['wait_label'] ?? formatQueueWaitDuration($row['wait_seconds'] ?? null)) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <!-- Prints -->
            <div class="report-panel" <?= $section !== 'prints' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Print volume</h2>
                            <p class="text-xs text-gray-400 mt-0.5">
                                <?= count($report['print_jobs'] ?? []) ?> completed production job(s) in <?= htmlspecialchars(strtolower($rangeLabel)) ?>
                                · <?= number_format((int) ($summary['certifications_printed'] ?? 0)) ?> certification · <?= number_format((int) ($summary['certificates_printed'] ?? 0)) ?> certificate
                            </p>
                        </div>
                    </div>
                    <div class="no-print px-5 py-4 border-b border-gray-100">
                        <?php if (empty($printsReportCharts['hasData'])): ?>
                        <p class="text-sm text-gray-400 text-center py-8 rounded-xl bg-slate-50 border border-slate-100">No completed print jobs for this period.</p>
                        <?php else: ?>
                        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                            <div class="analytics-chart-card xl:col-span-3">
                                <div class="analytics-chart-head">
                                    <h2>Print jobs in period</h2>
                                    <p><?= htmlspecialchars($rangeLabel) ?> · certifications and certificates by print date</p>
                                </div>
                                <div class="chart-box chart-box--compact"><canvas id="chartPrintsPeriodTrend"></canvas></div>
                            </div>
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>By document kind</h2>
                                    <p>Certification vs certificate</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartPrintsByKind"></canvas></div>
                            </div>
                            <div class="analytics-chart-card xl:col-span-2">
                                <div class="analytics-chart-head">
                                    <h2>By record type</h2>
                                    <p>Birth, death, and marriage</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartPrintsByType"></canvas></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto print-table-wrap print-landscape">
                        <table class="w-full text-sm text-left print-table">
                            <thead class="bg-white text-[10px] font-bold uppercase text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th class="px-5 py-3">Printed</th>
                                    <th class="px-5 py-3">Kind</th>
                                    <th class="px-5 py-3 hidden md:table-cell">Record type</th>
                                    <th class="px-5 py-3">Source</th>
                                    <th class="px-5 py-3 hidden lg:table-cell">Staff</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['print_jobs'])): ?>
                                <tr><td colspan="5" class="px-5 py-12 text-center text-gray-400 text-sm">No completed print jobs for this period.</td></tr>
                                <?php else: foreach ($report['print_jobs'] as $row): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-3 text-gray-500 text-xs whitespace-nowrap"><?= htmlspecialchars(formatReportDateTime($row['printed_at'] ?? null)) ?></td>
                                    <td class="px-5 py-3 font-semibold text-slate-800"><?= htmlspecialchars(printJobDocumentKindLabel((string) ($row['document_kind'] ?? ''))) ?></td>
                                    <td class="px-5 py-3 text-gray-500 hidden md:table-cell"><?= htmlspecialchars(civilRecordTypeLabel((string) ($row['certificate_type'] ?? ''))) ?></td>
                                    <td class="px-5 py-3 text-xs text-slate-600"><?= htmlspecialchars(printJobSourceSummary($row)) ?></td>
                                    <td class="px-5 py-3 font-mono text-[11px] text-gray-400 hidden lg:table-cell"><?= htmlspecialchars((string) ($row['printed_by'] ?? '—')) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-5 py-3 border-t border-gray-100 bg-gray-50/50">
                        <p class="text-[11px] text-gray-500">Counts include <strong class="text-slate-600">production</strong> print jobs marked <strong class="text-slate-600">completed</strong> only (same logs as dashboard analytics and system maintenance cleanup).</p>
                    </div>
                </section>
            </div>

            <!-- Civil Records (Quarterly) -->
            <div class="report-panel" <?= $section !== 'records' ? 'hidden' : '' ?>>
                <section class="report-section bg-white border border-gray-100 rounded-xl shadow-sm overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-100">
                        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                            <div>
                                <h2 class="text-base font-black text-slate-900">Civil Records — Quarterly Registration</h2>
                                <p class="text-xs text-gray-400 mt-0.5">Registrations in <?= (int) $reportYear ?> by quarter, type, and month · compared to <?= (int) ($recordsReport['prior_year'] ?? $reportYear - 1) ?>.</p>
                            </div>
                            <a href="<?= htmlspecialchars($recordsRegistryUrl) ?>" class="no-print inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 hover:underline shrink-0">
                                View details
                                <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
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
                        <?php
                        $style = $recordTypeStyles[$type];
                        $typeYoy = $recordsReport['type_yoy'][$type] ?? null;
                        ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-100">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="p-1.5 <?= $style['bg'] ?> rounded-lg">
                                    <i data-lucide="<?= $style['icon'] ?>" class="w-4 h-4 <?= $style['text'] ?>"></i>
                                </div>
                                <p class="text-[10px] font-bold uppercase text-gray-400"><?= htmlspecialchars(civilRecordTypeLabel($type)) ?></p>
                            </div>
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <p class="text-2xl font-black text-slate-900"><?= number_format((int) $recordsReport['year_totals'][$type]) ?></p>
                                <?php if ($typeYoy !== null): ?>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full <?= !empty($typeYoy['up']) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' ?>">
                                    <?= !empty($typeYoy['up']) ? '↑' : '↓' ?> <?= htmlspecialchars((string) ($typeYoy['label'] ?? '')) ?> vs <?= (int) ($recordsReport['prior_year'] ?? $reportYear - 1) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1"><?= (int) $reportYear ?> · prior year <?= number_format((int) ($recordsReport['prior_year_totals'][$type] ?? 0)) ?></p>
                        </div>
                        <?php endforeach; ?>
                        <?php $yearYoy = $recordsReport['year_yoy'] ?? null; ?>
                        <div class="bg-white p-4 rounded-xl border border-gray-100">
                            <div class="flex items-center gap-2 mb-2">
                                <div class="p-1.5 bg-slate-100 rounded-lg">
                                    <i data-lucide="layers" class="w-4 h-4 text-slate-600"></i>
                                </div>
                                <p class="text-[10px] font-bold uppercase text-gray-400">All types</p>
                            </div>
                            <div class="flex items-baseline gap-2 flex-wrap">
                                <p class="text-2xl font-black text-slate-900"><?= number_format((int) $recordsReport['year_totals']['total']) ?></p>
                                <?php if ($yearYoy !== null): ?>
                                <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full <?= !empty($yearYoy['up']) ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-800' ?>">
                                    <?= !empty($yearYoy['up']) ? '↑' : '↓' ?> <?= htmlspecialchars((string) ($yearYoy['label'] ?? '')) ?> vs <?= (int) ($recordsReport['prior_year'] ?? $reportYear - 1) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1">Prior year total <?= number_format((int) ($recordsReport['prior_year_totals']['total'] ?? 0)) ?></p>
                        </div>
                    </div>

                    <?php if (!empty($recordsReport['peak_quarter'])): ?>
                    <div class="px-5 py-3 border-b border-gray-50 bg-amber-50/40 text-xs text-slate-700">
                        <strong class="text-slate-900">Busiest quarter:</strong>
                        <?= htmlspecialchars($recordsReport['peak_quarter']['label']) ?>
                        with <?= number_format((int) $recordsReport['peak_quarter']['total']) ?> registration(s)
                        (<?= number_format((int) $recordsReport['peak_quarter']['birth']) ?> birth ·
                        <?= number_format((int) $recordsReport['peak_quarter']['death']) ?> death ·
                        <?= number_format((int) $recordsReport['peak_quarter']['marriage']) ?> marriage).
                    </div>
                    <?php endif; ?>

                    <div class="no-print px-5 py-4 border-b border-gray-100">
                        <?php if (empty($recordsReport['chart_payload']['hasData'])): ?>
                        <p class="text-sm text-gray-400 text-center py-8 rounded-xl bg-slate-50 border border-slate-100">No registrations in <?= (int) $reportYear ?> yet — charts appear when records are registered.</p>
                        <?php else: ?>
                        <div class="grid grid-cols-1 xl:grid-cols-3 gap-4">
                            <div class="analytics-chart-card xl:col-span-2">
                                <div class="analytics-chart-head">
                                    <h2>Registrations by quarter</h2>
                                    <p>Birth, death, and marriage · <?= (int) $reportYear ?></p>
                                </div>
                                <div class="chart-box chart-box--compact"><canvas id="chartRecordsQuarterly"></canvas></div>
                            </div>
                            <div class="analytics-chart-card">
                                <div class="analytics-chart-head">
                                    <h2>Share by type</h2>
                                    <p>Year total composition</p>
                                </div>
                                <div class="chart-box chart-box--donut"><canvas id="chartRecordsReportTypes"></canvas></div>
                            </div>
                            <div class="analytics-chart-card xl:col-span-3">
                                <div class="analytics-chart-head">
                                    <h2>Monthly volume</h2>
                                    <p>All record types · registration date in <?= (int) $reportYear ?></p>
                                </div>
                                <div class="chart-box chart-box--compact"><canvas id="chartRecordsMonthly"></canvas></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="overflow-x-auto print-table-wrap print-landscape">
                        <table class="w-full text-sm text-left print-table">
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
        </div>
    </main>
    <?= scriptTag('admin/report.js') ?>
    <?php if ($section === 'analytics' && $analytics !== null): ?>
    <?= pageConfigJson($analytics['chartPayload'], 'analytics-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/analytics.js') ?>
    <?php endif; ?>
    <?php if ($section === 'records'): ?>
    <?= pageConfigJson($recordsReport['chart_payload'] ?? [], 'records-report-chart-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/report-records-charts.js') ?>
    <?php endif; ?>
    <?php if ($section === 'requests' || $section === 'appointments' || $section === 'queue' || $section === 'prints'): ?>
    <?= pageConfigJson([
        'requests' => $requestsReportCharts,
        'appointments' => $appointmentsReportCharts,
        'queueWait' => $queueReportCharts,
        'prints' => $printsReportCharts,
    ], 'operational-report-charts-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/report-operational-charts.js') ?>
    <?php endif; ?>
    <?= lucideInitScript() ?>
</body>
</html>
