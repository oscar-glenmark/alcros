<?php
/** @var string $activePage current page filename e.g. dashboard.php */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scripts.php';
require_once __DIR__ . '/lucide_icons.php';

try {
    runReminderSchedulerIfDue(getDB());
} catch (Throwable $e) {
    // Non-fatal when reminders cannot run.
}

$activePage = $activePage ?? basename($_SERVER['PHP_SELF']);

function sidebarLink(string $page, string $label, string $icon, string $active, bool $liveBadge = false, ?string $countBadgeId = null, array $query = []): string
{
    $isActive = ($page === $active);
    $class = $isActive ? 'active-nav' : 'sidebar-item';
    $badge = $liveBadge
        ? '<span class="bg-white text-blue-600 text-[10px] px-1.5 py-0.5 rounded font-bold">LIVE</span>'
        : '';
    $countBadge = $countBadgeId
        ? '<span id="' . htmlspecialchars($countBadgeId) . '" class="hidden ml-auto min-w-[18px] h-5 px-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none">0</span>'
        : '';
    $hasTrailing = $liveBadge || $countBadgeId;
    $justify = $hasTrailing ? ' justify-between' : '';
    $href = buildAuthUrl($page, $query);

    $iconHtml = lucideSvg($icon, 'admin-sidebar-icon w-4 h-4 mr-3 shrink-0 inline-block align-middle');

    if ($liveBadge) {
        $inner = '<div class="flex items-center min-w-0 flex-1">' . $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span></div>' . $badge;
    } elseif ($countBadgeId) {
        $inner = '<div class="flex items-center min-w-0 flex-1">' . $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span></div>' . $countBadge;
    } else {
        $inner = $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span>';
    }

    return '<a href="' . htmlspecialchars($href) . '" data-sidebar-tip="' . htmlspecialchars($label) . '" class="' . $class . ' flex items-center min-w-0 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150' . $justify . '">'
        . $inner
        . '</a>';
}

function sidebarSectionLabel(string $label): string
{
    return '<p class="sidebar-section-label">' . htmlspecialchars($label) . '</p>';
}

?>


<script>(function(){try{if(sessionStorage.getItem('admin_sidebar_minimized')==='1'){document.body.classList.add('admin-sidebar-minimized');}}catch(e){}})();</script>
<aside class="admin-sidebar" aria-label="Admin navigation">
    <div class="admin-sidebar-brand p-4 sm:p-5 flex items-start justify-between gap-3">
        <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" data-sidebar-tip="Dashboard" class="flex items-center gap-3 min-w-0 flex-1 transition hover:opacity-90">
            <?= alcrosFaviconImg(52, 'gov-brand-logo shrink-0') ?>
            <div class="min-w-0">
                <div class="gov-brand-title">Municipality of Aloran Misamis Occidental</div>
                <div class="gov-brand-subtitle">ALCROS</div>
            </div>
        </a>
    </div>
    <nav class="admin-sidebar-nav px-4 py-4 space-y-1" id="sidebarNavScroll">
        <?= sidebarSectionLabel('Operations') ?>
        <?= sidebarLink('dashboard.php', 'Dashboard', 'layout-dashboard', $activePage) ?>
        <?= sidebarLink('notifications.php', 'Notifications', 'bell', $activePage, false, 'sidebar-notif-badge') ?>
        <?= sidebarLink('manage_request.php', 'Manage Requests', 'file-text', $activePage, false, 'sidebar-request-badge') ?>
        <?= sidebarLink('appointment.php', 'Manage Appointments', 'calendar', $activePage, false, 'sidebar-appt-badge') ?>
        <?= sidebarLink('records.php', 'Records', 'book-open', $activePage) ?>
        <?= sidebarLink('report.php', 'Reports', 'bar-chart-2', $activePage) ?>
        <?= sidebarLink('live-queue.php', 'Manage live queue', 'users', $activePage, true) ?>
        <?= sidebarLink('documents.php', 'Documents', 'files', $activePage) ?>
        <?php if (isAdmin()): ?>
        <?= sidebarSectionLabel('Administration') ?>
        <?= sidebarLink('print_calibration.php', 'Print calibration', 'crosshair', $activePage) ?>
        <?= sidebarLink('activity-log.php', 'Activity log', 'scroll-text', $activePage) ?>
        <?= sidebarLink('system_settings.php', 'Settings', 'settings', $activePage) ?>
        <?php else: ?>
        <?= sidebarSectionLabel('Account') ?>
        <?= sidebarLink('system_settings.php', 'Settings', 'settings', $activePage) ?>
        <?php endif; ?>
    </nav>
</aside>

<div id="logoutConfirmModal" class="alcros-confirm-modal alcros-logout-modal is-hidden" role="dialog" aria-modal="true" aria-labelledby="logoutConfirmTitle">
    <div class="alcros-confirm-modal__panel">
        <button type="button" class="alcros-modal-close" id="logoutCloseBtn" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
        </button>
        <div class="alcros-confirm-modal__hero">
            <div class="alcros-confirm-modal__icon-wrap alcros-logout-modal__icon-wrap" aria-hidden="true">
                <div class="alcros-confirm-modal__icon alcros-logout-modal__icon">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path><polyline points="16 17 21 12 16 7"></polyline><line x1="21" y1="12" x2="9" y2="12"></line></svg>
                </div>
            </div>
            <p class="alcros-modal-badge alcros-modal-badge--logout">Sign out</p>
            <h3 id="logoutConfirmTitle" class="alcros-confirm-modal__title">Confirm Logout</h3>
            <p class="alcros-confirm-modal__message">Are you sure you want to logout?</p>
        </div>
        <div class="alcros-confirm-modal__actions">
            <button type="button" id="logoutCancelBtn" class="alcros-confirm-modal__btn alcros-confirm-modal__btn--cancel">Cancel</button>
            <a href="<?= htmlspecialchars(buildAuthUrl('logout.php')) ?>" id="logoutConfirmBtn" class="alcros-confirm-modal__btn alcros-confirm-modal__btn--danger">Logout</a>
        </div>
    </div>
</div>

<?= adminCoreScripts() ?>
