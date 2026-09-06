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

    <div class="admin-header__toolbar flex items-center gap-1.5 sm:gap-2 shrink-0 min-w-0">
        <div class="relative admin-header__notif" id="notif-wrapper" data-staff-id="<?= htmlspecialchars(staffId()) ?>">

            <button
                type="button"
                id="notif-bell-btn"
                class="relative p-1.5 rounded-lg hover:bg-gray-50 transition-colors focus:outline-none"
                aria-label="Notifications"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <i
                    data-lucide="bell"
                    class="w-4 h-4 text-gray-400 pointer-events-none"
                ></i>

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

        <div class="flex items-center space-x-1.5 sm:space-x-2">
            <div class="text-right hidden md:block min-w-0">
                <p class="text-[11px] font-bold text-slate-900 leading-none truncate max-w-[6rem] sm:max-w-[8rem] lg:max-w-[10rem] xl:max-w-none">
                    <?= htmlspecialchars(staffName()) ?>
                </p>
                <p class="text-[8px] text-gray-400 uppercase font-bold tracking-tighter">
                    <?= htmlspecialchars(staffRole()) ?>
                </p>
            </div>

            <?= renderStaffAvatar(staffPhotoPath(), staffName(), 'w-7 h-7 text-[10px]') ?>
        </div>
    </div>

</header>
