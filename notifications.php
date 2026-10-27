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
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars(staffPortalInboxLabel()) ?> - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen bg-[#f8fafc]" data-realtime="notifications" data-staff-portal-role="<?= $notifIsAdmin ? 'administrator' : 'staff' ?>">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="admin-content p-4 sm:p-6 lg:p-8 admin-page-wrap">
            <div class="notif-page">
                <div class="notif-page__card">
                    <?php
                    $notifPanel = [
                        'context'       => 'page',
                        'listId'        => 'notif-page-list',
                        'listClass'     => 'min-h-[280px]',
                        'showFooter'    => false,
                        'toolbarPrefix' => 'page-',
                    ];
                    require __DIR__ . '/includes/notifications_panel.php';
                    ?>
                </div>
            </div>
        </div>
    </main>
    <?= lucideInitScript() ?>
</body>
</html>
