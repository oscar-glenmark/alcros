<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireStaffLogin();
requirePageAccess('notifications.php');

$activePage = 'notifications.php';
$notifIsAdmin = isAdmin();

$pageTitle = staffPortalInboxLabel();
$pageSubtitle = $notifIsAdmin
    ? 'System and maintenance issues that need an administrator (not citizen queue or document requests).'
    : 'Alerts when citizens need help: review requests, serve the queue, or manage appointments.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(staffPortalInboxLabel()) ?> - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen" data-realtime="notifications">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="admin-content p-4 sm:p-6 lg:p-8 max-w-3xl w-full mx-auto admin-page-wrap">
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

            <p class="mt-4 text-center text-xs text-slate-500 max-w-lg mx-auto leading-relaxed">
                <?php if ($notifIsAdmin): ?>
                The list updates while you work (about every 15 seconds). You only see <strong>system and maintenance</strong> alerts here (configuration, reminders, server health). Citizen requests, queue, and appointments are sent to <strong>Staff</strong> accounts. Use <strong>Reports</strong> for read-only oversight of office activity.
                <?php else: ?>
                The list updates while you work (about every 15 seconds). Tap an alert to open the right screen and help the citizen. The bell icon shows the same list on every page.
                <?php endif; ?>
            </p>
        </div>
    </main>
    <?= lucideInitScript() ?>
</body>
</html>
