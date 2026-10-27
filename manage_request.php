<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/staff_id_lightbox.php';
require_once __DIR__ . '/includes/printing.php';
require_once __DIR__ . '/includes/staff_resource_locks.php';
requireStaffLogin();
requirePageAccess('manage_request.php');
releaseSessionLock();

$activePage = 'manage_request.php';
$pdo = getDB();
migrateLegacyProcessingStatus($pdo);
ensureCitizenNotifyColumns($pdo);
ensurePrintTables($pdo);

function manageRequestStatusFilters(): array
{
    return ['all', 'pending', 'ready', 'rejected', 'completed', 'no_show', 'all_requests', 'recently_deleted'];
}

function manageRequestsRedirectFilters(): array
{
    $status = $_POST['redirect_status'] ?? $_GET['status'] ?? 'all';
    if (!in_array($status, manageRequestStatusFilters(), true)) {
        $status = 'all';
    }

    $date = trim((string) ($_POST['redirect_date'] ?? $_GET['date'] ?? ''));

    return [
        'status' => $status,
        'date'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null,
        'q'      => $_POST['redirect_q'] ?? $_GET['q'] ?? '',
    ];
}

function isManageRequestAjax(): bool
{
    return ($_POST['ajax'] ?? '') === '1';
}

function manageRequestJsonResponse(bool $ok, string $message, ?string $type = null): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'      => $ok,
        'type'    => $type ?? ($ok ? 'success' : 'error'),
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = manageRequestsRedirectFilters();
    $id = (int) ($_POST['request_id'] ?? 0);
    $isAjax = isManageRequestAjax();
    $responseOk = false;
    $responseMessage = 'Could not complete this action. Please try again.';

    $isDelete = isset($_POST['delete_request']);
    $isBulkDelete = isset($_POST['bulk_delete']);
    $isBulkDeleteAll = isset($_POST['bulk_delete_all']);
    $isBulkRestore = isset($_POST['bulk_restore']);
    $isBulkPurge = isset($_POST['bulk_purge']);
    $isVisitNoShow = isset($_POST['mark_visit_no_show']);
    $isUpdate = !$isVisitNoShow && (isset($_POST['update_status']) || (!$isDelete && !$isBulkDelete && !$isBulkDeleteAll && !$isBulkRestore && !$isBulkPurge && isset($_POST['status'])));

    if ($isVisitNoShow) {
        if ($id > 0 && documentRequestLockEligible($pdo, $id)) {
            try {
                assertStaffResourceLockForWrite($pdo, STAFF_LOCK_TYPE_REQUEST, $id, staffId(), staffName());
            } catch (InvalidArgumentException $e) {
                $responseMessage = $e->getMessage();
                if ($isAjax) {
                    manageRequestJsonResponse(false, $responseMessage);
                }
                manageRequestsFlashSet('error', $responseMessage);
                redirectWithAuth('manage_request.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'));
            }
        }

        if ($id > 0 && markDocumentRequestVisitNoShow($pdo, $id)) {
            clearStaffResourceLock($pdo, STAFF_LOCK_TYPE_REQUEST, $id);
            $responseOk = true;
            $responseMessage = 'Visit marked as no-show.';
            $responseMessage = staffCitizenNotifyAppendToStaffMessage($responseMessage);
            $responseType = staffCitizenNotifyFlashType(true);
            if (!$isAjax) {
                manageRequestsFlashSet($responseType, $responseMessage);
            }
        } else {
            $responseMessage = 'Could not mark no-show. The visit may not be 20 minutes late yet, or it was already updated.';
            if (!$isAjax) {
                manageRequestsFlashSet('error', $responseMessage);
            }
        }
    } elseif ($isUpdate) {
        if ($id > 0 && documentRequestLockEligible($pdo, $id)) {
            try {
                assertStaffResourceLockForWrite($pdo, STAFF_LOCK_TYPE_REQUEST, $id, staffId(), staffName());
            } catch (InvalidArgumentException $e) {
                $responseMessage = $e->getMessage();
                if ($isAjax) {
                    manageRequestJsonResponse(false, $responseMessage);
                }
                manageRequestsFlashSet('error', $responseMessage);
                redirectWithAuth('manage_request.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'));
            }
        }

        $status = (string) ($_POST['status'] ?? '');
        $rejectionReason = null;
        if ($status === 'rejected') {
            $rejectionReason = normalizeStaffRejectionReason($_POST['rejection_reason'] ?? null);
            if ($rejectionReason === null) {
                $responseMessage = 'Please enter a reason for rejecting this request.';
                if (!$isAjax) {
                    manageRequestsFlashSet('error', $responseMessage);
                }
                if ($isAjax) {
                    manageRequestJsonResponse(false, $responseMessage);
                }
                redirectWithAuth('manage_request.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'));
            }
        }
        if (updateDocumentRequestStatus($pdo, $id, $status, false, $rejectionReason)) {
            clearStaffResourceLock($pdo, STAFF_LOCK_TYPE_REQUEST, $id);
            $responseOk = true;
            if ($status === 'verified') {
                $responseMessage = 'Request accepted — moved to Ready for Pickup. Print the certificate when ready.';
            } elseif ($status === 'completed') {
                $responseMessage = 'Request marked completed — document claimed by citizen.';
            } elseif ($status === 'rejected') {
                $responseMessage = 'Request rejected.';
            } else {
                $responseMessage = 'Request status saved as ' . requestStatusLabel($status) . '.';
            }
            $responseMessage = staffCitizenNotifyAppendToStaffMessage($responseMessage);
            $responseType = staffCitizenNotifyFlashType(true);
            if (!$isAjax) {
                manageRequestsFlashSet($responseType, $responseMessage);
            }
        } else {
            $responseMessage = $status === 'rejected'
                ? 'Could not reject this request. It may have already been updated, or the reason was missing.'
                : 'Could not update request status. Another staff member may have already updated this request.';
            if (!$isAjax) {
                manageRequestsFlashSet('error', $responseMessage);
            }
        }
    } elseif ($isBulkDelete || $isBulkDeleteAll || $isBulkRestore || $isBulkPurge) {
        $bulkIds = parseBulkIdsFromPost();
        $count = 0;

        if ($isBulkDelete) {
            $count = softDeleteDocumentRequests($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 request moved to recently deleted.' : $count . ' requests moved to recently deleted.')
                : 'No completed requests were selected for deletion.';
        } elseif ($isBulkDeleteAll) {
            $count = softDeleteAllDeletableDocumentRequests($pdo);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 completed request moved to recently deleted.' : $count . ' completed requests moved to recently deleted.')
                : 'No completed requests are available to delete.';
        } elseif ($isBulkRestore) {
            $count = restoreDocumentRequests($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 request restored.' : $count . ' requests restored.')
                : 'No requests were selected for restore.';
        } elseif ($isBulkPurge) {
            $count = purgeDocumentRequests($pdo, $bulkIds);
            $responseOk = $count > 0;
            $responseMessage = $count > 0
                ? ($count === 1 ? '1 request permanently deleted.' : $count . ' requests permanently deleted.')
                : 'No requests were selected for permanent deletion.';
        }

        if (!$isAjax) {
            manageRequestsFlashSet($responseOk ? 'success' : 'error', $responseMessage);
        }
    } elseif ($isDelete) {
        if (deleteCompletedDocumentRequest($pdo, $id)) {
            $responseOk = true;
            $responseMessage = 'Completed request deleted successfully.';
            if (!$isAjax) {
                manageRequestsFlashSet('success', $responseMessage);
            }
        } else {
            $responseMessage = 'Only completed requests can be deleted.';
            if (!$isAjax) {
                manageRequestsFlashSet('error', $responseMessage);
            }
        }
    }

    if ($isAjax) {
        $ajaxType = $responseOk && ($isUpdate || $isVisitNoShow) ? staffCitizenNotifyFlashType(true) : ($responseOk ? 'success' : 'error');
        manageRequestJsonResponse($responseOk, $responseMessage, $ajaxType);
    }

    redirectWithAuth('manage_request.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'));
}

$filterStatus = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$flash        = manageRequestsFlashGet();

if (!in_array($filterStatus, manageRequestStatusFilters(), true)) {
    $filterStatus = 'all';
}

ensureSoftDeleteColumns($pdo);
$requestedViewDate = isset($_GET['date']) ? trim((string) $_GET['date']) : null;
if ($requestedViewDate === '') {
    $requestedViewDate = null;
}
$filters = manageRequestsListFilters([
    'status' => $filterStatus,
    'q'      => $search,
    'date'   => $requestedViewDate,
], $pdo);
$filterStatus = $filters['status'];
$search = $filters['q'];
$viewDate = $filters['date'];
$usesVisitDate = manageRequestListUsesVisitDate($filters);
$awaitingAllDates = !$usesVisitDate;
$showDateNav = $usesVisitDate;
$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));
$requests = fetchManageRequestsList($pdo, $filters);
$requestLockMap = fetchStaffResourceLocksMap(
    $pdo,
    STAFF_LOCK_TYPE_REQUEST,
    array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $requests)
);
$requestStats = fetchManageRequestStats($pdo);
$visitDateStats = $showDateNav ? fetchManageRequestVisitDateStats($pdo, $viewDate) : null;
$todayDate = alcrosTodayDate();
$visitDayLabel = $viewDate === $todayDate ? 'Total Today' : 'Total This Day';

$pageTitle = 'Manage Requests';
$pageSubtitle = 'Review and process certificate requests from citizens.';

$statCards = [
    [
        'label'  => $showDateNav ? $visitDayLabel : 'Total Requests',
        'hint'   => $showDateNav ? 'Pickup visits on selected date' : 'All time',
        'value'  => $showDateNav ? (int) ($visitDateStats['visit_total'] ?? 0) : $requestStats['total'],
        'icon'   => $showDateNav ? 'calendar-days' : 'files',
        'tone'   => 'blue',
        'filter' => 'all_requests',
    ],
    ['label' => 'Pending', 'hint' => 'Awaiting review', 'value' => $requestStats['pending'], 'icon' => 'clock', 'tone' => 'amber', 'filter' => 'pending'],
    [
        'label' => 'Ready',
        'hint'  => 'Ready for pickup',
        'value' => $showDateNav ? (int) ($visitDateStats['ready'] ?? 0) : $requestStats['ready'],
        'icon'  => 'package',
        'tone'  => 'violet',
        'filter'=> 'ready',
    ],
    [
        'label' => 'Completed',
        'hint'  => $showDateNav ? 'Released on this visit date' : 'Released to citizen',
        'value' => $showDateNav ? (int) ($visitDateStats['completed'] ?? 0) : $requestStats['completed'],
        'icon'  => 'check-circle-2',
        'tone'  => 'emerald',
        'filter'=> 'completed',
    ],
    [
        'label' => 'No-Show',
        'hint'  => 'Missed pickup visit',
        'value' => $showDateNav ? (int) ($visitDateStats['no_show'] ?? 0) : $requestStats['no_show'],
        'icon'  => 'user-x',
        'tone'  => 'rose',
        'filter' => 'no_show',
    ],
    ['label' => 'Rejected', 'hint' => 'Declined requests', 'value' => $requestStats['rejected'], 'icon' => 'x-circle', 'tone' => 'rose', 'filter' => 'rejected'],
];

$filterLabels = [
    'all'              => 'Pending Queue',
    'pending'          => 'Pending',
    'rejected'         => 'Rejected',
    'ready'            => 'Ready for Pickup',
    'completed'        => 'Completed',
    'no_show'          => 'No-Show',
    'all_requests'     => 'All Requests',
    'recently_deleted' => 'Recently Deleted',
];
$currentFilterLabel = $filterLabels[$filterStatus] ?? 'Pending Queue';
$resultCount = count($requests);
$showSidePanel = !in_array($filterStatus, ['ready', 'no_show', 'recently_deleted'], true);
$showBulkActions = in_array($filterStatus, ['all_requests', 'recently_deleted'], true);
$isRecentlyDeletedView = $filterStatus === 'recently_deleted';
$showVisitLateNoShowColumn = $filterStatus === 'ready';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('manage-requests') ?>
    <?= staffIdLightboxHeadStyles() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen" data-staff-lock-type="<?= htmlspecialchars(STAFF_LOCK_TYPE_REQUEST) ?>">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="admin-main flex flex-col min-h-screen">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 w-full admin-page-wrap manage-requests-page">

            <section class="manage-section" aria-label="Request overview">
                <div class="manage-section__head">
                    <h2 class="manage-section__title"><?= $showDateNav
                        ? 'Overview · ' . htmlspecialchars(formatDateEmailDisplay($viewDate))
                        : 'Overview' ?></h2>
                    <p class="manage-section__hint">Click a card to filter the list below</p>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
                <?php foreach ($statCards as $card):
                    $cardFilter = $card['filter'];
                    $cardQuery = [
                        'status' => $cardFilter,
                        'q'      => $search !== '' ? $search : null,
                    ];
                    $cardUsesVisitDate = !manageRequestManageAwaitingAllDates([
                        'status' => $cardFilter,
                        'q'      => $search,
                    ]) || ($showDateNav && in_array($cardFilter, ['all_requests', 'ready', 'completed', 'no_show'], true));
                    if ($cardUsesVisitDate) {
                        $cardQuery['date'] = $viewDate;
                    }
                    $cardHref = buildAuthUrl('manage_request.php', array_filter($cardQuery));
                    $cardActive = $filterStatus === $cardFilter
                        || ($cardFilter === 'pending' && $filterStatus === 'all');
                    if ($cardActive) {
                        $cardHref .= '#manageRequestsBody';
                    }
                ?>
                <a href="<?= htmlspecialchars($cardHref) ?>"
                   class="manage-stat-card manage-stat-card--<?= htmlspecialchars($card['tone']) ?><?= $cardActive ? ' is-active' : '' ?>"
                   data-stat-key="<?= htmlspecialchars($cardFilter) ?>">
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
                        <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="manage-controls__reset">Reset</a>
                        <?php endif; ?>
                    </div>
                    <div class="appointments-controls__tools">
                        <?php if ($showDateNav): ?>
                        <div class="appointments-date-nav" aria-label="Change visit date">
                            <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', array_filter(['date' => $prevDate, 'status' => $filterStatus !== 'all' ? $filterStatus : null, 'q' => $search ?: null]))) ?>" class="appointments-date-nav__btn" aria-label="Previous day">
                                <i data-lucide="chevron-left" class="w-4 h-4"></i>
                            </a>
                            <label class="appointments-date-nav__date alcros-date-picker">
                                <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-600 shrink-0" aria-hidden="true"></i>
                                <span id="manageRequestDateNavLabel" class="appointments-date-nav__label" data-date-picker-label><?= htmlspecialchars(formatDateEmailDisplay($viewDate)) ?></span>
                                <input type="date" id="manageRequestDatePicker" value="<?= htmlspecialchars($viewDate) ?>" class="appointments-date-nav__input" aria-label="Choose visit date">
                            </label>
                            <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', array_filter(['date' => $nextDate, 'status' => $filterStatus !== 'all' ? $filterStatus : null, 'q' => $search ?: null]))) ?>" class="appointments-date-nav__btn" aria-label="Next day">
                                <i data-lucide="chevron-right" class="w-4 h-4"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    <form method="GET" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="manage-controls__search">
                        <?= authFormField() ?>
                        <?php if ($search === '' && $showDateNav): ?>
                        <input type="hidden" name="date" value="<?= htmlspecialchars($viewDate) ?>">
                        <?php endif; ?>
                        <?php if ($filterStatus !== 'all' && $filterStatus !== 'all_requests'): ?>
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <?php elseif ($filterStatus === 'all_requests'): ?>
                        <input type="hidden" name="status" value="all_requests">
                        <?php elseif ($filterStatus === 'recently_deleted'): ?>
                        <input type="hidden" name="status" value="recently_deleted">
                        <?php endif; ?>
                        <div class="manage-requests-toolbar__search">
                            <i data-lucide="search" class="w-3.5 h-3.5 text-gray-400"></i>
                            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="First, middle, last, full name, DOB, DOM…" class="manage-requests-toolbar__input" autocomplete="off">
                        </div>
                        <button type="submit" class="manage-requests-toolbar__btn manage-requests-toolbar__btn--primary" data-loading-text="Searching…">
                            Search
                        </button>
                    </form>
                    </div>
                </div>
            </section>

            <div class="manage-requests-body" id="manageRequestsBody">
                <div class="manage-requests-main">
            <?php if (empty($requests)): ?>
            <div class="manage-requests-empty">
                <div class="manage-requests-empty__icon">
                    <i data-lucide="file-text" class="w-10 h-10"></i>
                </div>
                <h2>No requests in this view</h2>
                <p><?= $search !== '' ? 'No matches for your search. Try different keywords or reset filters.' : 'There are no requests for the selected filter.' ?></p>
                <?php if ($filterStatus !== 'all' || $search !== ''): ?>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="manage-empty-reset">View pending queue</a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="manage-requests-table-card">
                <div class="manage-table-head">
                    <div>
                        <h2 class="manage-table-head__title"><?= htmlspecialchars($currentFilterLabel) ?></h2>
                        <p class="manage-table-head__meta">
                            <?= number_format($resultCount) ?> request<?= $resultCount === 1 ? '' : 's' ?>
                            <?= $search !== '' ? ' · matching “' . htmlspecialchars($search) . '”' : '' ?>
                            <?php if ($showBulkActions && !$isRecentlyDeletedView): ?>
                            · <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'recently_deleted'])) ?>" class="manage-bulk-meta-link">Recently deleted<?= $requestStats['recently_deleted'] > 0 ? ' (' . number_format($requestStats['recently_deleted']) . ')' : '' ?></a>
                            <?php elseif ($isRecentlyDeletedView): ?>
                            · <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'all_requests'])) ?>" class="manage-bulk-meta-link">Back to all requests</a>
                            <?php endif; ?>
                        </p>
                    </div>
                    <p class="manage-table-head__tip"><?= $isRecentlyDeletedView ? 'Select items to restore or permanently delete them' : ($showSidePanel ? 'Click Verify to review details, then accept the request in the popup' : 'Click Complete to open the request popup and mark it claimed') ?></p>
                </div>
                <?php if ($showBulkActions): ?>
                <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" id="manageBulkForm" class="manage-bulk-form" data-manage-bulk-form>
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
                        <button type="submit" name="bulk_purge" value="1" class="manage-bulk-toolbar__btn manage-bulk-toolbar__btn--danger" data-bulk-require-selection data-bulk-confirm="Permanently delete the selected requests? This cannot be undone." data-loading-text="Deleting…">Delete</button>
                        <?php else: ?>
                        <button type="submit" name="bulk_delete" value="1" class="manage-bulk-toolbar__btn manage-bulk-toolbar__btn--danger" data-bulk-delete-btn data-bulk-require-selection data-bulk-confirm="Move the selected completed requests to recently deleted?" data-loading-text="Deleting…">Delete</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
                <div class="overflow-x-auto">
                <table class="manage-requests-table w-full text-left text-sm min-w-[1120px]">
                    <thead>
                        <tr>
                            <?php if ($showBulkActions): ?><th class="manage-bulk-col"><span class="sr-only">Select</span></th><?php endif; ?>
                            <th class="manage-col-request-id">Request ID</th>
                            <th class="manage-col-requester">Requester</th>
                            <th class="manage-col-record-name">Name on record</th>
                            <th class="manage-col-certificate">Certificate</th>
                            <th class="manage-col-visit">Visit</th>
                            <th class="manage-col-submitted">Submitted</th>
                            <?php if ($isRecentlyDeletedView): ?><th class="manage-col-submitted">Deleted</th><?php endif; ?>
                            <th class="manage-col-status">Status</th>
                            <?php if ($showVisitLateNoShowColumn): ?><th class="manage-col-visit-late">Late / No-show</th><?php endif; ?>
                            <th class="manage-cell-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                        <?php
                        $viewData = documentRequestViewData($req);
                        $reqLock = $requestLockMap[(int) $req['id']] ?? null;
                        $viewData['lock'] = $reqLock ? formatStaffResourceLock($reqLock, staffId()) : null;
                        $canBulkSelect = $isRecentlyDeletedView || documentRequestIsDeletable($req);
                        ?>
                        <tr class="manage-requests-row"
                            data-request-row="<?= (int) $req['id'] ?>"
                            data-request="<?= htmlspecialchars(json_encode($viewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                            <?php if ($showBulkActions): ?>
                            <td class="manage-bulk-col" onclick="event.stopPropagation()">
                                <?php if ($canBulkSelect): ?>
                                <input type="checkbox" form="manageBulkForm" name="bulk_ids[]" value="<?= (int) $req['id'] ?>" class="manage-bulk-check manage-bulk-row-check" aria-label="Select request <?= htmlspecialchars($req['tracking_code']) ?>">
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td class="manage-col-request-id">
                                <span class="manage-id"><?= htmlspecialchars($req['tracking_code']) ?></span>
                            </td>
                            <td class="manage-col-requester">
                                <?php $requesterDisplay = personRequesterNameFromRow($req); ?>
                                <p class="manage-citizen-name"><?= htmlspecialchars($requesterDisplay !== '' ? $requesterDisplay : '—') ?></p>
                                <p class="manage-citizen-meta"><?= htmlspecialchars($req['phone'] ?: 'No phone on file') ?></p>
                            </td>
                            <td class="manage-col-record-name">
                                <p class="manage-citizen-name"><?= htmlspecialchars(documentRequestNameOnRecord($req)) ?></p>
                            </td>
                            <td class="manage-col-certificate"><span class="manage-doc-type"><?= htmlspecialchars(documentTypeLabel($req['document_type'])) ?></span></td>
                            <td class="manage-col-visit"><span class="manage-date"><?= htmlspecialchars(formatAppointmentDisplay($req['appointment_date'] ?? null, $req['appointment_time'] ?? null) ?: '—') ?></span></td>
                            <td class="manage-col-submitted"><span class="manage-date"><?= htmlspecialchars(formatReportDateTime($req['submitted_at'])) ?></span></td>
                            <?php if ($isRecentlyDeletedView): ?>
                            <td class="manage-col-submitted"><span class="manage-date"><?= !empty($req['deleted_at']) ? htmlspecialchars(formatReportDateTime($req['deleted_at'])) : '—' ?></span></td>
                            <?php endif; ?>
                            <td class="manage-col-status manage-cell-status">
                                <?= requestStatusBadge($req['status'], $req['rejection_reason'] ?? null) ?>
                                <?php if (!empty($viewData['lock']) && !empty($viewData['lock']['active'])): ?>
                                <p class="manage-lock-badge" data-lock-badge-for="<?= (int) $req['id'] ?>"><?= htmlspecialchars($viewData['lock']['label']) ?></p>
                                <?php else: ?>
                                <p class="manage-lock-badge hidden" data-lock-badge-for="<?= (int) $req['id'] ?>"></p>
                                <?php endif; ?>
                            </td>
                            <?php if ($showVisitLateNoShowColumn): ?>
                            <td class="manage-col-visit-late">
                                <?= renderManageVisitLateNoShowCell(
                                    $pdo,
                                    $req,
                                    'request',
                                    buildAuthUrl('manage_request.php'),
                                    [
                                        'redirect_status' => $filterStatus,
                                        'redirect_date'   => $viewDate,
                                        'redirect_q'      => $search,
                                    ]
                                ) ?>
                            </td>
                            <?php endif; ?>
                            <td class="manage-cell-actions">
                                <div class="manage-row-actions" onclick="event.stopPropagation()">
                                    <?php if (!$isRecentlyDeletedView): ?>
                                    <?php
                                    $rowStatus = normalizeRequestStatus((string) ($req['status'] ?? 'pending'));
                                    $rowCanPrint = canPrintRequestStatus($rowStatus) && ($req['document_type'] ?? '') !== 'cenomar';
                                    $viewLabel = match ($rowStatus) {
                                        'pending' => 'Verify',
                                        'ready'   => 'Complete',
                                        default   => 'View',
                                    };
                                    $isReadyAction = $rowStatus === 'ready';
                                    ?>
                                    <?php if ($rowCanPrint): ?>
                                    <div class="manage-print-menu">
                                        <button type="button"
                                                class="manage-row-action manage-row-action--labeled manage-row-action--print manage-print-trigger"
                                                title="Print options"
                                                aria-label="Print options for <?= htmlspecialchars(personNameFromRow($req)) ?>"
                                                aria-haspopup="true"
                                                aria-expanded="false">
                                            <i data-lucide="printer" class="w-3.5 h-3.5"></i>
                                            <span class="manage-row-action__label">PRINT</span>
                                            <i data-lucide="chevron-down" class="w-3 h-3 manage-print-trigger__chevron"></i>
                                        </button>
                                        <div class="manage-print-dropdown hidden" role="menu">
                                            <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['request_id' => (int) $req['id']])) ?>"
                                               role="menuitem">Local Certificate</a>
                                            <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['request_id' => (int) $req['id'], 'kind' => 'certification'])) ?>"
                                               role="menuitem">Certification</a>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <button type="button"
                                            class="view-request-btn manage-row-action manage-row-action--labeled<?= $isReadyAction ? ' manage-row-action--complete' : '' ?>"
                                            title="<?= htmlspecialchars($viewLabel . ' request') ?>"
                                            aria-label="<?= htmlspecialchars($viewLabel . ' request') ?>"
                                            data-request-row="<?= (int) $req['id'] ?>"
                                            data-request="<?= htmlspecialchars(json_encode($viewData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                        <?php if (!$isReadyAction): ?>
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                        <?php endif; ?>
                                        <span class="manage-row-action__label"><?= htmlspecialchars($isReadyAction ? $viewLabel : strtoupper($viewLabel)) ?></span>
                                    </button>
                                    <?php if (documentRequestIsDeletable($req)): ?>
                                    <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="manage-delete-form">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($filterStatus) ?>">
                    <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($viewDate) ?>">
                                        <input type="hidden" name="redirect_q" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <input type="hidden" name="delete_request" value="1">
                                        <button type="submit" title="Delete completed request" class="manage-row-action manage-row-action--danger" data-loading-text="Deleting…">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
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
                <aside id="requestDetailPanel" class="manage-request-detail">
                    <div id="requestDetailEmpty" class="manage-request-detail__empty">
                        <i data-lucide="mouse-pointer-click" class="w-8 h-8 text-gray-300"></i>
                        <p class="manage-detail-empty__title">Select a request</p>
                        <p class="manage-detail-empty__hint">Click Verify on a request to review and accept it</p>
                    </div>

                    <div id="requestDetailContent" class="manage-request-detail__content hidden">
                        <div class="manage-request-detail__header">
                            <div class="min-w-0 flex-1">
                                <h2 id="requestViewTitle" class="manage-detail-title truncate">—</h2>
                                <p id="requestViewCode" class="manage-detail-code"></p>
                                <p id="view-submitted" class="manage-detail-meta"></p>
                            </div>
                            <button type="button" id="requestDetailClose" class="manage-request-detail__close" aria-label="Close details">
                                <i data-lucide="x" class="w-4 h-4"></i>
                            </button>
                        </div>

                        <div class="manage-request-detail__body">
                            <section class="manage-detail-block">
                                <h3>Citizen</h3>
                                <dl class="manage-detail-rows">
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Name on record</dt><dd id="view-citizen-name"></dd></div>
                                    <div class="manage-detail-row"><dt>DOB</dt><dd id="view-dob"></dd></div>
                                    <div class="manage-detail-row hidden" id="view-dom-wrap"><dt>DOM</dt><dd id="view-dom"></dd></div>
                                    <div class="manage-detail-row"><dt>Sex</dt><dd id="view-sex"></dd></div>
                                </dl>
                            </section>

                            <section class="manage-detail-block">
                                <h3>Request</h3>
                                <dl class="manage-detail-rows">
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Requester</dt><dd id="view-requester"></dd></div>
                                    <div class="manage-detail-row"><dt>Phone</dt><dd id="view-phone"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Email</dt><dd id="view-email"></dd></div>
                                    <div class="manage-detail-row"><dt>Email OK</dt><dd id="view-email-verified"></dd></div>
                                    <div class="manage-detail-row"><dt>Document</dt><dd id="view-document-type"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Purpose</dt><dd id="view-purpose"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Visit</dt><dd id="view-appointment"></dd></div>
                                    <div class="manage-detail-row"><dt>Status</dt><dd id="view-status"></dd></div>
                                    <p id="view-lock-banner" class="manage-lock-badge manage-lock-detail-banner hidden" aria-live="polite"></p>
                                    <div class="manage-detail-row"><dt>Privacy</dt><dd id="view-privacy"></dd></div>
                                    <div class="manage-detail-row manage-detail-row--full"><dt>Updated</dt><dd id="view-updated"></dd></div>
                                </dl>
                            </section>

                            <section class="manage-detail-block">
                                <h3>IDs</h3>
                                <div id="view-id-files" class="manage-detail-ids"></div>
                            </section>

                            <section id="view-notes-wrap" class="manage-detail-block hidden">
                                <h3>Notes</h3>
                                <p id="view-notes" class="manage-detail-notes"></p>
                            </section>

                            <section id="view-rejection-wrap" class="manage-detail-block hidden">
                                <h3>Reason for rejection</h3>
                                <p id="view-rejection" class="manage-detail-notes"></p>
                            </section>
                        </div>

                        <div id="requestDetailActions" class="manage-request-detail__actions hidden" aria-label="Request actions"></div>
                    </div>
                </aside>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <div id="requestReviewModal" class="manage-request-modal hidden" aria-hidden="true">
        <div class="manage-request-modal__backdrop" data-close-request-modal></div>
        <div class="manage-request-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="modalRequestViewTitle">
            <div class="manage-request-modal__header">
                <div class="min-w-0 flex-1">
                    <h2 id="modalRequestViewTitle" class="manage-detail-title truncate">—</h2>
                    <p id="modalRequestViewCode" class="manage-detail-code"></p>
                    <p id="modal-view-submitted" class="manage-detail-meta"></p>
                </div>
                <p id="modal-request-header-lock" class="manage-modal-header-lock hidden" aria-live="polite"></p>
                <button type="button" class="manage-request-modal__close" data-close-request-modal aria-label="Close review popup">
                    <i data-lucide="x" class="w-4 h-4"></i>
                </button>
            </div>

            <div class="manage-request-modal__body">
                <section class="manage-detail-block">
                    <h3>Citizen</h3>
                    <dl class="manage-detail-rows">
                        <div class="manage-detail-row manage-detail-row--full"><dt>Name on record</dt><dd id="modal-view-citizen-name"></dd></div>
                        <div class="manage-detail-row"><dt>DOB</dt><dd id="modal-view-dob"></dd></div>
                        <div class="manage-detail-row hidden" id="modal-view-dom-wrap"><dt>DOM</dt><dd id="modal-view-dom"></dd></div>
                        <div class="manage-detail-row"><dt>Sex</dt><dd id="modal-view-sex"></dd></div>
                    </dl>
                </section>

                <section class="manage-detail-block">
                    <h3>Request</h3>
                    <dl class="manage-detail-rows">
                        <div class="manage-detail-row manage-detail-row--full"><dt>Requester</dt><dd id="modal-view-requester"></dd></div>
                        <div class="manage-detail-row"><dt>Phone</dt><dd id="modal-view-phone"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Email</dt><dd id="modal-view-email"></dd></div>
                        <div class="manage-detail-row"><dt>Email OK</dt><dd id="modal-view-email-verified"></dd></div>
                        <div class="manage-detail-row"><dt>Document</dt><dd id="modal-view-document-type"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Purpose</dt><dd id="modal-view-purpose"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Visit</dt><dd id="modal-view-appointment"></dd></div>
                        <div class="manage-detail-row"><dt>Status</dt><dd id="modal-view-status"></dd></div>
                        <p id="modal-view-lock-banner" class="manage-lock-badge manage-lock-detail-banner hidden" aria-live="polite"></p>
                        <div class="manage-detail-row"><dt>Privacy</dt><dd id="modal-view-privacy"></dd></div>
                        <div class="manage-detail-row manage-detail-row--full"><dt>Updated</dt><dd id="modal-view-updated"></dd></div>
                    </dl>
                </section>

                <section class="manage-detail-block">
                    <h3>IDs</h3>
                    <div id="modal-view-id-files" class="manage-detail-ids"></div>
                </section>

                <section id="modal-view-notes-wrap" class="manage-detail-block hidden">
                    <h3>Notes</h3>
                    <p id="modal-view-notes" class="manage-detail-notes"></p>
                </section>

                <section id="modal-view-rejection-wrap" class="manage-detail-block hidden">
                    <h3>Reason for rejection</h3>
                    <p id="modal-view-rejection" class="manage-detail-notes"></p>
                </section>
            </div>

            <div id="modalRequestDetailActions" class="manage-request-modal__actions hidden" aria-label="Request actions"></div>
        </div>
    </div>

    <?= pageConfigJson([
        'formAction'     => buildAuthUrl('manage_request.php'),
        'redirectStatus'      => $filterStatus,
        'redirectQ'           => $search,
        'redirectDate'        => $viewDate,
        'awaitingAllDates'    => $awaitingAllDates,
        'useSidePanel'   => $showSidePanel,
        'bulkActions'    => $showBulkActions,
        'pollUrl'        => buildAuthUrl('api/manage_requests.php'),
        'locksApiUrl'    => buildAuthUrl('api/staff_locks.php'),
        'lockType'       => STAFF_LOCK_TYPE_REQUEST,
    ]) ?>
    <div id="requestActionAuthFields" class="hidden" aria-hidden="true"><?= authFormField() ?></div>
    <?= staffIdLightboxMarkup() ?>
    <?= actionResultScript($flash) ?>
    <?= scriptTag('core/staff-id-view.js') ?>
    <?= scriptTag('admin/id-preview.js') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('core/admin-search.js') ?>
    <?= scriptTag('admin/manage-bulk.js') ?>
    <?= scriptTag('core/staff-resource-locks.js') ?>
    <?= scriptTag('admin/manage-request.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
