<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

requireStaffLogin();

?>

<header class="admin-header min-h-14 sm:min-h-16 border-b border-gray-100 flex items-center justify-end gap-2 sm:gap-3 px-3 sm:px-4 lg:px-6 xl:px-8 py-2 min-w-0 shrink-0">

    <div class="flex items-center gap-1.5 sm:gap-2 shrink-0 min-w-0">

        <!-- Notification Bell -->
        <div class="relative" id="notif-wrapper" data-staff-id="<?= htmlspecialchars(staffId()) ?>">

            <button
                type="button"
                id="notif-bell-btn"
                class="relative p-2 rounded-lg hover:bg-gray-50 transition-colors focus:outline-none"
                aria-label="Notifications"
                aria-expanded="false"
                aria-haspopup="true"
            >
                <i
                    data-lucide="bell"
                    class="w-5 h-5 text-gray-400 pointer-events-none"
                ></i>

                <span
                    id="notif-badge"
                    class="hidden absolute -top-0.5 -right-0.5 min-w-[18px] h-4 px-1 bg-red-500 text-white text-[9px] font-bold rounded-full flex items-center justify-center leading-none"
                >
                    0
                </span>
            </button>

            <!-- Notification Dropdown -->
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


        <!-- Staff Name + Profile -->
        <div class="flex items-center space-x-2 sm:space-x-3">

            <div class="text-right hidden md:block min-w-0">
                <p class="text-xs font-bold text-slate-900 leading-none truncate max-w-[6rem] sm:max-w-[8rem] lg:max-w-[10rem] xl:max-w-none">
                    <?= htmlspecialchars(staffName()) ?>
                </p>

                <p class="text-[9px] text-gray-400 uppercase font-bold tracking-tighter">
                    <?= htmlspecialchars(staffRole()) ?>
                </p>
            </div>

            <?= renderStaffAvatar(staffPhotoPath(), staffName()) ?>

        </div>

    </div>

</header>
