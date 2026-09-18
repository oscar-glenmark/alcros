<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireAdmin();

$pageTitle = 'Activity Log';
$pageSubtitle = 'Search and review staff actions recorded in the system.';

$activePage = 'activity-log.php';
$pdo = getDB();

$search = trim($_GET['q'] ?? '');
$staffFilter = trim($_GET['staff'] ?? '');
$range = $_GET['range'] ?? '30d';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$validRanges = ['today', '7d', '30d', '90d', 'all'];
if (!in_array($range, $validRanges, true)) {
    $range = '30d';
}

function activityLogFilters(): array
{
    global $search, $staffFilter, $range;
    return [$search, $staffFilter, $range];
}

function activityLogWhereClause(): array
{
    [$search, $staffFilter, $range] = activityLogFilters();
    $where = [];
    $params = [];

    if ($search !== '') {
        $where[] = '(action LIKE ? OR details LIKE ? OR staff_id LIKE ?)';
        $like = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if ($staffFilter !== '') {
        $where[] = 'staff_id = ?';
        $params[] = $staffFilter;
    }

    if ($range === 'today') {
        $where[] = 'DATE(created_at) = CURDATE()';
    } elseif ($range === '7d') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
    } elseif ($range === '30d') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
    } elseif ($range === '90d') {
        $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)';
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    return [$whereSql, $params];
}

function activityLogPageUrl(array $overrides = []): string
{
    global $search, $staffFilter, $range, $page;

    $params = array_filter(array_merge([
        'q' => $search !== '' ? $search : null,
        'staff' => $staffFilter !== '' ? $staffFilter : null,
        'range' => $range !== '30d' ? $range : null,
        'page' => $page > 1 ? (string) $page : null,
    ], $overrides), static fn ($value) => $value !== null && $value !== '');

    return buildAuthUrl('activity-log.php', $params);
}

[$whereSql, $params] = activityLogWhereClause();

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs $whereSql");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("SELECT id, staff_id, action, details, created_at FROM activity_logs $whereSql ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll();

ensureStaffProfileColumns($pdo);
$staffById = [];
$logStaffIds = array_values(array_unique(array_filter(array_column($logs, 'staff_id'))));
if ($logStaffIds !== []) {
    $staffPlaceholders = implode(',', array_fill(0, count($logStaffIds), '?'));
    $staffStmt = $pdo->prepare(
        "SELECT staff_id, first_name, middle_name, last_name, profile_photo_path
         FROM staff WHERE staff_id IN ($staffPlaceholders)"
    );
    $staffStmt->execute($logStaffIds);
    foreach ($staffStmt->fetchAll() as $staffRow) {
        $staffById[(string) $staffRow['staff_id']] = $staffRow;
    }
}

$staffList = $pdo->query("SELECT DISTINCT staff_id FROM activity_logs WHERE staff_id IS NOT NULL AND staff_id != '' ORDER BY staff_id")->fetchAll(PDO::FETCH_COLUMN);

$rangeOptions = [
    'today' => 'Today',
    '7d'    => 'Last 7 days',
    '30d'   => 'Last 30 days',
    '90d'   => 'Last 90 days',
    'all'   => 'All time',
];

$showingFrom = $totalCount === 0 ? 0 : $offset + 1;
$showingTo = min($offset + $perPage, $totalCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('activity-log') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap">
            <div class="admin-page-head mb-6">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-4 no-print">
                    <div class="flex flex-wrap gap-2">
                        <button type="button" id="activityLogPrintBtn"
                                class="inline-flex items-center gap-2 border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 px-4 py-2.5 rounded-xl text-xs font-bold shrink-0">
                            <i data-lucide="printer" class="w-4 h-4"></i> Print Report
                        </button>
                    </div>
                </div>
            </div>

            <div class="print-report-header">
                <h1 class="print-report-header__title">Staff Activity Log</h1>
                <p class="print-report-header__meta"><?= htmlspecialchars(getSetting('office_name', 'Local Civil Registrar Office')) ?></p>
                <p class="print-report-header__meta">
                    Range: <?= htmlspecialchars($rangeOptions[$range] ?? $range) ?>
                    <?= $search !== '' ? ' · Search: ' . htmlspecialchars($search) : '' ?>
                    <?= $staffFilter !== '' ? ' · Staff: ' . htmlspecialchars($staffFilter) : '' ?>
                    · Generated <?= date('M j, Y g:i A') ?>
                </p>
                <p class="print-report-header__meta">Showing page <?= (int) $page ?> of <?= (int) $totalPages ?> (<?= number_format($totalCount) ?> matching entries).</p>
            </div>

            <div class="bg-white border border-slate-100 rounded-2xl shadow-sm overflow-hidden mb-5 print-report-body">
                <form method="GET" action="<?= htmlspecialchars(buildAuthUrl('activity-log.php')) ?>" class="admin-toolbar !mb-0 !rounded-none !border-0 !shadow-none border-b border-slate-100">
                    <div class="relative flex-1 admin-toolbar-search">
                        <i data-lucide="search" class="w-4 h-4 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                        <input type="search" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search action, details, or staff ID…"
                               class="w-full pl-10 pr-4 py-2 bg-gray-50 border-none rounded-lg text-sm focus:ring-0 text-slate-600 placeholder-gray-400">
                    </div>
                    <select name="staff" class="border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white min-w-[10rem] shrink-0">
                        <option value="">All staff</option>
                        <?php foreach ($staffList as $sid): ?>
                        <option value="<?= htmlspecialchars($sid) ?>" <?= $staffFilter === $sid ? 'selected' : '' ?>><?= htmlspecialchars($sid) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="admin-toolbar-filters !flex-1 xl:!flex-none">
                        <?php foreach ($rangeOptions as $rangeKey => $rangeLabel): ?>
                        <a href="<?= htmlspecialchars(activityLogPageUrl(['range' => $rangeKey === '30d' ? null : $rangeKey, 'page' => null])) ?>"
                           class="range-pill px-3 py-1.5 rounded-lg text-xs font-bold border border-slate-200 text-slate-600 hover:bg-slate-50 whitespace-nowrap shrink-0 <?= $range === $rangeKey ? 'is-active' : '' ?>">
                            <?= htmlspecialchars($rangeLabel) ?>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-xs font-bold shrink-0">Apply</button>
                </form>
                <?php if ($search !== '' || $staffFilter !== ''): ?>
                <div class="px-4 py-2 border-b border-slate-100 bg-slate-50/50 text-right">
                    <a href="<?= htmlspecialchars(activityLogPageUrl(['q' => null, 'staff' => null, 'page' => null])) ?>" class="text-xs font-bold text-slate-500 hover:text-blue-600">Clear filters</a>
                </div>
                <?php endif; ?>

                <div class="px-4 sm:px-5 py-3 bg-slate-50/80 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
                    <span>
                        <?php if ($totalCount === 0): ?>
                        No entries match your filters.
                        <?php else: ?>
                        Showing <strong class="text-slate-700"><?= number_format($showingFrom) ?>–<?= number_format($showingTo) ?></strong> of <strong class="text-slate-700"><?= number_format($totalCount) ?></strong>
                        <?php endif; ?>
                    </span>
                    <?php if ($totalPages > 1): ?>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                        <a href="<?= htmlspecialchars(activityLogPageUrl(['page' => (string) ($page - 1)])) ?>" class="px-2.5 py-1 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 font-semibold">Prev</a>
                        <?php endif; ?>
                        <span class="px-2 font-semibold text-slate-600">Page <?= $page ?> / <?= $totalPages ?></span>
                        <?php if ($page < $totalPages): ?>
                        <a href="<?= htmlspecialchars(activityLogPageUrl(['page' => (string) ($page + 1)])) ?>" class="px-2.5 py-1 rounded-lg border border-slate-200 bg-white hover:bg-slate-50 font-semibold">Next</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if (empty($logs)): ?>
                <div class="p-12 text-center">
                    <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-slate-100 text-slate-400 mb-3">
                        <i data-lucide="scroll-text" class="w-6 h-6"></i>
                    </div>
                    <p class="text-sm font-semibold text-slate-600">No activity found</p>
                    <p class="text-xs text-slate-400 mt-1">Try widening the date range or clearing your search.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto print-table-wrap print-landscape">
                    <table class="w-full text-sm print-table">
                        <thead>
                            <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100 bg-white">
                                <th class="px-4 sm:px-5 py-3 font-bold">When</th>
                                <th class="px-4 sm:px-5 py-3 font-bold">Staff</th>
                                <th class="px-4 sm:px-5 py-3 font-bold">Action</th>
                                <th class="px-4 sm:px-5 py-3 font-bold hidden md:table-cell print-details-col">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php foreach ($logs as $log): ?>
                            <tr class="hover:bg-slate-50/60">
                                <td class="px-4 sm:px-5 py-3.5 whitespace-nowrap align-top">
                                    <p class="font-semibold text-slate-800 text-xs"><?= htmlspecialchars(formatDateDisplay($log['created_at'])) ?></p>
                                    <p class="text-[11px] text-slate-400 mt-0.5"><?= htmlspecialchars(formatTimeAgo($log['created_at'])) ?></p>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 align-top">
                                    <?php
                                    $logStaffId = trim((string) ($log['staff_id'] ?? ''));
                                    $logStaff = $logStaffId !== '' ? ($staffById[$logStaffId] ?? null) : null;
                                    $logStaffName = $logStaff ? personNameFromRow($logStaff) : ($logStaffId !== '' ? $logStaffId : 'System');
                                    ?>
                                    <span class="inline-flex items-center gap-2.5 text-xs font-bold text-slate-700">
                                        <?php if ($logStaffId !== ''): ?>
                                            <?= renderStaffAvatar($logStaff ? ($logStaff['profile_photo_path'] ?? null) : null, $logStaffName, 'w-8 h-8 text-[10px]') ?>
                                        <?php else: ?>
                                            <span class="w-8 h-8 rounded-full bg-slate-100 border border-slate-200 text-slate-400 flex items-center justify-center shrink-0">
                                                <i data-lucide="bot" class="w-3.5 h-3.5"></i>
                                            </span>
                                        <?php endif; ?>
                                        <span class="min-w-0">
                                            <span class="block truncate"><?= htmlspecialchars($logStaffName) ?></span>
                                            <?php if ($logStaff && $logStaffName !== $logStaffId): ?>
                                            <span class="block text-[10px] font-semibold text-slate-400 truncate"><?= htmlspecialchars($logStaffId) ?></span>
                                            <?php endif; ?>
                                        </span>
                                    </span>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 align-top">
                                    <p class="font-semibold text-slate-800"><?= htmlspecialchars($log['action']) ?></p>
                                    <?php if (!empty($log['details'])): ?>
                                    <p class="text-xs text-slate-500 mt-1 md:hidden"><?= htmlspecialchars($log['details']) ?></p>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 sm:px-5 py-3.5 text-xs text-slate-500 hidden md:table-cell print-details-col align-top max-w-md">
                                    <?= htmlspecialchars($log['details'] ?? '—') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?= scriptTag('admin/activity-log.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
