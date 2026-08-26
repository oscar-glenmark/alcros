<?php
/**
 * ALCROS - Privacy & Safety Policy
 */
session_start();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$site = getSiteSettings();
$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl = 'login.php';
$currentPage = basename($_SERVER['PHP_SELF']);
$year = date('Y');

function navClass($page, $current) {
    return $page === $current ? 'text-blue-600 font-semibold' : 'hover:text-blue-600';
}

$sections = [
    [
        'id' => 'introduction',
        'icon' => 'shield-check',
        'title' => 'Introduction',
        'content' => '<p>The ' . htmlspecialchars($site['name']) . ' portal is operated by the ' . htmlspecialchars($site['office']) . ' to provide civil registry services online. We are committed to protecting your personal data in compliance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong> and its implementing rules.</p>',
    ],
    [
        'id' => 'collection',
        'icon' => 'database',
        'title' => 'Information We Collect',
        'content' => '<p>When you use our services, we may collect:</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
                <li>Personal identifiers (full name, date of birth, sex, contact number, email address)</li>
                <li>Valid government-issued identification documents for verification</li>
                <li>Document request details, appointment schedules, and purpose of request</li>
                <li>Tracking codes, queue ticket numbers, and service transaction records</li>
            </ul>',
    ],
    [
        'id' => 'usage',
        'icon' => 'clipboard-list',
        'title' => 'How We Use Your Information',
        'content' => '<p>Your information is used solely for legitimate civil registry purposes, including processing document requests, scheduling appointments, verifying identity, issuing queue numbers, notifying you of status updates, and maintaining official records required by law.</p>',
    ],
    [
        'id' => 'security',
        'icon' => 'lock',
        'title' => 'Data Security',
        'content' => '<p>We implement appropriate organizational, physical, and technical safeguards to protect personal data against unauthorized access, alteration, disclosure, or destruction. Access is limited to authorized LGU personnel who require the information to perform their duties.</p>',
    ],
    [
        'id' => 'sharing',
        'icon' => 'share-2',
        'title' => 'Data Sharing',
        'content' => '<p>We do not sell your personal information. Data may be shared only when required by law, with your consent, or with government agencies as permitted for civil registry transactions.</p>',
    ],
    [
        'id' => 'rights',
        'icon' => 'scale',
        'title' => 'Your Rights',
        'content' => '<p>Under the Data Privacy Act, you have the right to be informed, access, correct, and object to the processing of your personal data, subject to applicable laws and regulations. To exercise these rights, please contact our office using the details below.</p>',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy &amp; Safety - <?= htmlspecialchars($site['name']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="includes/back_home.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .gradient-text {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        html { scroll-behavior: smooth; }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">

    <nav class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-gray-100 bg-white">
        <a href="index.php" class="group flex items-center gap-3 rounded-xl pr-2 -ml-1 py-1 transition hover:opacity-90 min-w-0">
            <div class="flex items-center justify-center w-9 h-9 bg-gradient-to-br from-blue-600 to-blue-700 rounded-xl shadow-md shadow-blue-200/70 group-hover:shadow-lg group-hover:shadow-blue-200/80 transition-shadow shrink-0">
                <span class="text-white text-sm font-black">A</span>
            </div>
            <div class="flex flex-col leading-none min-w-0">
                <span class="font-black text-base tracking-tight text-slate-900 truncate"><?= htmlspecialchars($site['name']) ?></span>
                <span class="text-[9px] font-bold text-blue-600 tracking-widest uppercase mt-1">Civil Registry Portal</span>
            </div>
        </a>
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

    <header class="bg-white border-b border-gray-100">
        <div class="max-w-5xl mx-auto px-6 py-12">
            <a href="index.php" class="back-home back-home--inline mb-6">
                <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                <span>Back to Home</span>
            </a>
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6">
                <div class="max-w-2xl">
                    <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 text-[10px] font-bold uppercase tracking-wider mb-4">
                        <i data-lucide="shield" class="w-3.5 h-3.5"></i> Data Privacy Act Compliant
                    </div>
                    <h1 class="text-3xl md:text-4xl font-extrabold text-slate-900 mb-2">Privacy &amp; <span class="gradient-text">Safety Policy</span></h1>
                    <p class="text-gray-500 text-sm leading-relaxed"><?= htmlspecialchars($site['office']) ?> — how we collect, protect, and use your personal information.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-[10px] font-bold uppercase tracking-wide">RA 10173</span>
                    <span class="px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 text-[10px] font-bold uppercase tracking-wide">Secure Processing</span>
                    <span class="px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 text-[10px] font-bold uppercase tracking-wide">LGU Official Portal</span>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-5xl mx-auto px-6 py-10">
        <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-8 items-start">
            <aside class="lg:sticky lg:top-6 bg-white rounded-2xl border border-gray-100 shadow-sm p-4">
                <p class="text-[10px] font-bold uppercase tracking-widest text-gray-400 mb-3 px-2">On this page</p>
                <nav class="space-y-1">
                    <?php foreach ($sections as $i => $section): ?>
                    <a href="#<?= htmlspecialchars($section['id']) ?>" class="flex items-center gap-2 px-2 py-2 rounded-lg text-xs font-semibold text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition">
                        <span class="text-[10px] font-black text-blue-400 w-4"><?= $i + 1 ?></span>
                        <?= htmlspecialchars($section['title']) ?>
                    </a>
                    <?php endforeach; ?>
                    <a href="#contact" class="flex items-center gap-2 px-2 py-2 rounded-lg text-xs font-semibold text-gray-600 hover:bg-blue-50 hover:text-blue-600 transition">
                        <span class="text-[10px] font-black text-blue-400 w-4">7</span>
                        Contact Us
                    </a>
                </nav>
            </aside>

            <div class="space-y-4">
                <?php foreach ($sections as $i => $section): ?>
                <section id="<?= htmlspecialchars($section['id']) ?>" class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 scroll-mt-6">
                    <div class="flex items-start gap-4 mb-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
                            <i data-lucide="<?= htmlspecialchars($section['icon']) ?>" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-blue-500 mb-1">Section <?= $i + 1 ?></p>
                            <h2 class="text-lg font-extrabold text-slate-900"><?= htmlspecialchars($section['title']) ?></h2>
                        </div>
                    </div>
                    <div class="text-sm text-gray-600 leading-relaxed pl-0 md:pl-14">
                        <?= $section['content'] ?>
                    </div>
                </section>
                <?php endforeach; ?>

                <section id="contact" class="bg-gradient-to-br from-slate-900 to-slate-800 rounded-2xl shadow-lg p-6 md:p-8 text-white scroll-mt-6">
                    <div class="flex items-start gap-4 mb-6">
                        <div class="w-10 h-10 rounded-xl bg-white/10 text-blue-200 flex items-center justify-center shrink-0">
                            <i data-lucide="mail" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-widest text-blue-300 mb-1">Section 7</p>
                            <h2 class="text-lg font-extrabold">Contact Us</h2>
                            <p class="text-sm text-slate-300 mt-1">For privacy-related concerns or data requests, reach our office:</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:pl-14">
                        <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Address</p>
                            <p class="text-sm text-slate-100"><?= htmlspecialchars($site['address']) ?></p>
                        </div>
                        <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Phone</p>
                            <a href="tel:<?= htmlspecialchars($site['phone']) ?>" class="text-sm text-blue-300 hover:text-blue-200 hover:underline"><?= htmlspecialchars($site['phone']) ?></a>
                        </div>
                        <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Email</p>
                            <a href="mailto:<?= htmlspecialchars($site['email']) ?>" class="text-sm text-blue-300 hover:text-blue-200 hover:underline"><?= htmlspecialchars($site['email']) ?></a>
                        </div>
                        <div class="bg-white/5 border border-white/10 rounded-xl px-4 py-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-1">Office Hours</p>
                            <p class="text-sm text-slate-100"><?= htmlspecialchars($site['hours']) ?></p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </main>

    <footer class="bg-[#0b1120] text-gray-500 py-6 px-12 text-[10px] flex flex-wrap justify-between items-center gap-4 border-t border-gray-800 mt-12">
        <div class="flex flex-wrap items-center gap-6">
            <a href="index.php" class="group flex items-center gap-2.5 transition hover:opacity-90">
                <div class="flex items-center justify-center w-7 h-7 bg-gradient-to-br from-blue-500 to-blue-700 rounded-lg shadow-md shadow-blue-900/40 group-hover:shadow-lg group-hover:shadow-blue-900/50 transition-shadow shrink-0">
                    <span class="text-white text-[10px] font-black">A</span>
                </div>
                <div class="flex flex-col leading-none">
                    <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>
                    <span class="text-[8px] font-semibold text-blue-400 tracking-wider uppercase mt-0.5">Civil Registry Portal</span>
                </div>
            </a>
            <span>&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>
        </div>
        <div class="flex gap-4">
            <a href="index.php" class="hover:text-white transition">Home</a>
            <button type="button" data-open-track class="hover:text-white transition bg-transparent border-0 p-0 cursor-pointer">Track</button>
            <button type="button" data-open-privacy class="hover:text-white transition">Privacy &amp; Safety</button>
        </div>
    </footer>

    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= lucideInitScript() ?>
</body>
</html>
