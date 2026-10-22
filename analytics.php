<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/analytics_dashboard.php';
require_once __DIR__ . '/includes/scripts.php';
requireAdmin();
releaseSessionLock();

$activePage = 'analytics.php';
$pdo = getDB();

$adminAnalytics = fetchAnalyticsDashboard($pdo);
$officeName = getSetting('office_name', 'Local Civil Registrar Office (LCRO) of Aloran');

$pageTitle = 'Analytics';
$pageSubtitle = 'Same live office analytics as Reports — civil records, print volume, intake, and queue.';
$pageHeaderMeta = '<p class="admin-header__meta">' . htmlspecialchars($officeName) . ' · Live office analytics</p>';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= interFontTags() ?>
    <?= adminLayoutHeadStyles('report') ?>
    <?= stylesheetTag('admin/dashboard.css') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?= vendorScriptTag('chart.umd.min.js') ?>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#f8fafc]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto admin-page-wrap">
            <?php
            $adminAnalyticsContext = 'report';
            require __DIR__ . '/includes/admin_dashboard_analytics.php';
            ?>
        </div>
    </main>
    <?= pageConfigJson($adminAnalytics['chartPayload'], 'analytics-config') ?>
    <?= scriptTag('core/page-config.js') ?>
    <?= scriptTag('admin/analytics.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
