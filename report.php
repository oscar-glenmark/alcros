<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireAdmin();

$activePage = 'report.php';
$pdo = getDB();

$range = $_GET['range'] ?? 'today';
$fromInput = $_GET['from'] ?? '';
$toInput = $_GET['to'] ?? '';
[$fromDate, $toDate] = resolveReportDateRange($range, $fromInput, $toInput);
$rangeLabel = reportRangeLabel($range, $fromDate, $toDate);

$downloadType = $_GET['download'] ?? '';
$validDownloads = ['full', 'summary', 'requests', 'appointments', 'queue', 'activity'];
if (isset($_GET['action']) && $_GET['action'] === 'download' && in_array($downloadType, $validDownloads, true)) {
    $exportReport = buildOperationalReport($pdo, $fromDate, $toDate);
    logActivity(staffId(), 'Report Downloaded', 'Operational report (' . $downloadType . ') for ' . $rangeLabel);
    exportOperationalReportCsv($exportReport, $downloadType);
    exit;
}

$report = buildOperationalReport($pdo, $fromDate, $toDate);
$summary = $report['summary'];

function reportDownloadUrl(string $type, string $range, string $from, string $to): string
{
    return buildAuthUrl('report.php', array_filter([
        'action' => 'download',
        'download' => $type,
        'range' => $range,
        'from' => $range === 'custom' ? $from : null,
        'to' => $range === 'custom' ? $to : null,
    ]));
}

function reportPageUrl(string $range, string $from, string $to): string
{
    return buildAuthUrl('report.php', array_filter([
        'range' => $range !== 'today' ? $range : null,
        'from' => $range === 'custom' ? $from : null,
        'to' => $range === 'custom' ? $to : null,
    ]));
}

$purposeLabels = ['walk_in' => 'Walk-in', 'appointment' => 'Appointment', 'document_claim' => 'Document claim'];

$summaryCards = [
    ['label' => 'Requests Submitted', 'value' => $summary['requests_submitted'], 'hint' => 'In selected period', 'icon' => 'file-text', 'iconBg' => 'bg-blue-50', 'iconText' => 'text-blue-600'],
    ['label' => 'Requests Completed', 'value' => $summary['requests_completed'], 'hint' => 'Marked completed in period', 'icon' => 'circle-check', 'iconBg' => 'bg-emerald-50', 'iconText' => 'text-emerald-600'],
    ['label' => 'Appointments', 'value' => $summary['appointments_scheduled'], 'hint' => 'Scheduled in period', 'icon' => 'calendar', 'iconBg' => 'bg-purple-50', 'iconText' => 'text-purple-600'],
    ['label' => 'Queue Served', 'value' => $summary['queue_served'], 'hint' => 'Tickets completed in period', 'icon' => 'users', 'iconBg' => 'bg-green-50', 'iconText' => 'text-green-600'],
    ['label' => 'Pending Now', 'value' => $summary['pending_requests'], 'hint' => 'Current backlog', 'icon' => 'clock', 'iconBg' => 'bg-amber-50', 'iconText' => 'text-amber-600'],
    ['label' => 'Ready for Pickup', 'value' => $summary['ready_for_pickup'], 'hint' => 'Current queue for release', 'icon' => 'package', 'iconBg' => 'bg-teal-50', 'iconText' => 'text-teal-600'],
];

$rangeOptions = [
    'today' => 'Today',
    'week' => 'Last 7 Days',
    'month' => 'This Month',
    'custom' => 'Custom Range',
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
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        .sidebar-item:hover { background-color: #f1f5f9; }
        .active-nav { background-color: #2563eb; color: white !important; }
        @media print {
            .admin-sidebar, .no-print, header { display: none !important; }
            .admin-main { margin-left: 0 !important; width: 100% !important; }
            .report-section { break-inside: avoid; }
        }
    </style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-6 lg:p-10 max-w-7xl w-full mx-auto space-y-6">
            <div class="no-print">
                <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                    <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                </a>
                <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-widest text-blue-600 mb-1">Registry Admin</p>
                        <h1 class="text-2xl lg:text-3xl font-black text-slate-900">Operational Reports</h1>
                        <p class="text-gray-500 text-sm mt-1"><?= htmlspecialchars($report['office_name']) ?> · <?= htmlspecialchars($rangeLabel) ?></p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="window.print()" class="inline-flex items-center gap-2 bg-white border border-gray-200 hover:border-gray-300 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold">
                            <i data-lucide="printer" class="w-3.5 h-3.5"></i> Print
                        </button>
                        <div class="relative" id="reportDownloadMenu">
                            <button type="button" id="reportDownloadBtn" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold">
                                <i data-lucide="download" class="w-3.5 h-3.5"></i> Download CSV
                                <i data-lucide="chevron-down" class="w-3.5 h-3.5"></i>
                            </button>
                            <div id="reportDownloadPanel" class="hidden absolute right-0 mt-2 w-56 bg-white border border-gray-100 rounded-xl shadow-lg z-20 py-1">
                                <a href="<?= htmlspecialchars(reportDownloadUrl('full', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Full report (all sections)</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('summary', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Summary metrics only</a>
                                <div class="border-t border-gray-100 my-1"></div>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('requests', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Document requests</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('appointments', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Appointments</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('queue', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Queue tickets</a>
                                <a href="<?= htmlspecialchars(reportDownloadUrl('activity', $range, $fromDate, $toDate)) ?>" class="block px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-gray-50">Staff activity log</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="no-print bg-white border border-gray-100 rounded-2xl p-4 lg:p-5 shadow-sm">
                <form method="GET" class="flex flex-col lg:flex-row lg:items-end gap-4">
                    <?php if ($token = staffAuthToken()): ?>
                    <input type="hidden" name="alcros_auth" value="<?= htmlspecialchars($token) ?>">
                    <?php endif; ?>
                    <div class="flex-1">
                        <label class="block text-[10px] font-bold uppercase text-gray-400 mb-2">Report Period</label>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($rangeOptions as $key => $label): ?>
                            <a href="<?= htmlspecialchars(reportPageUrl($key, $fromDate, $toDate)) ?>"
                               class="px-3 py-2 rounded-xl text-xs font-bold border <?= $range === $key ? 'bg-blue-600 border-blue-600 text-white' : 'bg-gray-50 border-gray-200 text-slate-600 hover:border-blue-200' ?>">
                                <?= htmlspecialchars($label) ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-gray-400 mb-1">From</label>
                            <input type="date" name="from" value="<?= htmlspecialchars($fromDate) ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-[10px] font-bold uppercase text-gray-400 mb-1">To</label>
                            <input type="date" name="to" value="<?= htmlspecialchars($toDate) ?>" class="w-full border border-gray-200 rounded-xl px-3 py-2 text-sm">
                        </div>
                    </div>
                    <input type="hidden" name="range" value="custom">
                    <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-xs font-bold">Apply Range</button>
                </form>
            </div>

            <div class="hidden print:block mb-6">
                <h1 class="text-2xl font-black text-slate-900"><?= htmlspecialchars($report['site_name']) ?> Operational Report</h1>
                <p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($report['office_name']) ?></p>
                <p class="text-sm text-gray-500">Period: <?= htmlspecialchars($rangeLabel) ?> · Generated: <?= htmlspecialchars($report['generated_at']) ?></p>
            </div>

            <section class="report-section">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-black text-slate-900 uppercase tracking-wide">Executive Summary</h2>
                    <a href="<?= htmlspecialchars(reportDownloadUrl('summary', $range, $fromDate, $toDate)) ?>" class="no-print text-[10px] font-bold text-blue-600 hover:underline">Download summary CSV</a>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
                    <?php foreach ($summaryCards as $card): ?>
                    <div class="bg-white p-5 rounded-2xl border border-gray-100 shadow-sm flex items-start gap-4">
                        <div class="p-3 <?= $card['iconBg'] ?> rounded-xl shrink-0">
                            <i data-lucide="<?= $card['icon'] ?>" class="w-5 h-5 <?= $card['iconText'] ?>"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-gray-400 uppercase"><?= htmlspecialchars($card['label']) ?></p>
                            <p class="text-3xl font-black text-slate-900 leading-none mt-1"><?= (int) $card['value'] ?></p>
                            <p class="text-[11px] text-gray-400 mt-1"><?= htmlspecialchars($card['hint']) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <p class="text-[11px] text-gray-400 mt-3">Civil registry records on file: <strong class="text-slate-700"><?= (int) $summary['total_records'] ?></strong></p>
            </section>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <section class="report-section bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Document Requests</h2>
                            <p class="text-[11px] text-gray-400"><?= count($report['requests']) ?> submission(s) in period</p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('requests', $range, $fromDate, $toDate)) ?>" class="no-print text-[10px] font-bold text-blue-600 hover:underline">Download CSV</a>
                    </div>
                    <div class="p-5 grid grid-cols-2 gap-4 border-b border-gray-50">
                        <div>
                            <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By Status</p>
                            <?php if (empty($report['requests_by_status'])): ?>
                            <p class="text-xs text-gray-400 italic">No requests in this period.</p>
                            <?php else: foreach ($report['requests_by_status'] as $status => $count): ?>
                            <div class="flex justify-between text-xs py-1"><span><?= htmlspecialchars(requestStatusLabel($status)) ?></span><span class="font-bold"><?= $count ?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By Document Type</p>
                            <?php if (empty($report['requests_by_type'])): ?>
                            <p class="text-xs text-gray-400 italic">No requests in this period.</p>
                            <?php else: foreach ($report['requests_by_type'] as $type => $count): ?>
                            <div class="flex justify-between text-xs py-1"><span><?= htmlspecialchars(documentTypeLabel($type)) ?></span><span class="font-bold"><?= $count ?></span></div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                    <div class="overflow-x-auto max-h-72 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2">Tracking</th>
                                    <th class="px-4 py-2">Citizen</th>
                                    <th class="px-4 py-2">Type</th>
                                    <th class="px-4 py-2">Status</th>
                                    <th class="px-4 py-2">Submitted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['requests'])): ?>
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 italic">No document requests for this period.</td></tr>
                                <?php else: foreach ($report['requests'] as $row): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-4 py-2 font-mono font-bold text-blue-600"><?= htmlspecialchars($row['tracking_code']) ?></td>
                                    <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($row['citizen_name']) ?></td>
                                    <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars(documentTypeLabel($row['document_type'])) ?></td>
                                    <td class="px-4 py-2"><span class="text-[10px] font-bold uppercase"><?= htmlspecialchars(requestStatusLabel($row['status'])) ?></span></td>
                                    <td class="px-4 py-2 text-gray-400"><?= htmlspecialchars(formatDateDisplay(substr($row['submitted_at'], 0, 10))) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="report-section bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Appointments</h2>
                            <p class="text-[11px] text-gray-400"><?= count($report['appointments']) ?> visit(s) in period</p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('appointments', $range, $fromDate, $toDate)) ?>" class="no-print text-[10px] font-bold text-blue-600 hover:underline">Download CSV</a>
                    </div>
                    <div class="p-5 border-b border-gray-50">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By Status</p>
                        <?php if (empty($report['appointments_by_status'])): ?>
                        <p class="text-xs text-gray-400 italic">No appointments in this period.</p>
                        <?php else: ?>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($report['appointments_by_status'] as $status => $count): ?>
                            <span class="inline-flex items-center gap-1.5 bg-gray-50 border border-gray-100 rounded-lg px-2.5 py-1 text-[11px] font-semibold">
                                <?= htmlspecialchars(appointmentStatusLabel($status)) ?>
                                <span class="font-black text-slate-900"><?= $count ?></span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto max-h-72 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2">Code</th>
                                    <th class="px-4 py-2">Citizen</th>
                                    <th class="px-4 py-2">Service</th>
                                    <th class="px-4 py-2">Schedule</th>
                                    <th class="px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['appointments'])): ?>
                                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-400 italic">No appointments for this period.</td></tr>
                                <?php else: foreach ($report['appointments'] as $row): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-4 py-2 font-mono font-bold text-blue-600"><?= htmlspecialchars($row['appointment_code']) ?></td>
                                    <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($row['citizen_name']) ?></td>
                                    <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars(appointmentServiceLabel($row['service_type'])) ?></td>
                                    <td class="px-4 py-2 text-gray-500"><?= htmlspecialchars(formatDateDisplay($row['appointment_date'])) ?> · <?= date('g:i A', strtotime($row['appointment_time'])) ?></td>
                                    <td class="px-4 py-2"><span class="text-[10px] font-bold uppercase"><?= htmlspecialchars(appointmentStatusLabel($row['status'])) ?></span></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                <section class="report-section bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Queue Performance</h2>
                            <p class="text-[11px] text-gray-400"><?= (int) $summary['queue_served'] ?> served · <?= (int) $summary['queue_waiting'] ?> waiting · <?= (int) $summary['queue_skipped'] ?> no-show</p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('queue', $range, $fromDate, $toDate)) ?>" class="no-print text-[10px] font-bold text-blue-600 hover:underline">Download CSV</a>
                    </div>
                    <div class="p-5 border-b border-gray-50">
                        <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">By Purpose</p>
                        <?php if (empty($report['queue_by_purpose'])): ?>
                        <p class="text-xs text-gray-400 italic">No queue activity in this period.</p>
                        <?php else: foreach ($report['queue_by_purpose'] as $purpose => $count): ?>
                        <div class="flex justify-between text-xs py-1"><span><?= htmlspecialchars($purposeLabels[$purpose] ?? ucfirst($purpose)) ?></span><span class="font-bold"><?= $count ?></span></div>
                        <?php endforeach; endif; ?>
                    </div>
                    <div class="overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2">Ticket</th>
                                    <th class="px-4 py-2">Purpose</th>
                                    <th class="px-4 py-2">Status</th>
                                    <th class="px-4 py-2">Created</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['queue_tickets'])): ?>
                                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 italic">No queue tickets for this period.</td></tr>
                                <?php else: foreach (array_slice($report['queue_tickets'], 0, 50) as $row): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-4 py-2 font-mono font-bold"><?= htmlspecialchars($row['ticket_number']) ?></td>
                                    <td class="px-4 py-2"><?= htmlspecialchars($purposeLabels[$row['purpose']] ?? $row['purpose']) ?></td>
                                    <td class="px-4 py-2 capitalize"><?= htmlspecialchars($row['status']) ?></td>
                                    <td class="px-4 py-2 text-gray-400"><?= htmlspecialchars(formatDateDisplay(substr($row['created_at'], 0, 10))) ?></td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="report-section bg-white border border-gray-100 rounded-2xl overflow-hidden shadow-sm">
                    <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Staff Activity Log</h2>
                            <p class="text-[11px] text-gray-400">Latest actions in selected period (max 500)</p>
                        </div>
                        <a href="<?= htmlspecialchars(reportDownloadUrl('activity', $range, $fromDate, $toDate)) ?>" class="no-print text-[10px] font-bold text-blue-600 hover:underline">Download CSV</a>
                    </div>
                    <div class="overflow-x-auto max-h-96 overflow-y-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2">Staff</th>
                                    <th class="px-4 py-2">Action</th>
                                    <th class="px-4 py-2">Details</th>
                                    <th class="px-4 py-2">When</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php if (empty($report['activities'])): ?>
                                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-400 italic">No staff activity logged for this period.</td></tr>
                                <?php else: foreach ($report['activities'] as $row): ?>
                                <tr class="hover:bg-gray-50/50">
                                    <td class="px-4 py-2 font-mono text-[11px]"><?= htmlspecialchars($row['staff_id'] ?? '—') ?></td>
                                    <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($row['action']) ?></td>
                                    <td class="px-4 py-2 text-gray-500 max-w-xs truncate"><?= htmlspecialchars($row['details'] ?? '') ?></td>
                                    <td class="px-4 py-2 text-gray-400 whitespace-nowrap"><?= htmlspecialchars($row['created_at']) ?></td>
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
