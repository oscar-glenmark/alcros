<?php
/**
 * ALCROS - All civil registry services
 */
session_start();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$site = getSiteSettings();
$documentTypes = getDocumentTypes();
$appointmentServices = getAppointmentServices();
$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl = 'login.php';
$currentPage = basename($_SERVER['PHP_SELF']);

function navClass($page, $current) {
    return $page === $current ? 'text-blue-600 font-semibold' : 'hover:text-blue-600';
}

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Services - <?= htmlspecialchars($site['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="includes/back_home.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">

    <nav class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2 min-w-0">
            <?= alcrosFaviconImg(20) ?>
            <span class="font-bold tracking-tight text-blue-900 truncate"><?= htmlspecialchars($site['name']) ?></span>
        </div>
        <div class="hidden md:flex items-center gap-8 text-sm font-medium text-gray-600">
            <a href="index.php" class="<?= navClass('index.php', $currentPage) ?>">Home</a>
            <button type="button" data-open-track class="text-gray-600 hover:text-blue-600 font-medium bg-transparent border-0 cursor-pointer p-0">Track Request</button>
            <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="bg-blue-600 text-white px-4 py-1.5 rounded-md text-xs hover:bg-blue-700 transition">
                <?= $isStaffLoggedIn ? 'Staff Dashboard' : 'Staff Portal' ?>
            </a>
        </div>
        <div class="md:hidden flex items-center gap-2 shrink-0">
            <button type="button" data-open-track class="text-xs font-semibold text-blue-700 px-3 py-2 rounded-lg bg-blue-50">Track</button>
            <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="text-xs font-bold text-white px-3 py-2 rounded-lg bg-blue-700">Staff</a>
        </div>
    </nav>

    <header class="max-w-6xl mx-auto px-6 pt-14 pb-8 text-center">
        <a href="index.php" class="back-home back-home--inline is-centered mb-4">
            <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
            <span>Back to Home</span>
        </a>
        <h1 class="text-3xl font-extrabold text-slate-900 mb-2">All Civil Registry Services</h1>
        <p class="text-gray-500 text-sm max-w-xl mx-auto"><?= renderOverviewText($site['overview'], $site['office']) ?></p>
    </header>

    <section class="max-w-6xl mx-auto px-6 pb-12">
        <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-6">Fast-Track Online Services</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-16">
            <?php foreach ($documentTypes as $doc): ?>
            <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100 text-center hover:shadow-md transition">
                <div class="w-12 h-12 <?= $doc['iconBg'] ?> rounded-lg flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="<?= htmlspecialchars($doc['icon']) ?>" class="w-5 h-5"></i>
                </div>
                <h3 class="font-bold text-sm mb-2"><?= htmlspecialchars($doc['label']) ?></h3>
                <p class="text-gray-400 text-[11px] leading-relaxed mb-5"><?= htmlspecialchars($doc['desc']) ?></p>
                <a href="request.php?type=<?= urlencode($doc['slug']) ?>" class="text-blue-600 text-[10px] font-bold border-b border-blue-600 pb-0.5 inline-flex items-center gap-0.5">
                    APPLY NOW <i data-lucide="chevron-right" class="w-3 h-3"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>

        <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-2">Special Services & Consultations</p>
        <p class="text-gray-400 text-xs mb-8 italic">Schedule an appointment for record updates and civil registry consultations.</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($appointmentServices as $svc): ?>
            <div class="bg-white p-6 rounded-xl border border-gray-100 flex flex-col items-start text-left relative hover:shadow-md transition">
                <span class="absolute top-4 right-4 text-[9px] font-bold text-orange-400 border border-orange-200 px-2 py-0.5 rounded uppercase">Appointment</span>
                <div class="w-8 h-8 <?= $svc['iconBg'] ?> rounded flex items-center justify-center mb-4">
                    <i data-lucide="<?= htmlspecialchars($svc['icon']) ?>" class="w-4 h-4"></i>
                </div>
                <h4 class="font-bold text-xs mb-1 uppercase"><?= htmlspecialchars($svc['label']) ?></h4>
                <p class="text-gray-400 text-[10px] mb-4 flex-1"><?= htmlspecialchars($svc['desc']) ?></p>
                <a href="book_appointment.php?service=<?= urlencode($svc['slug']) ?>" class="text-blue-600 text-[10px] font-bold border-b border-blue-600 pb-0.5 inline-flex items-center gap-0.5">
                    SCHEDULE APPOINTMENT <i data-lucide="chevron-right" class="w-3 h-3"></i>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="max-w-4xl mx-auto px-6 pb-16">
        <div class="bg-white rounded-2xl border border-gray-100 p-8 text-center shadow-sm">
            <h2 class="text-lg font-black text-slate-900 mb-2">Need Help Choosing a Service?</h2>
            <p class="text-gray-500 text-sm mb-6">Visit the <?= htmlspecialchars($site['office']) ?> during office hours or contact us directly.</p>
            <div class="flex flex-wrap justify-center gap-4 text-xs text-gray-500">
                <span class="flex items-center gap-1.5"><i data-lucide="clock" class="w-3.5 h-3.5 text-blue-500"></i> <?= htmlspecialchars($site['hours']) ?></span>
                <span class="flex items-center gap-1.5"><i data-lucide="phone" class="w-3.5 h-3.5 text-blue-500"></i> <?= htmlspecialchars($site['phone']) ?></span>
                <span class="flex items-center gap-1.5"><i data-lucide="mail" class="w-3.5 h-3.5 text-blue-500"></i> <?= htmlspecialchars($site['email']) ?></span>
            </div>
        </div>
    </section>

    <footer class="bg-[#0b1120] text-gray-500 py-6 px-12 text-[10px] flex justify-between items-center border-t border-gray-800">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-1">
                <?= alcrosFaviconImg(20) ?>
                <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>
            </div>
            <span>&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>
        </div>
        <div class="flex gap-4">
            <a href="index.php" class="hover:text-white">Home</a>
            <button type="button" data-open-track class="hover:text-white bg-transparent border-0 p-0 cursor-pointer">Track</button>
            <a href="privacy.php" class="hover:text-white">Privacy & Safety</a>
        </div>
    </footer>

    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= scriptTag('core/reminders.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
