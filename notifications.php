<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('notifications.php');

$activePage = 'notifications.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <?= adminLayoutHeadStyles() ?>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex min-h-screen" data-realtime="notifications">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="admin-content p-4 sm:p-6 lg:p-8 max-w-3xl w-full mx-auto admin-page-wrap">
            <div class="admin-page-head mb-6">
                <h1>Notifications</h1>
                <p>Alerts for pending requests, ready pickups, queue, and appointments.</p>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <?php
                $notifPanel = [
                    'context'       => 'page',
                    'listId'        => 'notif-page-list',
                    'listClass'     => 'min-h-[240px]',
                    'showFooter'    => false,
                    'toolbarPrefix' => 'page-',
                ];
                require __DIR__ . '/includes/notifications_panel.php';
                ?>
            </div>

            <p class="mt-4 text-center text-xs text-slate-400">
                Notifications refresh automatically every minute. Use the bell icon for quick access from any page.
            </p>
        </div>
    </main>
    <?= lucideInitScript() ?>
</body>
</html>
