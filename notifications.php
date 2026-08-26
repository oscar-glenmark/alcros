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
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
    </style>
</head>
<body class="flex min-h-screen" data-realtime="notifications">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="admin-content p-6 lg:p-8 max-w-3xl w-full mx-auto">
            <div class="mb-6">
                <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                    <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                </a>
                <h1 class="text-2xl font-black text-slate-900">Notifications</h1>
                <p class="text-sm text-slate-500 mt-0.5">Alerts for pending requests, ready pickups, queue, and appointments.</p>
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
