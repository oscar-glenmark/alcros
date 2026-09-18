<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/lucide_icons.php';
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
            <?= lucideSvg('panel-left', 'w-4 h-4 pointer-events-none') ?>
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
        <span id="live-sync-indicator" class="live-sync-indicator admin-header__sync hidden sm:inline-flex" aria-live="polite">Live</span>
        <div class="admin-header__actions flex items-center shrink-0">
            <div class="relative admin-header__notif" id="notif-wrapper" data-staff-id="<?= htmlspecialchars(staffId()) ?>">
                <button
                type="button"
                id="notif-bell-btn"
                class="admin-header__notif-btn"
                aria-label="Notifications"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <?= lucideSvg('bell', 'admin-header__notif-icon pointer-events-none') ?>

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
                <?= lucideSvg('chevron-down', 'admin-header__profile-chevron w-3.5 h-3.5 text-gray-400 shrink-0 pointer-events-none hidden sm:block') ?>
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
                    <?= lucideSvg('user', 'w-4 h-4 shrink-0') ?>
                    <span>My information</span>
                </a>
                <button type="button" class="admin-header__profile-menu-item admin-header__profile-menu-item--danger" data-logout-trigger role="menuitem">
                    <?= lucideSvg('log-out', 'w-4 h-4 shrink-0') ?>
                    <span>Logout</span>
                </button>
            </div>
        </div>
    </div>

</header>
