<?php
/** @var string $activePage current page filename e.g. dashboard.php */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/scripts.php';

$activePage = $activePage ?? basename($_SERVER['PHP_SELF']);

function sidebarLink(string $page, string $label, string $icon, string $active, bool $liveBadge = false, ?string $countBadgeId = null): string
{
    $isActive = ($page === $active);
    $class = $isActive ? 'active-nav' : 'sidebar-item text-slate-600';
    $badge = $liveBadge
        ? '<span class="bg-white text-blue-600 text-[10px] px-1.5 py-0.5 rounded font-bold">LIVE</span>'
        : '';
    $countBadge = $countBadgeId
        ? '<span id="' . htmlspecialchars($countBadgeId) . '" class="hidden ml-auto min-w-[18px] h-5 px-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center leading-none">0</span>'
        : '';
    $hasTrailing = $liveBadge || $countBadgeId;
    $justify = $hasTrailing ? ' justify-between' : '';
    $href = buildAuthUrl($page);

    // Pre-sizing icon wrapper prevents reflow layout flickering when JS icons initialize
    $iconHtml = '<i data-lucide="' . $icon . '" class="w-4 h-4 mr-3 shrink-0 inline-block align-middle"></i>';

    if ($liveBadge) {
        $inner = '<div class="flex items-center min-w-0 flex-1">' . $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span></div>' . $badge;
    } elseif ($countBadgeId) {
        $inner = '<div class="flex items-center min-w-0 flex-1">' . $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span></div>' . $countBadge;
    } else {
        $inner = $iconHtml . '<span class="sidebar-link-label">' . htmlspecialchars($label) . '</span>';
    }

    return '<a href="' . htmlspecialchars($href) . '" class="' . $class . ' flex items-center min-w-0 px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150' . $justify . '">'
        . $inner
        . '</a>';
}

function sidebarSectionLabel(string $label): string
{
    return '<p class="sidebar-section-label">' . htmlspecialchars($label) . '</p>';
}

?>


<aside class="admin-sidebar" aria-label="Admin navigation">
    <div class="admin-sidebar-brand p-4 sm:p-5 flex items-start justify-between gap-3">
        <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="flex items-center gap-3 min-w-0 flex-1 transition hover:opacity-90">
            <?= alcrosFaviconImg(44, 'gov-brand-logo shrink-0') ?>
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
        <?= sidebarLink('manage_request.php', 'Manage Requests', 'file-text', $activePage) ?>
        <?= sidebarLink('appointment.php', 'Appointments', 'calendar', $activePage) ?>
        <?= sidebarLink('records.php', 'Civil records', 'book-open', $activePage) ?>
        <?= sidebarLink('report.php', 'Operational Reports', 'file-bar-chart-2', $activePage) ?>
        <?= sidebarLink('live-queue.php', 'Live queue', 'users', $activePage, true) ?>
        <?php if (isAdmin()): ?>
        <?= sidebarSectionLabel('Administration') ?>
        <?= sidebarLink('analytics.php', 'Analytics', 'bar-chart-2', $activePage) ?>
        <?= sidebarLink('Activity-log.php', 'Activity log', 'scroll-text', $activePage) ?>
        <?= sidebarLink('system_settings.php', 'System settings', 'settings', $activePage) ?>
        <?php else: ?>
        <?= sidebarSectionLabel('Account') ?>
        <?= sidebarLink('system_settings.php', 'My settings', 'settings', $activePage) ?>
        <?php endif; ?>
    </nav>
    <div class="admin-sidebar-footer p-4 border-t border-gray-100 bg-white">
        <button type="button" id="logoutOpenBtn" class="flex items-center text-red-500 text-[11px] font-bold uppercase tracking-wider hover:bg-red-50 w-full px-3 py-2 rounded-lg transition-colors duration-150">
            <i data-lucide="log-out" class="w-4 h-4 mr-2 shrink-0"></i><span class="sidebar-logout-label">Logout</span>
        </button>
    </div>
</aside>

<div id="logoutConfirmModal" class="fixed inset-0 bg-black/40 z-[100] hidden items-center justify-center p-4">
    <div class="bg-white rounded-2xl border border-gray-100 shadow-xl w-full max-w-sm p-6">
        <div class="flex items-start gap-3 mb-4">
            <div class="bg-red-50 text-red-500 p-2 rounded-xl">
                <i data-lucide="log-out" class="w-5 h-5"></i>
            </div>
            <div>
                <h3 class="text-base font-black text-slate-900">Confirm Logout</h3>
                <p class="text-sm text-gray-500 mt-1">Are you sure you want to logout?</p>
            </div>
        </div>
        <div class="flex gap-2">
            <button type="button" id="logoutCancelBtn" class="flex-1 border border-gray-200 rounded-xl py-2.5 text-sm font-bold text-gray-600 hover:bg-gray-50">Cancel</button>
            <a href="<?= htmlspecialchars(buildAuthUrl('logout.php')) ?>" id="logoutConfirmBtn" class="flex-1 bg-red-600 hover:bg-red-700 text-white rounded-xl py-2.5 text-sm font-bold text-center">Logout</a>
        </div>
    </div>
</div>

<?= adminCoreScripts() ?>
