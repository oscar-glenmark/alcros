<?php
/**
 * ALCROS - Privacy & Safety Policy
 */
session_start();
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$site = getSiteSettings();
$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl = $isStaffLoggedIn ? 'dashboard.php' : 'login.php';
$staffPortalLabel = $isStaffLoggedIn ? 'Dashboard' : 'Staff Login';
$year = date('Y');

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
        'content' => '<p>Under the Data Privacy Act, you have the right to be informed, access, correct, and object to the processing of your personal data, subject to applicable laws and regulations. To exercise these rights, please contact our office using the contact information on the home page.</p>',
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
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?= publicStylesheet('landing') ?>
    <?= publicStylesheet('back-home') ?>
    <?= publicStylesheet('privacy') ?>
</head>
<body class="bg-white">

    <header class="site-header sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-4 py-3">
                <a href="index.php" class="flex items-center gap-3 min-w-0">
                    <?= alcrosFaviconImg(48, 'brand-logo shrink-0') ?>
                    <div class="site-brand-text">
                        <div class="site-brand-title">ALCROS</div>
                        <div class="site-brand-subtitle">Aloran Local Civil Registry Online System</div>
                    </div>
                </a>

                <nav class="hidden lg:flex items-center gap-1 xl:gap-2">
                    <a href="index.php" class="nav-link">Home</a>
                    <a href="index.php#services" class="nav-link">Services</a>
                    <button type="button" data-open-track class="nav-link cursor-pointer bg-transparent border-0 border-b-2 border-transparent">Track Request</button>
                    <a href="index.php#about" class="nav-link">About</a>
                    <a href="index.php#faqs" class="nav-link">FAQs</a>
                    <a href="index.php#contact" class="nav-link">Contact Us</a>
                </nav>

                <div class="flex items-center gap-2 shrink-0">
                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="btn-login hidden sm:inline-block">
                        <?= htmlspecialchars($staffPortalLabel) ?>
                    </a>
                    <button type="button" id="mobileNavToggle" class="lg:hidden p-2 text-white" aria-label="Open menu">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>

            <div id="mobileNav" class="hidden lg:hidden pb-4 border-t border-white/10 pt-3">
                <div class="flex flex-col gap-1">
                    <a href="index.php" class="nav-link">Home</a>
                    <a href="index.php#services" class="nav-link">Services</a>
                    <button type="button" data-open-track class="nav-link text-left cursor-pointer bg-transparent border-0">Track Request</button>
                    <a href="index.php#about" class="nav-link">About</a>
                    <a href="index.php#faqs" class="nav-link">FAQs</a>
                    <a href="index.php#contact" class="nav-link">Contact Us</a>
                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="btn-login inline-block text-center mt-2 w-fit"><?= htmlspecialchars($staffPortalLabel) ?></a>
                </div>
            </div>
        </div>
    </header>

    <section class="privacy-hero hero-section flex items-center">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full py-12 md:py-16">
            <a href="index.php" class="back-home back-home--inline is-centered">
                <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                <span>Back to Home</span>
            </a>
            <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-6 max-w-5xl">
                <div class="max-w-2xl">
                    <div class="privacy-badge mb-4">
                        <i data-lucide="shield" class="w-3.5 h-3.5"></i>
                        Data Privacy Act Compliant
                    </div>
                    <h1 class="text-white text-2xl sm:text-3xl md:text-4xl font-black leading-tight tracking-tight uppercase mb-2">
                        Privacy &amp; <span class="text-gold-accent">Safety Policy</span>
                    </h1>
                    <p class="text-white/75 text-sm md:text-base leading-relaxed">
                        <?= htmlspecialchars($site['office']) ?> — how we collect, protect, and use your personal information.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="privacy-tag privacy-tag--gold">RA 10173</span>
                    <span class="privacy-tag">Secure Processing</span>
                    <span class="privacy-tag">LGU Official Portal</span>
                </div>
            </div>
        </div>
    </section>

    <main class="bg-slate-50 py-10 md:py-14">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-[220px_1fr] gap-8 items-start">
                <aside class="privacy-sidebar lg:sticky lg:top-24 p-4">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-3 px-2">On this page</p>
                    <nav class="space-y-1">
                        <?php foreach ($sections as $i => $section): ?>
                        <a href="#<?= htmlspecialchars($section['id']) ?>" class="privacy-sidebar-link">
                            <span class="privacy-sidebar-link__num"><?= $i + 1 ?></span>
                            <?= htmlspecialchars($section['title']) ?>
                        </a>
                        <?php endforeach; ?>
                    </nav>
                </aside>

                <div class="space-y-4">
                    <?php foreach ($sections as $i => $section): ?>
                    <section id="<?= htmlspecialchars($section['id']) ?>" class="privacy-section-card p-6 scroll-mt-24">
                        <div class="flex items-start gap-4 mb-4">
                            <div class="privacy-section-icon">
                                <i data-lucide="<?= htmlspecialchars($section['icon']) ?>" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <p class="privacy-section-label mb-1">Section <?= $i + 1 ?></p>
                                <h2 class="text-lg font-extrabold text-slate-900"><?= htmlspecialchars($section['title']) ?></h2>
                            </div>
                        </div>
                        <div class="text-sm text-slate-600 leading-relaxed pl-0 md:pl-14">
                            <?= $section['content'] ?>
                        </div>
                    </section>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>

    <footer class="footer-dark">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-10">
                <div class="lg:col-span-1">
                    <a href="index.php" class="flex items-center gap-3 mb-4">
                        <?= alcrosFaviconImg(40, 'brand-logo shrink-0') ?>
                        <div>
                            <div class="text-white font-extrabold text-sm">ALCROS</div>
                            <div class="text-white/60 text-[10px] italic">Aloran Local Civil Registry Online System</div>
                        </div>
                    </a>
                    <p class="text-xs leading-relaxed text-white/60">Official online portal for civil registry services in the Municipality of Aloran.</p>
                </div>
                <div>
                    <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-xs">
                        <li><a href="index.php" class="hover:text-gold transition">Home</a></li>
                        <li><a href="index.php#services" class="hover:text-gold transition">Services</a></li>
                        <li><button type="button" data-open-track class="hover:text-gold transition bg-transparent border-0 p-0 cursor-pointer text-left text-white/75">Track Request</button></li>
                        <li><a href="services.php" class="hover:text-gold transition">All Services</a></li>
                        <li><a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="hover:text-gold transition"><?= htmlspecialchars($staffPortalLabel) ?></a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4">Contact Us</h4>
                    <ul class="space-y-3 text-xs">
                        <li class="flex items-start gap-2">
                            <i data-lucide="map-pin" class="w-4 h-4 text-gold shrink-0 mt-0.5"></i>
                            <span><?= htmlspecialchars($site['address']) ?></span>
                        </li>
                        <li class="flex items-center gap-2">
                            <i data-lucide="phone" class="w-4 h-4 text-gold shrink-0"></i>
                            <a href="tel:<?= htmlspecialchars($site['phone']) ?>" class="hover:text-gold transition"><?= htmlspecialchars($site['phone']) ?></a>
                        </li>
                        <li class="flex items-center gap-2">
                            <i data-lucide="mail" class="w-4 h-4 text-gold shrink-0"></i>
                            <a href="mailto:<?= htmlspecialchars($site['email']) ?>" class="hover:text-gold transition break-all"><?= htmlspecialchars($site['email']) ?></a>
                        </li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4">Office Hours</h4>
                    <p class="text-[11px] text-white/60 leading-relaxed"><?= htmlspecialchars($site['hours']) ?></p>
                </div>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row justify-between items-center gap-2 text-[11px] text-white/50">
                <span>&copy; <?= htmlspecialchars($year) ?> ALCROS. All Rights Reserved.</span>
                <div class="flex gap-4">
                    <button type="button" data-open-privacy class="hover:text-white transition bg-transparent border-0 p-0 cursor-pointer">Privacy Policy</button>
                    <span class="text-white/30">|</span>
                    <a href="privacy.php" class="hover:text-white transition text-white/80">Privacy &amp; Safety</a>
                </div>
            </div>
        </div>
    </footer>

    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/maintenance_announcement.php'; ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= scriptTag('public/landing.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
