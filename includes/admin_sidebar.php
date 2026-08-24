<?php
/** @var string $activePage current page filename e.g. dashboard.php */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

$activePage = $activePage ?? basename($_SERVER['PHP_SELF']);
$siteName = getSiteSettings()['name'];

function sidebarLink(string $page, string $label, string $icon, string $active, bool $liveBadge = false): string
{
    $isActive = ($page === $active);
    $class = $isActive ? 'active-nav' : 'sidebar-item text-slate-600';
    $badge = $liveBadge
        ? '<span class="bg-white text-blue-600 text-[10px] px-1.5 py-0.5 rounded font-bold">LIVE</span>'
        : '';
    $justify = $liveBadge ? ' justify-between' : '';
    $href = buildAuthUrl($page);

    // Pre-sizing icon wrapper prevents reflow layout flickering when JS icons initialize
    $iconHtml = '<i data-lucide="' . $icon . '" class="w-4 h-4 mr-3 shrink-0 inline-block align-middle"></i>';

    return '<a href="' . htmlspecialchars($href) . '" class="' . $class . ' flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-colors duration-150' . $justify . '">'
        . ($liveBadge ? '<div class="flex items-center">' . $iconHtml . htmlspecialchars($label) . '</div>' . $badge
            : $iconHtml . htmlspecialchars($label))
        . '</a>';
}

$sidebarSubtitle = isAdmin() ? 'Registry Admin' : 'Staff Portal';
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
        z-index: 30;
        display: flex;
        flex-direction: column;
        background-color: #ffffff;
        border-right: 1px solid #e2e8f0;
        transform: translateZ(0);
        backface-visibility: hidden;
        will-change: transform;
        contain: layout style;
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
        margin-left: 16rem;
        width: calc(100vw - 16rem);
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        flex-direction: column;
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
</style>

<aside class="admin-sidebar" id="mainAdminSidebar">
    <div class="flex-shrink-0 p-6 border-b border-gray-50">
        <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="group flex items-center gap-3 rounded-xl transition hover:opacity-90">
            <div class="flex items-center justify-center w-9 h-9 bg-gradient-to-br from-blue-600 to-blue-700 rounded-xl shadow-md shadow-blue-200/70 group-hover:shadow-lg group-hover:shadow-blue-200/80 transition-shadow shrink-0">
                <span class="text-white text-sm font-black">A</span>
            </div>
            <div class="flex flex-col leading-none min-w-0">
                <span class="font-black text-base tracking-tight text-slate-900 truncate"><?= htmlspecialchars($siteName) ?></span>
                <span class="text-[9px] font-bold text-blue-600 tracking-widest uppercase mt-1"><?= htmlspecialchars($sidebarSubtitle) ?></span>
            </div>
        </a>
    </div>
    <nav class="admin-sidebar-nav px-4 py-4 space-y-1" id="sidebarNavScroll">
        <?= sidebarLink('dashboard.php', 'Dashboard', 'layout-dashboard', $activePage) ?>
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

<script>
(function () {
    // Preserve sidebar navigation scroll position across page transitions
    const navScroll = document.getElementById('sidebarNavScroll');
    if (navScroll) {
        const savedScroll = sessionStorage.getItem('sidebar_scroll_pos');
        if (savedScroll !== null) {
            navScroll.scrollTop = parseInt(savedScroll, 10);
        }
        navScroll.addEventListener('scroll', function () {
            sessionStorage.setItem('sidebar_scroll_pos', navScroll.scrollTop);
        });
    }

    // Modal behavior logic
    const modal = document.getElementById('logoutConfirmModal');
    const openBtn = document.getElementById('logoutOpenBtn');
    const cancelBtn = document.getElementById('logoutCancelBtn');
    if (!modal || !openBtn || !cancelBtn) return;

    function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    openBtn.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) {
        if (e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('flex')) closeModal();
    });
})();
</script>

<script src="includes/admin_auth.js"></script>
<script src="includes/loading.js"></script>
<script src="includes/poll.js"></script>
<script src="includes/realtime.js"></script>
<script src="includes/notifications.js"></script>
<script src="includes/reminders.js"></script>