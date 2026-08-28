<?php
/**
 * ALCROS - Local Civil Registry of Aloran
 * Landing page (index.php)
 */
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$site = getSiteSettings();
$maintenanceMode = isMaintenanceMode();
$publicRequestsAllowed = arePublicRequestsAllowed();

$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl  = 'login.php';
$currentPage = basename($_SERVER['PHP_SELF']);
$documentTypes = getDocumentTypes();
$year = date('Y');

$serviceCards = [
    ['slug' => 'birth', 'label' => 'Birth Certificate', 'desc' => 'Request an official copy of a birth certificate.', 'icon' => 'users', 'color' => 'bg-blue-600', 'link' => 'request.php?type=birth'],
    ['slug' => 'death', 'label' => 'Death Certificate', 'desc' => 'Request an official copy of a death certificate.', 'icon' => 'activity', 'color' => 'bg-emerald-600', 'link' => 'request.php?type=death'],
    ['slug' => 'marriage', 'label' => 'Marriage Certificate', 'desc' => 'Request an official copy of a marriage certificate.', 'icon' => 'heart', 'color' => 'bg-amber-500', 'link' => 'request.php?type=marriage'],
    ['slug' => 'other', 'label' => 'Other Services', 'desc' => 'CENOMAR, corrections, appointments, and more.', 'icon' => 'file-text', 'color' => 'bg-violet-600', 'link' => 'services.php'],
];

$howSteps = [
    ['num' => '01', 'icon' => 'clipboard-list', 'title' => 'Request Online', 'desc' => 'Choose the certificate type and fill out the online request form.'],
    ['num' => '02', 'icon' => 'upload', 'title' => 'Submit Details', 'desc' => 'Provide your information and upload the required supporting documents.'],
    ['num' => '03', 'icon' => 'shield-check', 'title' => 'Office Processing', 'desc' => 'Our staff verifies your request and prepares your certificate.'],
    ['num' => '04', 'icon' => 'package', 'title' => 'Track & Claim', 'desc' => 'Monitor your request status online and claim once ready.'],
];

$whyItems = [
    ['icon' => 'shield-check', 'title' => 'Secure & Reliable', 'desc' => 'Official LCRO records handled with care and compliance.'],
    ['icon' => 'clock', 'title' => 'Convenient', 'desc' => 'Submit requests online without long queues at the office.'],
    ['icon' => 'smartphone', 'title' => 'Accessible', 'desc' => 'Use ALCROS from any device with an internet connection.'],
    ['icon' => 'search', 'title' => 'Easy to Track', 'desc' => 'Check your request status anytime with your tracking code.'],
];

$faqs = [
    ['q' => 'How do I request a certificate?', 'a' => 'Click Request a Certificate, choose your document type, complete the online form, and upload the required documents. You will receive a tracking code by email.'],
    ['q' => 'How long does processing take?', 'a' => 'Processing time varies by document type and completeness of requirements. Track your request online for real-time status updates from our office.'],
    ['q' => 'Where do I claim my certificate?', 'a' => 'Once your request status shows Ready, visit the Local Civil Registrar Office at the Municipal Hall during office hours with a valid ID.'],
    ['q' => 'What documents do I need?', 'a' => 'Requirements depend on the certificate type. The request form will guide you on valid IDs and supporting documents to upload.'],
    ['q' => 'Can I track my request online?', 'a' => 'Yes. Use the Track My Request section with your reference number (e.g. ALR-XXXXXXXX) to view current status.'],
    ['q' => 'Is my personal information secure?', 'a' => 'ALCROS follows the Data Privacy Act (RA 10173). Your information is used only for civil registry services and is not shared without authorization.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALCROS - Aloran Local Civil Registry Online System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; color: #1e293b; }

        :root {
            --alcros-navy: #071428;
            --alcros-navy-mid: #0c2247;
            --alcros-gold: #f4b400;
            --alcros-gold-hover: #e5a800;
        }

        .site-header {
            background: var(--alcros-navy);
            border-bottom: 1px solid rgba(255,255,255,0.06);
        }

        .brand-logo {
            width: 3rem;
            height: 3rem;
            border-radius: 9999px;
            object-fit: cover;
            background: #fff;
            box-shadow: 0 0 0 2px rgba(255,255,255,0.15);
        }

        .nav-link {
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 0.35rem 0.65rem;
            border-bottom: 2px solid transparent;
            transition: color 0.2s, border-color 0.2s;
        }
        .nav-link:hover { color: var(--alcros-gold); }
        .nav-link.is-active { color: #fff; border-bottom-color: var(--alcros-gold); }

        .btn-login {
            border: 1.5px solid rgba(255,255,255,0.85);
            color: #fff;
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            padding: 0.45rem 1.1rem;
            transition: background 0.2s, color 0.2s;
        }
        .btn-login:hover { background: #fff; color: var(--alcros-navy); }

        .hero-section {
            position: relative;
            min-height: 520px;
            background-image:
                linear-gradient(105deg, rgba(7, 20, 40, 0.94) 0%, rgba(7, 20, 40, 0.82) 42%, rgba(7, 20, 40, 0.45) 68%, rgba(7, 20, 40, 0.25) 100%),
                url('images/municipal-hall.jpg');
            background-size: cover;
            background-position: center;
        }

        .hero-seal {
            width: min(320px, 42vw);
            height: min(320px, 42vw);
            opacity: 0.18;
            filter: drop-shadow(0 0 40px rgba(244, 180, 0, 0.15));
        }

        .text-gold { color: var(--alcros-gold); }

        .btn-gold {
            background: var(--alcros-gold);
            color: #071428;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            font-size: 0.78rem;
            transition: background 0.2s, transform 0.15s;
        }
        .btn-gold:hover { background: var(--alcros-gold-hover); }

        .btn-outline-light {
            border: 1.5px solid rgba(255,255,255,0.75);
            color: #fff;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
            font-size: 0.78rem;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-outline-light:hover { background: rgba(255,255,255,0.1); border-color: #fff; }

        .service-card {
            border: 1px solid #e2e8f0;
            transition: box-shadow 0.2s, transform 0.2s, border-color 0.2s;
        }
        .service-card:hover {
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            transform: translateY(-2px);
            border-color: #cbd5e1;
        }

        .steps-line {
            top: 2rem;
            left: 10%;
            right: 10%;
            height: 0;
            border-top: 2px dotted #cbd5e1;
        }

        .track-banner {
            background: linear-gradient(90deg, #071428 0%, #0c2247 100%);
            border-radius: 0.25rem;
        }

        .track-banner-inner {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            align-items: stretch;
        }
        @media (min-width: 1024px) {
            .track-banner-inner {
                flex-direction: row;
                align-items: center;
                justify-content: flex-start;
                gap: 1rem;
            }
        }

        .faq-item[open] summary .faq-chevron { transform: rotate(180deg); }
        .faq-item summary { list-style: none; }
        .faq-item summary::-webkit-details-marker { display: none; }

        .footer-dark {
            background: var(--alcros-navy);
            color: rgba(255,255,255,0.75);
        }

        #mobileNav.is-open { display: block; }
    </style>
</head>
<body class="bg-white">

    <?php if ($maintenanceMode): ?>
    <div class="bg-amber-500 text-white text-center text-xs font-bold py-2 px-4">
        The citizen portal is currently under maintenance. Online requests may be temporarily unavailable.
    </div>
    <?php endif; ?>

    <!-- HEADER -->
    <header class="site-header sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-4 py-3">
                <a href="index.php" class="flex items-center gap-3 min-w-0">
                    <?= alcrosFaviconImg(48, 'brand-logo shrink-0') ?>
                    <div class="min-w-0 hidden sm:block">
                        <div class="text-white font-extrabold text-lg leading-tight tracking-tight">ALCROS</div>
                        <div class="text-white/70 text-[11px] italic leading-snug">Aloran Local Civil Registry Online System</div>
                    </div>
                </a>

                <nav class="hidden lg:flex items-center gap-1 xl:gap-2">
                    <a href="index.php" class="nav-link is-active">Home</a>
                    <a href="#services" class="nav-link">Services</a>
                    <button type="button" data-open-track class="nav-link cursor-pointer bg-transparent border-0 border-b-2 border-transparent">Track Request</button>
                    <a href="#about" class="nav-link">About</a>
                    <a href="#faqs" class="nav-link">FAQs </a>
                    <a href="#contact" class="nav-link">Contact Us</a>
                </nav>

                <div class="flex items-center gap-2 shrink-0">
                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="btn-login hidden sm:inline-block">
                        <?= $isStaffLoggedIn ? 'Dashboard' : 'Login' ?>
                    </a>
                    <button type="button" id="mobileNavToggle" class="lg:hidden p-2 text-white" aria-label="Open menu">
                        <i data-lucide="menu" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>

            <div id="mobileNav" class="hidden lg:hidden pb-4 border-t border-white/10 pt-3">
                <div class="flex flex-col gap-1">
                    <a href="index.php" class="nav-link is-active">Home</a>
                    <a href="#services" class="nav-link">Services</a>
                    <button type="button" data-open-track class="nav-link text-left cursor-pointer bg-transparent border-0">Track Request</button>
                    <a href="#about" class="nav-link">About</a>
                    <a href="#faqs" class="nav-link">FAQs</a>
                    <a href="#contact" class="nav-link">Contact Us</a>
                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="btn-login inline-block text-center mt-2 w-fit">Login</a>
                </div>
            </div>
        </div>
    </header>

    <!-- HERO -->
    <section class="hero-section flex items-center">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full py-16 lg:py-20">
            <div class="grid lg:grid-cols-2 gap-10 items-center">
                <div class="relative z-10 max-w-xl">
                    <h1 class="text-white text-2xl sm:text-3xl md:text-4xl lg:text-[2.65rem] font-black leading-tight tracking-tight uppercase mb-2">Aloran Local Civil Registry</h1>
                    <p class="text-gold text-4xl md:text-5xl lg:text-6xl font-black tracking-tight mb-4">ALCROS</p>
                    <p class="text-white text-lg md:text-xl font-semibold mb-3">Access Civil Registry Services Easily and Securely Online</p>
                    <p class="text-white/75 text-sm md:text-base leading-relaxed mb-8 max-w-lg">
                        Request birth, death, and marriage certificates without visiting the office. Track your application status anytime, anywhere.
                    </p>
                    <div class="flex flex-col sm:flex-row gap-3">
                        <?php if ($publicRequestsAllowed && !$maintenanceMode): ?>
                        <a href="request.php" class="btn-gold inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-sm">
                            Request a Certificate
                        </a>
                        <?php else: ?>
                        <span class="inline-flex items-center justify-center px-6 py-3.5 rounded-sm bg-slate-600 text-white/80 text-sm font-bold uppercase cursor-not-allowed">Requests Unavailable</span>
                        <?php endif; ?>
                        <button type="button" data-open-track class="btn-outline-light inline-flex items-center justify-center gap-2 px-6 py-3.5 rounded-sm">
                            <i data-lucide="search" class="w-4 h-4"></i> Track My Request
                        </button>
                    </div>
                </div>
                <div class="hidden lg:flex justify-end items-center relative">
                    <?= alcrosFaviconImg(320, 'hero-seal rounded-full') ?>
                </div>
            </div>
        </div>
    </section>

    <!-- OUR SERVICES -->
    <section id="services" class="py-16 md:py-20 bg-slate-50 scroll-mt-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900 uppercase tracking-wide">Our Services</h2>
                <p class="text-slate-500 text-sm mt-2 max-w-xl mx-auto">Select a service below to start your online request.</p>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <?php foreach ($serviceCards as $card): ?>
                <a href="<?= htmlspecialchars($card['link']) ?>" class="service-card bg-white rounded-lg p-6 flex flex-col text-center">
                    <div class="w-16 h-16 rounded-full <?= htmlspecialchars($card['color']) ?> text-white flex items-center justify-center mx-auto mb-5 shadow-md">
                        <i data-lucide="<?= htmlspecialchars($card['icon']) ?>" class="w-7 h-7"></i>
                    </div>
                    <h3 class="font-bold text-slate-900 text-sm uppercase tracking-wide mb-2"><?= htmlspecialchars($card['label']) ?></h3>
                    <p class="text-slate-500 text-xs leading-relaxed mb-5 flex-1"><?= htmlspecialchars($card['desc']) ?></p>
                    <span class="text-[11px] font-bold text-slate-800 uppercase tracking-wide inline-flex items-center justify-center gap-1 group-hover:gap-2">
                        <?= $card['slug'] === 'other' ? 'View Services' : 'Request Now' ?> <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section id="how-it-works" class="py-16 md:py-20 bg-white scroll-mt-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-14">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900 uppercase tracking-wide">How It Works</h2>
            </div>
            <div class="relative">
                <div class="steps-line absolute hidden md:block"></div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10 relative">
                    <?php foreach ($howSteps as $step): ?>
                    <div class="text-center">
                        <div class="relative inline-flex flex-col items-center">
                            <div class="w-16 h-16 rounded-full bg-[#071428] text-white flex items-center justify-center mb-4 shadow-lg relative z-10">
                                <i data-lucide="<?= htmlspecialchars($step['icon']) ?>" class="w-7 h-7"></i>
                            </div>
                            <span class="text-[10px] font-black text-slate-400 tracking-widest mb-1"><?= htmlspecialchars($step['num']) ?></span>
                        </div>
                        <h3 class="font-bold text-slate-900 text-sm mb-2"><?= htmlspecialchars($step['title']) ?></h3>
                        <p class="text-slate-500 text-xs leading-relaxed max-w-[220px] mx-auto"><?= htmlspecialchars($step['desc']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- TRACK BANNER -->
    <section class="px-12 sm:px-24 md:px-32 lg:px-48 xl:px-64 2xl:px-72">
        <div class="track-banner py-6 md:py-7 max-w-4xl mx-auto">
            <div class="track-banner-inner px-4 sm:px-5">
                <div class="flex items-center gap-2 sm:gap-2.5 shrink-0">
                    <div class="w-11 h-11 sm:w-12 sm:h-12 rounded-full bg-white/10 flex items-center justify-center text-gold">
                        <i data-lucide="search" class="w-5 h-5 sm:w-6 sm:h-6"></i>
                    </div>
                    <h2 class="text-white font-black text-sm sm:text-base md:text-lg uppercase tracking-wider whitespace-nowrap">Track Your Request</h2>
                </div>
                <form id="home-track-form" class="flex flex-col sm:flex-row gap-2 flex-1 min-w-0">
                    <input type="text" id="home-track-input" placeholder="Enter Reference Number (e.g. ALCROS-2026-000123)"
                        class="flex-1 min-w-0 rounded-sm border-0 px-3 py-2.5 sm:py-3 text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-amber-400 uppercase">
                    <button type="submit" class="btn-gold px-6 sm:px-7 py-2.5 sm:py-3 rounded-sm shrink-0">Track</button>
                </form>
            </div>
        </div>
    </section>

    <!-- WHY CHOOSE -->
    <section class="py-16 md:py-20 bg-slate-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900 uppercase tracking-wide">Why Choose ALCROS?</h2>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <?php foreach ($whyItems as $item): ?>
                <div class="text-center">
                    <div class="w-14 h-14 rounded-full bg-[#071428] text-gold flex items-center justify-center mx-auto mb-4">
                        <i data-lucide="<?= htmlspecialchars($item['icon']) ?>" class="w-6 h-6"></i>
                    </div>
                    <h3 class="font-bold text-slate-900 text-sm mb-2"><?= htmlspecialchars($item['title']) ?></h3>
                    <p class="text-slate-500 text-xs leading-relaxed"><?= htmlspecialchars($item['desc']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- ANNOUNCEMENTS + ABOUT -->
    <section id="about" class="py-16 md:py-20 bg-white scroll-mt-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-8">
                <div class="border border-slate-200 rounded-lg overflow-hidden shadow-sm">
                    <div class="bg-[#071428] text-white px-5 py-3 font-bold text-sm uppercase tracking-wider">Latest Announcements</div>
                    <div class="p-5">
                        <article class="border-l-4 border-amber-400 pl-4">
                            <time class="text-[11px] font-bold text-slate-400 uppercase"><?= date('F j, Y') ?></time>
                            <h3 class="font-bold text-slate-900 text-sm mt-1 mb-2">Office Schedule</h3>
                            <p class="text-slate-600 text-xs leading-relaxed"><?= htmlspecialchars($site['hours']) ?>. Walk-in clients and online request claimants are served during these hours.</p>
                        </article>
                    </div>
                </div>
                <div class="border border-slate-200 rounded-lg overflow-hidden shadow-sm flex flex-col">
                    <div class="bg-[#071428] text-white px-5 py-3 font-bold text-sm uppercase tracking-wider">About ALCROS</div>
                    <div class="p-5 flex flex-col sm:flex-row gap-5 flex-1">
                        <img src="images/municipal-hall.jpg" alt="Aloran Municipal Hall" class="w-full sm:w-36 h-28 object-cover rounded-md shrink-0">
                        <div>
                            <p class="text-slate-600 text-xs leading-relaxed mb-3">
                                <strong class="text-slate-900">ALCROS</strong> (Aloran Local Civil Registry Online System) is the official digital portal of the <?= htmlspecialchars($site['office']) ?>.
                                It allows citizens to request civil registry documents and track applications online.
                            </p>
                            <p class="text-slate-500 text-xs leading-relaxed">
                                <?= htmlspecialchars($site['address']) ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section id="faqs" class="py-16 md:py-20 bg-slate-50 scroll-mt-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-12">
                <h2 class="text-2xl md:text-3xl font-black text-slate-900 uppercase tracking-wide">Frequently Asked Questions</h2>
            </div>
            <div class="grid md:grid-cols-2 gap-4 max-w-5xl mx-auto">
                <?php foreach ($faqs as $faq): ?>
                <details class="faq-item bg-white border border-slate-200 rounded-lg px-4 py-3 group">
                    <summary class="flex items-center justify-between gap-3 cursor-pointer py-2 font-semibold text-sm text-slate-800">
                        <span><?= htmlspecialchars($faq['q']) ?></span>
                        <i data-lucide="chevron-down" class="faq-chevron w-4 h-4 text-slate-400 shrink-0 transition-transform"></i>
                    </summary>
                    <p class="text-slate-500 text-xs leading-relaxed pb-3 pr-6"><?= htmlspecialchars($faq['a']) ?></p>
                </details>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer id="contact" class="footer-dark scroll-mt-24">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10">
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <?= alcrosFaviconImg(44, 'brand-logo shrink-0') ?>
                        <div>
                            <div class="text-white font-extrabold text-base">ALCROS</div>
                            <div class="text-white/60 text-[10px] italic">Aloran Local Civil Registry Online System</div>
                        </div>
                    </div>
                    <p class="text-xs leading-relaxed text-white/60">Municipality of Aloran, Misamis Occidental, Philippines</p>
                </div>
                <div>
                    <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4">Quick Links</h4>
                    <ul class="space-y-2 text-xs">
                        <li><a href="index.php" class="hover:text-gold transition">Home</a></li>
                        <li><a href="#services" class="hover:text-gold transition">Services</a></li>
                        <li><button type="button" data-open-track class="hover:text-gold transition bg-transparent border-0 p-0 cursor-pointer text-left text-white/75">Track Request</button></li>
                        <li><a href="services.php" class="hover:text-gold transition">All Services</a></li>
                        <li><a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="hover:text-gold transition">Staff Login</a></li>
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
                    <h4 class="text-white font-bold text-xs uppercase tracking-wider mb-4">Follow Us</h4>
                    <div class="flex gap-3">
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition" aria-label="Facebook">
                            <i data-lucide="facebook" class="w-4 h-4"></i>
                        </a>
                        <a href="#" class="w-9 h-9 rounded-full bg-white/10 flex items-center justify-center hover:bg-white/20 transition" aria-label="Twitter">
                            <i data-lucide="twitter" class="w-4 h-4"></i>
                        </a>
                    </div>
                    <p class="text-[11px] text-white/50 mt-4"><?= htmlspecialchars($site['hours']) ?></p>
                </div>
            </div>
        </div>
        <div class="border-t border-white/10">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-col sm:flex-row justify-between items-center gap-2 text-[11px] text-white/50">
                <span>&copy; <?= htmlspecialchars($year) ?> ALCROS. All Rights Reserved.</span>
                <div class="flex gap-4">
                    <button type="button" data-open-privacy class="hover:text-white transition bg-transparent border-0 p-0 cursor-pointer">Privacy Policy</button>
                    <span class="text-white/30">|</span>
                    <a href="privacy.php" class="hover:text-white transition">Terms of Use</a>
                </div>
            </div>
        </div>
    </footer>

    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= scriptTag('core/reminders.js') ?>
    <?= lucideInitScript() ?>
    <script>
    (function () {
        var toggle = document.getElementById('mobileNavToggle');
        var mobileNav = document.getElementById('mobileNav');
        if (toggle && mobileNav) {
            toggle.addEventListener('click', function () {
                mobileNav.classList.toggle('hidden');
                mobileNav.classList.toggle('is-open');
            });
        }

        var homeTrackForm = document.getElementById('home-track-form');
        var homeTrackInput = document.getElementById('home-track-input');
        if (homeTrackForm) {
            homeTrackForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var code = homeTrackInput ? homeTrackInput.value.trim() : '';
                if (window.AlcrosTrack) {
                    window.AlcrosTrack.open(code);
                }
            });
        }
    })();
    </script>
</body>
</html>
