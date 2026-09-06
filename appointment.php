<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('appointment.php');

$activePage = 'appointment.php';
$pdo = getDB();
ensureCitizenNotifyColumns($pdo);

function appointmentStatusFilters(): array
{
    return ['all', 'scheduled', 'confirmed', 'completed', 'no_show', 'all_appointments', 'recently_deleted'];
}

function appointmentsRedirectFilters(): array
{
    $status = $_POST['redirect_status'] ?? $_GET['status'] ?? 'all';
    if (!in_array($status, appointmentStatusFilters(), true)) {
        $status = 'all';
    }

    $date = $_POST['redirect_date'] ?? $_GET['date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $date = date('Y-m-d');
    }

    return [
        'status' => $status,
        'date'   => $date,
        'q'      => $_POST['redirect_q'] ?? $_GET['q'] ?? '',
    ];
}

function isAppointmentAjax(): bool
{
    return ($_POST['ajax'] ?? '') === '1';
}

function appointmentJsonResponse(bool $ok, string $message): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => $ok,
        'type'    => $ok ? 'success' : 'error',
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = appointmentsRedirectFilters();
    $viewDate = $filters['date'];
    $id = (int) ($_POST['appointment_id'] ?? 0);
    $isAjax = isAppointmentAjax();
    $responseOk = false;
    $responseMessage = 'Could not complete this action. Please try again.';

    $isDelete = isset($_POST['delete_appointment']);
    $isBulkDelete = isset($_POST['bulk_delete']);
    $isBulkDeleteAll = isset($_POST['bulk_delete_all']);
    $isBulkRestore = isset($_POST['bulk_restore']);
    $isBulkPurge = isset($_POST['bulk_purge']);
    $isUpdate = isset($_POST['update_status']) || (!$isDelete && !$isBulkDelete && !$isBulkDeleteAll && !$isBulkRestore && !$isBulkPurge && isset($_POST['status']));

    if ($isUpdate) {
        $status = (string) ($_POST['status'] ?? '');
        if ($id > 0) {
            $rowStmt = $pdo->prepare('SELECT status, appointment_code FROM appointments WHERE id = ?');
            $rowStmt->execute([$id]);
            $row = $rowStmt->fetch();
            if ($row && isAllowedAppointmentStatusTransition((string) $row['status'], $status)) {
                $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $id]);
                try {
                    notifyAppointmentStatusChange($pdo, $id, $status);
                } catch (Throwable $e) {
                }
                logActivity(staffId(), 'Appointment Updated', 'Changed ' . $row['appointment_code'] . ' to ' . appointmentStatusLabel($status));
                $responseOk = true;
                $responseMessage = match ($status) {
                    'confirmed' => 'Appointment confirmed — citizen will be notified.',
                    'completed' => 'Appointment marked completed.',
                    'cancelled' => 'Appointment rejected — citizen will be notified.',
                    'no_show'   => 'Appointment marked as no-show.',
                    default     => 'Appointment status saved as ' . appointmentStatusLabel($status) . '.',
                };
                if (!$isAjax) {
                    appointmentFlashSet('success', $responseMessage);
                }
            } else {
                $responseMessage = 'Could not update appointment status. Please try again.';
                if (!$isAjax) {
                    appointmentFlashSet('error', $responseMessage);
                }
            }
        }
    } elseif ($isBulkDelete || $isBulkDeleteAll || $isBulkRestore || $isBulkPurge) {
        $bulkIds = parseBulkIdsFromPost();
        $count = 0;

        if ($isBulkDelete) {
            $count = softDeleteAppointments($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 appointment moved to recently deleted.' : $count . ' appointments moved to recently deleted.')
                : 'No finished appointments were selected for deletion.';
        } elseif ($isBulkDeleteAll) {
            $count = softDeleteAllDeletableAppointments($pdo, $viewDate);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 appointment moved to recently deleted.' : $count . ' appointments moved to recently deleted.')
                : 'No finished appointments are available to delete for this date.';
        } elseif ($isBulkRestore) {
            $count = restoreAppointments($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 appointment restored.' : $count . ' appointments restored.')
                : 'No appointments were selected for restore.';
        } elseif ($isBulkPurge) {
            $count = purgeAppointments($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 appointment permanently deleted.' : $count . ' appointments permanently deleted.')
                : 'No appointments were selected for permanent deletion.';
        }

        if (!$isAjax) {
            appointmentFlashSet($responseOk ? 'success' : 'error', $responseMessage);
        }
    } elseif ($isDelete) {
        if ($id > 0) {
            if (softDeleteAppointment($pdo, $id)) {
                $responseOk = true;
                $responseMessage = 'Appointment moved to recently deleted.';
                if (!$isAjax) {
                    appointmentFlashSet('success', $responseMessage);
                }
            }
        }
    }

    if ($isAjax) {
        appointmentJsonResponse($responseOk, $responseMessage);
    }

    redirectWithAuth('appointment.php', array_filter([
        'date'   => $viewDate,
        'status' => $filters['status'] !== 'all' ? $filters['status'] : null,
        'q'      => trim($filters['q']) !== '' ? trim($filters['q']) : null,
    ]));
}

$viewDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) {
    $viewDate = date('Y-m-d');
}

$filterStatus = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$flash = appointmentFlashGet();

if (!in_array($filterStatus, appointmentStatusFilters(), true)) {
    $filterStatus = 'all';
}

if ($search !== '' && empty($_GET['date'])) {
    $searchDate = findAppointmentDateForSearch($pdo, $search);
    if ($searchDate) {
        $viewDate = $searchDate;
    }
}

$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));

$standaloneSql = appointmentStandaloneSql('a');
$statsStmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt FROM appointments a
     WHERE a.appointment_date = ? AND {$standaloneSql} AND a.deleted_at IS NULL
     GROUP BY status"
);
$statsStmt->execute([$viewDate]);
$statusCounts = [];
foreach ($statsStmt->fetchAll() as $statRow) {
    $statusCounts[(string) $statRow['status']] = (int) $statRow['cnt'];
}

$recentlyDeletedStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM appointments a
     WHERE a.appointment_date = ? AND {$standaloneSql} AND a.deleted_at IS NOT NULL"
);
$recentlyDeletedStmt->execute([$viewDate]);
$recentlyDeletedCount = (int) $recentlyDeletedStmt->fetchColumn();

$appointmentStats = [
    'total'            => array_sum($statusCounts),
    'scheduled'        => (int) ($statusCounts['scheduled'] ?? 0),
    'confirmed'        => (int) ($statusCounts['confirmed'] ?? 0),
    'completed'        => (int) ($statusCounts['completed'] ?? 0),
    'no_show'          => (int) ($statusCounts['no_show'] ?? 0),
    'recently_deleted' => $recentlyDeletedCount,
];

$statCards = [
    ['label' => 'Total Today', 'hint' => 'All visits this date', 'value' => $appointmentStats['total'], 'icon' => 'calendar-days', 'tone' => 'blue', 'filter' => 'all_appointments'],
    ['label' => 'Awaiting', 'hint' => 'Need confirmation', 'value' => $appointmentStats['scheduled'], 'icon' => 'clock', 'tone' => 'amber', 'filter' => 'scheduled'],
    ['label' => 'Confirmed', 'hint' => 'Ready to serve', 'value' => $appointmentStats['confirmed'], 'icon' => 'badge-check', 'tone' => 'violet', 'filter' => 'confirmed'],
    ['label' => 'Completed', 'hint' => 'Served today', 'value' => $appointmentStats['completed'], 'icon' => 'check-circle-2', 'tone' => 'emerald', 'filter' => 'completed'],
    ['label' => 'No-Show', 'hint' => 'Did not arrive', 'value' => $appointmentStats['no_show'], 'icon' => 'user-x', 'tone' => 'rose', 'filter' => 'no_show'],
];

$filterLabels = [
    'all'              => 'Awaiting Queue',
    'scheduled'        => 'Awaiting Confirmation',
    'confirmed'        => 'Confirmed',
    'completed'        => 'Completed',
    'no_show'          => 'No-Show',
    'all_appointments' => 'All Appointments',
    'recently_deleted' => 'Recently Deleted',
];
$currentFilterLabel = $filterLabels[$filterStatus] ?? 'Awaiting Queue';
$showSidePanel = !in_array($filterStatus, ['completed', 'confirmed', 'recently_deleted'], true);
$showBulkActions = in_array($filterStatus, ['all_appointments', 'recently_deleted'], true);
$isRecentlyDeletedView = $filterStatus === 'recently_deleted';

$sql = "SELECT a.*, dr.date_of_birth, dr.date_of_marriage, dr.sex, dr.document_type AS request_document_type
        FROM appointments a
        LEFT JOIN document_requests dr ON dr.tracking_code = a.tracking_code AND dr.deleted_at IS NULL
        WHERE {$standaloneSql}";
$params = [];

if ($filterStatus === 'recently_deleted') {
    $sql .= ' AND a.appointment_date = ? AND a.deleted_at IS NOT NULL';
    $params[] = $viewDate;
} else {
    $sql .= ' AND a.appointment_date = ? AND a.deleted_at IS NULL';
    $params[] = $viewDate;
}

if ($search !== '') {
    if ($filterStatus !== 'all' && $filterStatus !== '' && $filterStatus !== 'all_appointments') {
        $sql .= ' AND a.status = ?';
        $params[] = $filterStatus;
    }
} elseif ($filterStatus === 'all_appointments') {
    // All active statuses for this date.
} elseif ($filterStatus === 'recently_deleted') {
    // Deleted items only for this date.
} elseif ($filterStatus === 'all' || $filterStatus === '') {
    $sql .= " AND a.status = 'scheduled'";
} else {
    $sql .= ' AND a.status = ?';
    $params[] = $filterStatus;
}

if ($search !== '') {
    $sql .= ' AND (a.first_name LIKE ? OR a.middle_name LIKE ? OR a.last_name LIKE ? OR a.appointment_code LIKE ? OR a.phone LIKE ? OR a.email LIKE ?)';
    $term = "%{$search}%";
    array_push($params, $term, $term, $term, $term, $term, $term);
}

$sql .= $filterStatus === 'recently_deleted' ? ' ORDER BY a.deleted_at DESC' : ' ORDER BY a.appointment_time ASC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();
$resultCount = count($appointments);

$pageTitle = 'Appointments';
$pageSubtitle = 'Review, confirm, and complete citizen visits and special service bookings.';
$pageHeaderMeta = '<p class="admin-header__meta">Viewing <strong>' . htmlspecialchars(formatDateDisplay($viewDate)) . '</strong></p>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointments - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorStylesheetTag('inter/inter.css') ?>
    <?= adminLayoutHeadStyles('appointment') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col min-h-screen">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 w-full admin-page-wrap manage-requests-page appointments-page">

            <section class="manage-section" aria-label="Appointment overview">
                <div class="manage-section__head">
                    <h2 class="manage-section__title">Overview · <?= htmlspecialchars(formatDateDisplay($viewDate)) ?></h2>
                    <p class="manage-section__hint">Click a card to filter the list below</p>
                </div>
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
                <?php foreach ($statCards as $card):
                    $cardFilter = $card['filter'];
                    $cardHref = buildAuthUrl('appointment.php', array_filter([
                        'date'   => $viewDate,
                        'status' => $cardFilter,
                        'q'      => $search !== '' ? $search : null,
                    ]));
                    $cardActive = $filterStatus === $cardFilter
                        || ($cardFilter === 'scheduled' && $filterStatus === 'all');
                ?>
                <a href="<?= htmlspecialchars($cardHref) ?>"
                   class="manage-stat-card manage-stat-card--<?= htmlspecialchars($card['tone']) ?><?= $cardActive ? ' is-active' : '' ?>">
                    <div class="manage-stat-card__icon">
                        <i data-lucide="<?= htmlspecialchars($card['icon']) ?>" class="w-4 h-4"></i>
                    </div>
                    <div class="min-w-0">
                        <p class="manage-stat-card__value"><?= number_format($card['value']) ?></p>
                        <p class="manage-stat-card__label"><?= htmlspecialchars($card['label']) ?></p>
                        <p class="manage-stat-card__hint"><?= htmlspecialchars($card['hint']) ?></p>
                    </div>
                </a>
                <?php endforeach; ?>
                </div>
            </section>

            <section class="manage-section manage-section--controls" aria-label="Search and filters">
                <div class="manage-controls">
                    <div class="manage-controls__context">
                        <span class="manage-controls__label">Current view</span>
                        <span class="manage-controls__view"><?= htmlspecialchars($currentFilterLabel) ?></span>
                        <span class="manage-controls__count"><?= number_format($resultCount) ?> shown</span>
                        <?php if ($filterStatus !== 'all' || $search !== ''): ?>
                        <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate])) ?>" class="manage-controls__reset">Reset</a>
                        <?php endif; ?>
                    </div>
                    <div class="appointments-controls__tools">
                        <div class="appointments-date-nav" aria-label="Change date">
                            <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', array_filter(['date' => $prevDate, 'status' => $filterStatus !== 'all' ? $filterStatus : null, 'q' => $search ?: null]))) ?>" class="appointments-date-nav__btn" aria-label="Previous day">
                                <i data-lucide="chevron-left" class="w-4 h-4"></i>
                            </a>
                            <label class="appointments-date-nav__date">
                                <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-600"></i>
                                <input type="date" id="appointmentDatePicker" value="<?= htmlspecialchars($viewDate) ?>" class="appointments-date-nav__input">
                            </label>
                            <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', array_filter(['date' => $nextDate, 'status' => $filterStatus !== 'all' ? $filterStatus : null, 'q' => $search ?: null]))) ?>" class="appointments-date-nav__btn" aria-label="Next day">
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                        <form method="GET" action="<?= htmlspecialchars(buildAuthUrl('appointment.php')) ?>" class="manage-controls__search">
                            <?= authFormField() ?>
                            <input type="hidden" name="date" value="<?= htmlspecialchars($viewDate) ?>">
                            <?php if ($filterStatus !== 'all' && $filterStatus !== 'all_appointments'): ?>
                            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                            <?php elseif ($filterStatus === 'all_appointments'): ?>
                            <input type="hidden" name="status" value="all_appointments">
                            <?php elseif ($filterStatus === 'recently_deleted'): ?>
                            <input type="hidden" name="status" value="recently_deleted">
                            <?php endif; ?>
                            <div class="manage-requests-toolbar__search">
                                <i data-lucide="search" class="w-3.5 h-3.5 text-gray-400"></i>
                                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search code, name, phone…" class="manage-requests-toolbar__input">
                            </div>
                            <button type="submit" class="manage-requests-toolbar__btn manage-requests-toolbar__btn--primary" data-loading-text="Searching…">
                                Search
                            </button>
                        </form>
                    </div>
                </div>
            </section>

            <div class="manage-requests-body" id="appointmentsBody">
                <div class="manage-requests-main">
            <?php if (empty($appointments)): ?>
            <div class="manage-requests-empty">
                <div class="manage-requests-empty__icon">
                    <i data-lucide="calendar-off" class="w-10 h-10"></i>
                </div>
                <h2>No appointments in this view</h2>
                <p><?= $search !== '' ? 'No matches for your search. Try different keywords or reset filters.' : 'There are no appointments for the selected filter on this date.' ?></p>
                <?php if ($filterStatus !== 'all' || $search !== ''): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate])) ?>" class="manage-empty-reset">View awaiting queue</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="manage-requests-table-card">
                <div class="manage-table-head">
                    <div>
                        <h2 class="manage-table-head__title"><?= htmlspecialchars($currentFilterLabel) ?></h2>
                        <p class="manage-table-head__meta">
                            <?= number_format($resultCount) ?> appointment<?= $resultCount === 1 ? '' : 's' ?>
                            · <?= htmlspecialchars(formatDateDisplay($viewDate)) ?>
                            <?= $search !== '' ? ' · matching “' . htmlspecialchars($search) . '”' : '' ?>
                            <?php if ($showBulkActions && !$isRecentlyDeletedView): ?>
                            · <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate, 'status' => 'recently_deleted'])) ?>" class="manage-bulk-meta-link">Recently deleted<?= $appointmentStats['recently_deleted'] > 0 ? ' (' . number_format($appointmentStats['recently_deleted']) . ')' : '' ?></a>
                            <?php elseif ($isRecentlyDeletedView): ?>
                            · <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate, 'status' => 'all_appointments'])) ?>" class="manage-bulk-meta-link">Back to all appointments</a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <p class="manage-table-head__tip"><?= $isRecentlyDeletedView ? 'Select items to restore or permanently delete them' : ($showSidePanel ? 'Click Verify to review details, then confirm or reject the appointment in the popup' : 'Click Complete to open the visit popup and mark it served') ?></p>
                </div>
                <?php if ($showBulkActions): ?>
                <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('appointment.php')) ?>" id="manageBulkForm" class="manage-bulk-form" data-manage-bulk-form>
                    <?= authFormField() ?>
                    <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($viewDate) ?>">
                    <input type="hidden" name="redirect_q" value="<?= htmlspecialchars($search) ?>">
                    <div class="manage-bulk-toolbar hidden" data-bulk-toolbar aria-hidden="true">
                        <label class="manage-bulk-toolbar__all">
                            <input type="checkbox" class="manage-bulk-check manage-bulk-check--all" aria-label="Select all">
                            <span>All</span>
                        </label>
                        <?php if ($isRecentlyDeletedView): ?>
                        <button type="submit" name="bulk_restore" value="1" class="manage-bulk-toolbar__btn manage-bulk-toolbar__btn--primary" data-bulk-require-selection data-loading-text="Restoring…">Restore</button>
                        <button type="submit" name="bulk_purge" value="1" class="manage-bulk-toolbar__btn manage-bulk-toolbar__btn--danger" data-bulk-require-selection data-bulk-confirm="Permanently delete the selected appointments? This cannot be undone." data-loading-text="Deleting…">Delete</button>
                        <?php else: ?>
                        <button type="submit" name="bulk_delete" value="1" class="manage-bulk-toolbar__btn manage-bulk-toolbar__btn--danger" data-bulk-delete-btn data-bulk-require-selection data-bulk-confirm="Move the selected finished appointments to recently deleted?" data-loading-text="Deleting…">Delete</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
                <div class="overflow-x-auto">
                <table class="manage-requests-table w-full text-left text-sm min-w-[800px]">
                    <thead>
                        <tr>
                            <?php if ($showBulkActions): ?><th class="manage-bulk-col"><span class="sr-only">Select</span></th><?php endif; ?>
                            <th>Code</th>
                            <th>Citizen</th>
                            <th>Service</th>
                            <th>Time</th>
                            <?php if ($isRecentlyDeletedView): ?><th>Deleted</th><?php endif; ?>
                            <th>Status</th>
                            <th class="manage-cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $ap): ?>
                        <?php
                        $viewData = appointmentViewData($ap);
                        $canBulkSelect = $isRecentlyDeletedView || appointmentIsDeletable($ap);
                        ?>
                        <tr class="manage-requests-row"
                            data-appointment-row="<?= (int) $ap['id'] ?>"
                            data-appointment="<?= htmlspecialchars(json_encode($viewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($showBulkActions): ?>
                            <td class="manage-bulk-col" onclick="event.stopPropagation()">
                                <?php if ($canBulkSelect): ?>
                                <input type="checkbox" form="manageBulkForm" name="bulk_ids[]" value="<?= (int) $ap['id'] ?>" class="manage-bulk-check manage-bulk-row-check" aria-label="Select appointment <?= htmlspecialchars($ap['appointment_code']) ?>">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="manage-id"><?= htmlspecialchars($ap['appointment_code']) ?></span></td>
                            <td>
                                <p class="manage-citizen-name"><?= htmlspecialchars(personNameFromRow($ap)) ?></p>
                                <p class="manage-citizen-meta"><?= htmlspecialchars($ap['phone'] ?: 'No phone on file') ?></p>
                            </td>
                            <td><span class="manage-doc-type"><?= htmlspecialchars(appointmentServiceLabel($ap['service_type'])) ?></span></td>
                            <td><span class="manage-date"><?= date('g:i A', strtotime($ap['appointment_time'])) ?></span></td>
                            <?php if ($isRecentlyDeletedView): ?>
                            <td><span class="manage-date"><?= !empty($ap['deleted_at']) ? htmlspecialchars(formatReportDateTime($ap['deleted_at'])) : '—' ?></span></td>
                            <?php endif; ?>
                            <td><?= appointmentStatusBadge($ap['status']) ?></td>
                            <td class="manage-cell-actions">
                                <div class="manage-row-actions" onclick="event.stopPropagation()">
                                    <?php if (!$isRecentlyDeletedView): ?>
                                    <?php
                                    $rowStatus = (string) ($ap['status'] ?? 'scheduled');
                                    $viewLabel = match ($rowStatus) {
                                        'scheduled' => 'Verify',
                                        'confirmed' => 'Complete',
                                        default     => 'View',
                                    };
                                    $isCompleteAction = $rowStatus === 'confirmed';
                                    $showVerifyEye = $rowStatus === 'scheduled';
                                    ?>
                                    <button type="button"
                                            class="view-appointment-btn manage-row-action manage-row-action--labeled<?= $isCompleteAction ? ' manage-row-action--complete' : '' ?>"
                                            title="<?= htmlspecialchars($viewLabel . ' appointment') ?>"
                                            aria-label="<?= htmlspecialchars($viewLabel . ' appointment') ?>"
                                            data-appointment="<?= htmlspecialchars(json_encode($viewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if ($showVerifyEye): ?>
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                        <?php endif; ?>
                                        <span class="manage-row-action__label"><?= htmlspecialchars($isCompleteAction ? $viewLabel : strtoupper($viewLabel)) ?></span>
                                    </button>
                                    <?php else: ?>
                                    <span class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Deleted</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endif; ?>
                </div>

                <?php if ($showSidePanel): ?>
                <aside id="appointmentDetailPanel" class="manage-request-detail">
                    <div id="appointmentDetailEmpty" class="manage-request-detail__empty">
                        <i data-lucide="mouse-pointer-click" class="w-8 h-8 text-gray-300"></i>
                        <p class="manage-detail-empty__title">Select an appointment</p>
                        <p class="manage-detail-empty__hint">Click Verify on a visit to review and confirm or reject it</p>
                    </div>

                    <div id="appointmentDetailContent" class="manage-request-detail__content hidden">
                        <div class="manage-request-detail__header">
                            <div class="min-w-0 flex-1">
                                <h2 id="appointmentViewTitle" class="manage-detail-title truncate">—</h2>
                                <p id="appointmentViewCode" class="manage-detail-code"></p>
                                <p id="appt-view-created" class="manage-detail-meta"></p>
                            </div>
                            <button type="button" id="appointmentDetailClose" class="manage-request-detail__close" aria-label="Close details">
                                <i data-lucide="x" class="w-4 h-4"></i>
                            </button>
                        </div>

                        <div class="manage-request-detail__body">
                            <section class="manage-detail-block">
                                <h3>Citizen</h3>
                                <dl class="manage-detail-rows">
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Name</dt><dd id="appt-view-name"></dd></div>
                                    <div class="manage-detail-row"><dt>DOB</dt><dd id="appt-view-dob"></dd></div>
                                    <div class="manage-detail-row hidden" id="appt-view-dom-wrap"><dt>DOM</dt><dd id="appt-view-dom"></dd></div>
                                    <div class="manage-detail-row"><dt>Sex</dt><dd id="appt-view-sex"></dd></div>
                                    <div class="manage-detail-row"><dt>Phone</dt><dd id="appt-view-phone"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Email</dt><dd id="appt-view-email"></dd></div>
                                    <div class="manage-detail-row"><dt>Gmail alerts</dt><dd id="appt-view-notify"></dd></div>
                                </dl>
                            </section>

                            <section class="manage-detail-block">
                                <h3>Visit</h3>
                                <dl class="manage-detail-rows">
                                    <div class="manage-detail-row"><dt>Service</dt><dd id="appt-view-service"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Schedule</dt><dd id="appt-view-schedule"></dd></div>
                                    <div class="manage-detail-row"><dt>Status</dt><dd id="appt-view-status"></dd></div>
                                    <div class="manage-detail-row"><dt>Type</dt><dd id="appt-view-source"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full" id="appt-view-tracking-wrap"><dt>Tracking</dt><dd id="appt-view-tracking"></dd></div>
                                </dl>
                            </section>

                            <section class="manage-detail-block">
                                <h3>IDs</h3>
                                <div id="appt-view-id-files" class="manage-detail-ids"></div>
                            </section>

                            <section id="appt-view-notes-wrap" class="manage-detail-block hidden">
                                <h3>Notes</h3>
                                <p id="appt-view-notes" class="manage-detail-notes"></p>
                            </section>
                        </div>

                        <div id="appointmentDetailActions" class="manage-request-detail__actions hidden" aria-label="Appointment actions"></div>
                    </div>
                </aside>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="appointmentReviewModal" class="manage-request-modal hidden" aria-hidden="true">
        <div class="manage-request-modal__backdrop" data-close-appointment-modal></div>
        <div class="manage-request-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modalAppointmentViewTitle">
            <div class="manage-request-modal__header">
                <div class="min-w-0 flex-1">
                    <h2 id="modalAppointmentViewTitle" class="manage-detail-title truncate">—</h2>
                    <p id="modalAppointmentViewCode" class="manage-detail-code"></p>
                    <p id="modal-appt-view-created" class="manage-detail-meta"></p>
                </div>
                <button type="button" class="manage-request-modal__close" data-close-appointment-modal aria-label="Close review popup">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <div class="manage-request-modal__body">
                <section class="manage-detail-block">
                    <h3>Citizen</h3>
                    <dl class="manage-detail-rows">
                        <div class="manage-detail-row manage-detail-row--full"><dt>Name</dt><dd id="modal-appt-view-name"></dd></div>
                        <div class="manage-detail-row"><dt>DOB</dt><dd id="modal-appt-view-dob"></dd></div>
                        <div class="manage-detail-row hidden" id="modal-appt-view-dom-wrap"><dt>DOM</dt><dd id="modal-appt-view-dom"></dd></div>
                        <div class="manage-detail-row"><dt>Sex</dt><dd id="modal-appt-view-sex"></dd></div>
                        <div class="manage-detail-row"><dt>Phone</dt><dd id="modal-appt-view-phone"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Email</dt><dd id="modal-appt-view-email"></dd></div>
                        <div class="manage-detail-row"><dt>Gmail alerts</dt><dd id="modal-appt-view-notify"></dd></div>
                    </dl>
                </section>

                <section class="manage-detail-block">
                    <h3>Visit</h3>
                    <dl class="manage-detail-rows">
                        <div class="manage-detail-row"><dt>Service</dt><dd id="modal-appt-view-service"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Schedule</dt><dd id="modal-appt-view-schedule"></dd></div>
                        <div class="manage-detail-row"><dt>Status</dt><dd id="modal-appt-view-status"></dd></div>
                        <div class="manage-detail-row"><dt>Type</dt><dd id="modal-appt-view-source"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full" id="modal-appt-view-tracking-wrap"><dt>Tracking</dt><dd id="modal-appt-view-tracking"></dd></div>
                    </dl>
                </section>

                <section class="manage-detail-block">
                    <h3>IDs</h3>
                    <div id="modal-appt-view-id-files" class="manage-detail-ids"></div>
                </section>

                <section id="modal-appt-view-notes-wrap" class="manage-detail-block hidden">
                    <h3>Notes</h3>
                    <p id="modal-appt-view-notes" class="manage-detail-notes"></p>
                </section>
            </div>

            <div id="modalAppointmentDetailActions" class="manage-request-modal__actions hidden" aria-label="Appointment actions"></div>
        </div>
    </div>

    <?= pageConfigJson([
        'formAction'     => buildAuthUrl('appointment.php'),
        'redirectStatus' => $filterStatus,
        'redirectDate'   => $viewDate,
        'redirectQ'      => $search,
        'useSidePanel'   => $showSidePanel,
        'bulkActions'    => $showBulkActions,
    ]) ?>
    <div id="appointmentActionAuthFields" class="hidden" aria-hidden="true"><?= authFormField() ?></div>
    <?= actionResultScript($flash) ?>
    <?= scriptTag('admin/id-preview.js') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/manage-bulk.js') ?>
    <?= scriptTag('admin/appointment.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
