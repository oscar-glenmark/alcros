<?php
/**
 * ALCROS - Privacy & Safety Policy
 */
session_start();
require_once __DIR__ . '/includes/helpers.php';

$site = getSiteSettings();
$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl = 'login.php';
$currentPage = basename($_SERVER['PHP_SELF']);
$year = date('Y');

function navClass($page, $current) {
    return $page === $current ? 'text-blue-600 font-semibold' : 'hover:text-blue-600';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy &amp; Safety - <?= htmlspecialchars($site['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">

    <nav class="flex items-center justify-between px-8 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2">
            <div class="bg-blue-600 text-white p-1 rounded font-bold text-[10px] w-5 h-5 flex items-center justify-center">A</div>
            <span class="font-bold tracking-tight text-blue-900"><?= htmlspecialchars($site['name']) ?></span>
        </div>
        <div class="flex items-center gap-8 text-sm font-medium text-gray-600">
            <a href="index.php" class="<?= navClass('index.php', $currentPage) ?>">Home</a>
            <a href="track.php" class="<?= navClass('track.php', $currentPage) ?>">Track Request</a>
            <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="bg-blue-600 text-white px-4 py-1.5 rounded-md text-xs hover:bg-blue-700 transition">
                <?= $isStaffLoggedIn ? 'Staff Dashboard' : 'Staff Portal' ?>
            </a>
        </div>
    </nav>

    <main class="max-w-3xl mx-auto px-6 py-14">
        <a href="index.php" class="text-blue-600 text-[11px] font-bold inline-flex items-center gap-1 mb-6 hover:underline">
            <i data-lucide="chevron-left" class="w-3 h-3"></i> Back to Home
        </a>
        <h1 class="text-3xl font-extrabold text-slate-900 mb-2">Privacy &amp; Safety Policy</h1>
        <p class="text-gray-500 text-sm mb-10"><?= htmlspecialchars($site['office']) ?></p>

        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-8 space-y-6 text-sm text-gray-600 leading-relaxed">
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">1. Introduction</h2>
                <p>The <?= htmlspecialchars($site['name']) ?> portal is operated by the <?= htmlspecialchars($site['office']) ?> to provide civil registry services online. We are committed to protecting your personal data in compliance with the Data Privacy Act of 2012 (Republic Act No. 10173) and its implementing rules.</p>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">2. Information We Collect</h2>
                <p>When you use our services, we may collect:</p>
                <ul class="list-disc pl-5 mt-2 space-y-1">
                    <li>Personal identifiers (full name, date of birth, sex, contact number, email address)</li>
                    <li>Valid government-issued identification documents for verification</li>
                    <li>Document request details, appointment schedules, and purpose of request</li>
                    <li>Tracking codes, queue ticket numbers, and service transaction records</li>
                </ul>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">3. How We Use Your Information</h2>
                <p>Your information is used solely for legitimate civil registry purposes, including processing document requests, scheduling appointments, verifying identity, issuing queue numbers, notifying you of status updates, and maintaining official records required by law.</p>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">4. Data Security</h2>
                <p>We implement appropriate organizational, physical, and technical safeguards to protect personal data against unauthorized access, alteration, disclosure, or destruction. Access is limited to authorized LGU personnel who require the information to perform their duties.</p>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">5. Data Sharing</h2>
                <p>We do not sell your personal information. Data may be shared only when required by law, with your consent, or with government agencies as permitted for civil registry transactions.</p>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">6. Your Rights</h2>
                <p>Under the Data Privacy Act, you have the right to be informed, access, correct, and object to the processing of your personal data, subject to applicable laws and regulations. To exercise these rights, please contact our office using the details below.</p>
            </section>
            <section>
                <h2 class="text-base font-bold text-slate-900 mb-2">7. Contact Us</h2>
                <p>For privacy-related concerns or data requests, reach us at:</p>
                <ul class="mt-2 space-y-1">
                    <li><strong>Address:</strong> <?= htmlspecialchars($site['address']) ?></li>
                    <li><strong>Phone:</strong> <a href="tel:<?= htmlspecialchars($site['phone']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($site['phone']) ?></a></li>
                    <li><strong>Email:</strong> <a href="mailto:<?= htmlspecialchars($site['email']) ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($site['email']) ?></a></li>
                    <li><strong>Office Hours:</strong> <?= htmlspecialchars($site['hours']) ?></li>
                </ul>
            </section>
        </div>
    </main>

    <footer class="bg-[#0b1120] text-gray-500 py-6 px-12 text-[10px] flex justify-between items-center border-t border-gray-800 mt-12">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-1">
                <div class="bg-blue-600 text-white p-0.5 rounded text-[8px] font-bold">A</div>
                <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>
            </div>
            <span>&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>
        </div>
        <div class="flex gap-4">
            <a href="index.php" class="hover:text-white">Home</a>
            <a href="track.php" class="hover:text-white">Track</a>
        </div>
    </footer>

    <script>lucide.createIcons();</script>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
</body>
</html>
