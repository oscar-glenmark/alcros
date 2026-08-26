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
        $inner = '<div class="flex items-center">' . $iconHtml . htmlspecialchars($label) . '</div>' . $badge;
    } elseif ($countBadgeId) {
        $inner = '<div class="flex items-center min-w-0 flex-1">' . $iconHtml . htmlspecialchars($label) . '</div>' . $countBadge;
    } else {
        $inner = $iconHtml . htmlspecialchars($label);
    }

    return '<a href="' . htmlspecialchars($href) . '" class="' . $class . ' flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150' . $justify . '">'
        . $inner
        . '</a>';
}

?>
<style>
    /* Fixed Positioning with GPU Rendering Layer to Eliminate Page-Load Flicker */
    .admin-sidebar {
        position: fixed;
        top: 0;
        left: 0;
        width: 16rem;
        height: 100vh;
        height: 100dvh;
        z-index: 50;
        display: flex;
        flex-direction: column;
        background-color: #ffffff;
        border-right: 1px solid #e2e8f0;
        transform: translateX(-100%);
        transition: transform 0.25s ease;
        backface-visibility: hidden;
        will-change: transform;
        contain: layout style;
    }
    @media (min-width: 1024px) {
        .admin-sidebar {
            transform: translateX(0);
            z-index: 30;
        }
    }
    .admin-sidebar.is-open {
        transform: translateX(0);
    }
    .admin-sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 40;
        background: rgba(15, 23, 42, 0.45);
        opacity: 0;
        visibility: hidden;
        transition: opacity 0.25s ease, visibility 0.25s ease;
    }
    .admin-sidebar-backdrop.is-open {
        opacity: 1;
        visibility: visible;
    }
    @media (min-width: 1024px) {
        .admin-sidebar-backdrop {
            display: none;
        }
    }
    body.admin-sidebar-open {
        overflow: hidden;
    }
    .admin-sidebar-nav {
        flex: 1 1 auto;
        overflow-y: auto;
        overscroll-behavior: contain;
        scrollbar-width: thin;
    }
    .admin-sidebar-footer {
        flex-shrink: 0;
    }
    .admin-main {
        margin-left: 0;
        width: 100%;
        min-width: 0;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
    }
    @media (min-width: 1024px) {
        .admin-main {
            margin-left: 16rem;
            width: calc(100vw - 16rem);
        }
    }
    .admin-content {
        flex: 1 1 auto;
        width: 100%;
    }
    /* Smooth interaction styling */
    .sidebar-item {
        transition: background-color 0.15s ease, color 0.15s ease;
    }
    .sidebar-item:hover { 
        background-color: #f1f5f9; 
    }
    .active-nav { 
        background-color: #2563eb !important; 
        color: #ffffff !important; 
    }
    .active-nav #sidebar-notif-badge {
        background-color: #ffffff;
        color: #dc2626;
    }
    
    /* Touch & Focus Normalization to stop blue flash boxes */
    .admin-sidebar a,
    .admin-sidebar button {
        -webkit-tap-highlight-color: transparent;
        tap-highlight-color: transparent;
        outline: none;
    }
    .admin-sidebar a:focus,
    .admin-sidebar a:focus-visible,
    .admin-sidebar button:focus,
    .admin-sidebar button:focus-visible {
        outline: none;
        box-shadow: none;
    }
    
    /* Prevent icon layout shift before Lucide hydrated */
    .admin-sidebar [data-lucide] {
        display: inline-block;
        min-width: 1rem;
        min-height: 1rem;
    }
    .admin-sidebar-brand {
        flex-shrink: 0;
        background: #050b18;
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }
    .admin-sidebar-brand .gov-brand-logo {
        width: 2.75rem;
        height: 2.75rem;
        border-radius: 9999px;
        object-fit: cover;
        background: #fff;
        box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.12);
    }
    .admin-sidebar-brand .gov-brand-title {
        color: #ffffff;
        font-size: 0.8125rem;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: -0.01em;
    }
    .admin-sidebar-brand .gov-brand-subtitle {
        color: rgba(255, 255, 255, 0.78);
        font-size: 0.6875rem;
        font-style: italic;
        line-height: 1.35;
        margin-top: 0.2rem;
    }
    .admin-sidebar-brand .sidebar-close-btn {
        color: rgba(255, 255, 255, 0.75);
    }
    .admin-sidebar-brand .sidebar-close-btn:hover {
        background: rgba(255, 255, 255, 0.1);
        color: #ffffff;
    }
</style>

<div id="adminSidebarBackdrop" class="admin-sidebar-backdrop" aria-hidden="true"></div>

<aside class="admin-sidebar" id="mainAdminSidebar" aria-label="Admin navigation">
    <div class="admin-sidebar-brand p-4 sm:p-5 flex items-start justify-between gap-3">
        <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="flex items-center gap-3 min-w-0 flex-1 transition hover:opacity-90">
            <?= alcrosFaviconImg(44, 'gov-brand-logo shrink-0') ?>
            <div class="min-w-0">
                <div class="gov-brand-title">Municipality of Aloran Misamis Occidental</div>
                <div class="gov-brand-subtitle">ALCROS</div>
            </div>
        </a>
        <button type="button" id="sidebarCloseBtn" class="sidebar-close-btn lg:hidden shrink-0 p-2 rounded-lg transition-colors" aria-label="Close menu">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>
    <nav class="admin-sidebar-nav px-4 py-4 space-y-1" id="sidebarNavScroll">
        <?= sidebarLink('dashboard.php', 'Dashboard', 'layout-dashboard', $activePage) ?>
        <?= sidebarLink('notifications.php', 'Notifications', 'bell', $activePage, false, 'sidebar-notif-badge') ?>
        <?= sidebarLink('manage_request.php', 'Manage Requests', 'file-text', $activePage) ?>
        <?= sidebarLink('appointment.php', 'Appointments', 'calendar', $activePage) ?>
        <?= sidebarLink('records.php', 'Civil records', 'book-open', $activePage) ?>
        <?php if (isAdmin()): ?>
        <?= sidebarLink('analytics.php', 'Analytics', 'bar-chart-2', $activePage) ?>
        <?= sidebarLink('Activity-log.php', 'Activity log', 'activity', $activePage) ?>
        <?= sidebarLink('report.php', 'Operational Reports', 'file-bar-chart-2', $activePage) ?>
        <?php endif; ?>
        <?= sidebarLink('live-queue.php', 'Live queue', 'users', $activePage, true) ?>
        <?= sidebarLink('system_settings.php', isAdmin() ? 'System settings' : 'My settings', 'settings', $activePage) ?>
    </nav>
    <div class="admin-sidebar-footer p-4 border-t border-gray-100 bg-white">
        <button type="button" id="logoutOpenBtn" class="flex items-center text-red-500 text-[11px] font-bold uppercase tracking-wider hover:bg-red-50 w-full px-3 py-2 rounded-lg transition-colors duration-150">
            <i data-lucide="log-out" class="w-4 h-4 mr-2 shrink-0"></i> Logout
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
