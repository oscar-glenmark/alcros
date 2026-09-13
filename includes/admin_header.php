<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/api_helpers.php';

requireStaffLogin();

$pageTitle = $pageTitle ?? '';
$pageSubtitle = $pageSubtitle ?? '';
$pageHeaderMeta = $pageHeaderMeta ?? '';

?>

<header class="admin-header w-full border-b border-gray-100 flex items-center justify-between gap-2 sm:gap-3 px-3 sm:px-4 lg:px-6 xl:px-8 min-w-0 shrink-0">

    <div class="admin-header__lead flex items-center gap-2 min-w-0 flex-1">
        <button
            type="button"
            id="adminSidebarToggle"
            aria-label="Toggle sidebar"
            aria-expanded="false"
        >
            <i data-lucide="panel-left" class="w-4 h-4 pointer-events-none"></i>
        </button>
        <?php if ($pageTitle !== ''): ?>
        <div class="admin-header__titles min-w-0">
            <h1 class="admin-header__title"><?= htmlspecialchars($pageTitle) ?></h1>
            <?php if ($pageSubtitle !== ''): ?>
            <p class="admin-header__subtitle"><?= htmlspecialchars($pageSubtitle) ?></p>
            <?php endif; ?>
            <?= $pageHeaderMeta ?>
        </div>
        <?php endif; ?>
    </div>

    <div class="admin-header__toolbar flex items-center min-w-0">
        <div class="admin-header__actions flex items-center shrink-0">
            <div class="admin-header__theme-wrap">
                <button
                    type="button"
                    id="alcrosThemeToggle"
                    class="admin-header__theme-btn"
                    aria-label="Switch to dark mode"
                    aria-pressed="false"
                    title="Dark mode"
                >
                    <i data-lucide="moon" class="alcros-theme-icon alcros-theme-icon--dark pointer-events-none"></i>
                    <i data-lucide="sun" class="alcros-theme-icon alcros-theme-icon--light pointer-events-none"></i>
                </button>
            </div>

            <div class="relative admin-header__notif" id="notif-wrapper" data-staff-id="<?= htmlspecialchars(staffId()) ?>">
                <button
                type="button"
                id="notif-bell-btn"
                class="admin-header__notif-btn"
                aria-label="Notifications"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <i data-lucide="bell" class="admin-header__notif-icon pointer-events-none"></i>

                <span
                    id="notif-badge"
                    class="hidden absolute -top-0.5 -right-0.5 min-w-[18px] h-4 px-1 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center leading-none"
                >
                    0
                </span>
                </button>

                <div
                    id="notif-dropdown"
                class="hidden fixed sm:absolute left-4 right-4 sm:left-auto sm:right-0 top-16 sm:top-full sm:mt-2 w-auto sm:w-80 lg:w-96 bg-white rounded-xl border border-gray-100 shadow-xl z-50 overflow-hidden"
                >
                    <?php
                    $notifPanel = [
                        'context'       => 'dropdown',
                        'listId'        => 'notif-list',
                        'listClass'     => 'max-h-96 overflow-y-auto',
                        'showFooter'    => true,
                        'toolbarPrefix' => '',
                    ];
                    require __DIR__ . '/notifications_panel.php';
                    ?>
                </div>
            </div>
        </div>

        <?php $myInfoUrl = buildAuthUrl('system_settings.php', ['tab' => 'my-account']); ?>
        <div class="relative admin-header__profile" id="profile-wrapper">
            <button
                type="button"
                id="profile-menu-btn"
                class="admin-header__profile-btn"
                aria-label="Account menu"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <span class="admin-header__profile-text hidden lg:block">
                    <span class="admin-header__profile-name"><?= htmlspecialchars(staffName()) ?></span>
                    <span class="admin-header__profile-role"><?= htmlspecialchars(staffRole()) ?></span>
                </span>
                <?= renderStaffAvatar(staffPhotoPath(), staffName(), 'w-7 h-7 text-[10px]') ?>
                <i data-lucide="chevron-down" class="admin-header__profile-chevron w-3.5 h-3.5 text-gray-400 shrink-0 pointer-events-none hidden sm:block"></i>
            </button>

            <div
                id="profile-dropdown"
                class="admin-header__profile-menu hidden"
                role="menu"
                aria-labelledby="profile-menu-btn"
            >
                <div class="admin-header__profile-menu-head lg:hidden">
                    <p class="admin-header__profile-menu-name"><?= htmlspecialchars(staffName()) ?></p>
                    <p class="admin-header__profile-menu-role"><?= htmlspecialchars(staffRole()) ?></p>
                </div>
                <a href="<?= htmlspecialchars($myInfoUrl) ?>" class="admin-header__profile-menu-item" role="menuitem">
                    <i data-lucide="user" class="w-4 h-4 shrink-0"></i>
                    <span>My information</span>
                </a>
                <button type="button" class="admin-header__profile-menu-item admin-header__profile-menu-item--danger" data-logout-trigger role="menuitem">
                    <i data-lucide="log-out" class="w-4 h-4 shrink-0"></i>
                    <span>Logout</span>
                </button>
            </div>
        </div>
    </div>

</header>
