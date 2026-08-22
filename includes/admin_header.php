<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

requireStaffLogin();

?>

<header class="h-16 border-b border-gray-100 flex items-center justify-between px-8 bg-white sticky top-0 z-20">

    <!-- Search -->
    <div class="relative w-96">
        <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>

        <input
            type="text"
            placeholder="SEARCH CIVIL RECORDS OR TRACKING ID..."
            class="w-full pl-10 pr-4 py-2 text-[10px] bg-gray-50 border border-gray-100 rounded-md focus:outline-none focus:ring-1 focus:ring-blue-500 font-bold uppercase tracking-tight"
        >
    </div>


    <!-- Right Side -->
    <div class="flex items-center space-x-4">

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
                class="hidden absolute right-0 top-full mt-2 w-80 sm:w-96 bg-white rounded-xl border border-gray-100 shadow-xl z-50 overflow-hidden"
            >

                <div class="px-4 py-3 border-b border-gray-50 flex justify-between items-center bg-white gap-2">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-800">
                        Notifications
                    </p>

                    <div class="flex items-center gap-3 shrink-0">
                        <button
                            type="button"
                            id="notif-clear-all"
                            class="text-[10px] font-bold text-slate-400 hover:text-red-500 uppercase tracking-wide"
                        >
                            Clear all
                        </button>
                        <button
                            type="button"
                            id="notif-mark-read"
                            class="text-[10px] font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wide"
                        >
                            Mark all read
                        </button>
                    </div>
                </div>

                <div
                    id="notif-list"
                    class="max-h-96 overflow-y-auto bg-white"
                >
                    <p class="text-gray-300 text-xs italic p-8 text-center">
                        Loading notifications...
                    </p>
                </div>

                <div class="px-4 py-2.5 border-t border-gray-50 bg-gray-50">
                    <a
                        id="notif-view-all"
                        href="dashboard.php"
                        class="text-[10px] font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wide"
                    >
                        Open dashboard
                    </a>
                </div>

            </div>
        </div>


        <!-- Staff Name + Profile -->
        <div class="flex items-center space-x-3">

            <div class="text-right">
                <p class="text-xs font-bold text-slate-900 leading-none">
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