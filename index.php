<?php
/**
 * ALCROS - Local Civil Registry of Aloran
 * Landing page (index.php)
 */
session_start();
require_once __DIR__ . '/includes/helpers.php';

$site = getSiteSettings();
$maintenanceMode = isMaintenanceMode();
$publicRequestsAllowed = arePublicRequestsAllowed();

$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl  = 'login.php';

$currentPage = basename($_SERVER['PHP_SELF']);
function navClass($page, $current) {
    return $page === $current
        ? 'nav-link nav-link-active'
        : 'nav-link';
}

$documentTypes = getDocumentTypes();
$appointmentServices = getAppointmentServices();
$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site['name']) ?> - Efficient Civil Registry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; }
        .gradient-text {
            background: linear-gradient(135deg, #1d4ed8, #0ea5e9);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .hero-glow {
            background:
                radial-gradient(circle at 20% 20%, rgba(59, 130, 246, 0.12), transparent 45%),
                radial-gradient(circle at 80% 0%, rgba(14, 165, 233, 0.1), transparent 40%),
                linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
        }
        .site-header {
            transition: box-shadow 0.25s ease, border-color 0.25s ease;
        }
        .site-header:hover {
            box-shadow: 0 4px 24px rgba(37, 99, 235, 0.08);
            border-color: rgba(191, 219, 254, 0.9);
        }
        .brand-link {
            transition: transform 0.2s ease, opacity 0.2s ease;
        }
        .brand-link:hover {
            transform: translateY(-1px);
        }
        .brand-logo {
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }
        .brand-link:hover .brand-logo {
            transform: scale(1.06) rotate(-2deg);
            box-shadow: 0 8px 20px rgba(37, 99, 235, 0.35);
        }
        .brand-link:hover .brand-title {
            color: #1d4ed8;
        }
        .brand-title {
            transition: color 0.2s ease;
        }
        .nav-link {
            position: relative;
            color: #475569;
            font-weight: 500;
            padding: 0.5rem 0.875rem;
            border-radius: 0.625rem;
            transition: color 0.2s ease, background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            left: 0.875rem;
            right: 0.875rem;
            bottom: 0.35rem;
            height: 2px;
            border-radius: 999px;
            background: linear-gradient(90deg, #1d4ed8, #0ea5e9);
            transform: scaleX(0);
            transform-origin: center;
            transition: transform 0.25s ease;
        }
        .nav-link:hover {
            color: #1d4ed8;
            background-color: rgba(239, 246, 255, 0.9);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.1);
        }
        .nav-link:hover::after {
            transform: scaleX(1);
        }
        .nav-link-active {
            color: #1d4ed8;
            font-weight: 600;
            background-color: #eff6ff;
            box-shadow: inset 0 0 0 1px rgba(191, 219, 254, 0.8);
        }
        .nav-link-active::after {
            transform: scaleX(1);
        }
        .nav-staff-btn {
            transition: transform 0.2s ease, box-shadow 0.25s ease, opacity 0.2s ease;
        }
        .nav-staff-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(37, 99, 235, 0.35);
            opacity: 1;
        }
        .nav-staff-btn:active {
            transform: translateY(0);
        }
        .nav-mobile-btn {
            transition: transform 0.2s ease, background-color 0.2s ease, box-shadow 0.2s ease, color 0.2s ease;
        }
        .nav-mobile-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
        }
        .nav-mobile-btn:active {
            transform: scale(0.97);
        }
        .nav-mobile-btn-light:hover {
            background-color: #dbeafe;
            color: #1d4ed8;
        }
        .nav-mobile-btn-dark:hover {
            background-color: #1e40af;
            box-shadow: 0 6px 16px rgba(29, 78, 216, 0.4);
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-900">

    <?php if ($maintenanceMode): ?>
    <div class="bg-amber-500 text-white text-center text-xs font-bold py-2 px-4">
        The citizen portal is currently under maintenance. Online requests may be temporarily unavailable.
    </div>
    <?php endif; ?>

    <header class="site-header sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200/80">
        <nav class="max-w-6xl mx-auto flex items-center justify-between px-4 sm:px-6 py-3">
            <a href="index.php" class="brand-link group flex items-center gap-3 rounded-xl pr-2 -ml-1 py-1">
                <div class="brand-logo flex items-center justify-center w-9 h-9 bg-gradient-to-br from-blue-700 to-sky-600 rounded-xl shadow-md shadow-blue-200/70">
                    <span class="text-white text-sm font-black">A</span>
                </div>
                <div class="flex flex-col leading-none">
                    <span class="brand-title font-black text-base tracking-tight text-slate-900"><?= htmlspecialchars($site['name']) ?></span>
                    <span class="text-[9px] font-bold text-sky-600 tracking-widest uppercase mt-1 group-hover:text-blue-500 transition-colors duration-200">Civil Registry Portal</span>
                </div>
            </a>

            <div class="hidden md:flex items-center gap-1 text-sm font-medium">
                <a href="index.php" class="<?= navClass('index.php', $currentPage) ?>">Home</a>
                <button type="button" data-open-track class="nav-link cursor-pointer border-0 bg-transparent">Track</button>
                <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="nav-staff-btn ml-2 bg-gradient-to-r from-blue-700 to-sky-600 text-white px-4 py-2 rounded-lg text-xs font-bold shadow-sm shadow-blue-200">
                    <?= $isStaffLoggedIn ? 'Staff Dashboard' : 'Staff Portal' ?>
                </a>
            </div>

            <div class="md:hidden flex items-center gap-2">
                <a href="index.php" class="nav-mobile-btn nav-mobile-btn-light text-xs font-semibold text-blue-700 px-3 py-2 rounded-lg bg-blue-50">Home</a>
                <button type="button" data-open-track class="nav-mobile-btn nav-mobile-btn-light text-xs font-semibold text-blue-700 px-3 py-2 rounded-lg bg-blue-50">Track</button>
                <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="nav-mobile-btn nav-mobile-btn-dark text-xs font-bold text-white px-3 py-2 rounded-lg bg-blue-700">Staff</a>
            </div>
        </nav>
    </header>

    <section class="hero-glow border-b border-slate-100">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-16 md:py-20 text-center">
            <p class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-blue-50 text-blue-700 text-[10px] font-bold uppercase tracking-wider mb-5">
                <i data-lucide="building-2" class="w-3.5 h-3.5"></i>
                <?= htmlspecialchars($site['office']) ?>
            </p>
            <h1 class="text-4xl md:text-5xl font-extrabold text-slate-900 mb-2 leading-tight">Efficient Civil Registry</h1>
            <h2 class="text-4xl md:text-5xl font-extrabold gradient-text mb-5 leading-tight">Rightsizing Public Service</h2>
            <p class="text-slate-500 max-w-xl mx-auto mb-8 text-sm md:text-base leading-relaxed">
                Request, track, and receive your vital documents online fast, transparent, and secure.
            </p>

            <div class="flex flex-col sm:flex-row justify-center gap-3">
                <?php if ($publicRequestsAllowed && !$maintenanceMode): ?>
                <a href="request.php" class="bg-gradient-to-r from-blue-700 to-sky-600 text-white px-8 py-3.5 rounded-xl font-semibold text-sm inline-flex items-center justify-center gap-2 hover:opacity-95 transition shadow-lg shadow-blue-200/60">
                    Start a Request <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
                <?php else: ?>
                <span class="bg-slate-200 text-slate-500 px-8 py-3.5 rounded-xl font-semibold text-sm cursor-not-allowed">Requests Unavailable</span>
                <?php endif; ?>
                <button type="button" data-open-track class="bg-white border border-slate-200 text-slate-700 px-8 py-3.5 rounded-xl font-semibold text-sm inline-flex items-center justify-center gap-2 hover:border-blue-200 hover:text-blue-700 transition shadow-sm">
                    <i data-lucide="search" class="w-4 h-4"></i> Track My Request
                </button>
            </div>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-4 sm:px-6 py-10">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 md:p-8 shadow-sm">
            <div class="text-center mb-8">
                <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-2">Simple Process</p>
                <h3 class="text-xl font-extrabold text-slate-900">How It Works</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center md:text-left flex flex-col md:flex-row items-center md:items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-blue-600 text-white font-black text-sm flex items-center justify-center shrink-0">1</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-800 mb-1">Submit Online</h4>
                        <p class="text-slate-500 text-xs leading-relaxed">Fill out the request form and upload required documents.</p>
                    </div>
                </div>
                <div class="text-center md:text-left flex flex-col md:flex-row items-center md:items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-sky-500 text-white font-black text-sm flex items-center justify-center shrink-0">2</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-800 mb-1">Track Progress</h4>
                        <p class="text-slate-500 text-xs leading-relaxed">Use your tracking code to monitor status in real time.</p>
                    </div>
                </div>
                <div class="text-center md:text-left flex flex-col md:flex-row items-center md:items-start gap-4">
                    <div class="w-10 h-10 rounded-full bg-emerald-500 text-white font-black text-sm flex items-center justify-center shrink-0">3</div>
                    <div>
                        <h4 class="font-bold text-sm text-slate-800 mb-1">Pick Up Document</h4>
                        <p class="text-slate-500 text-xs leading-relaxed">Get notified when your document is ready for release.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section id="services" class="max-w-6xl mx-auto px-4 sm:px-6 py-12 scroll-mt-24">
        <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 mb-8">
            <div>
                <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-2">Online Requests</p>
                <h3 class="text-2xl font-extrabold text-slate-900">Fast-Track Document Services</h3>
                <p class="text-slate-500 text-sm mt-1">Select a document type to begin your application.</p>
            </div>
            <a href="services.php" class="text-xs font-bold text-blue-700 inline-flex items-center gap-1 hover:underline shrink-0">
                View all services <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
            </a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <?php foreach ($documentTypes as $doc): ?>
            <a href="request.php?type=<?= urlencode($doc['slug']) ?>"
               class="group bg-white p-6 rounded-2xl border border-slate-200 hover:border-blue-200 hover:shadow-md transition flex flex-col">
                <div class="w-11 h-11 bg-blue-50 text-blue-600 group-hover:bg-blue-600 group-hover:text-white rounded-xl flex items-center justify-center mb-4 transition-colors">
                    <i data-lucide="<?= htmlspecialchars($doc['icon']) ?>" class="w-5 h-5"></i>
                </div>
                <h4 class="font-bold text-sm text-slate-900 mb-2"><?= htmlspecialchars($doc['label']) ?></h4>
                <p class="text-slate-500 text-xs leading-relaxed mb-4 flex-1"><?= htmlspecialchars($doc['desc']) ?></p>
                <span class="text-[10px] font-bold text-blue-700 inline-flex items-center gap-1 group-hover:gap-2 transition-all">
                    Apply now <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section id="appointments" class="bg-white border-y border-slate-200 scroll-mt-24">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 py-12">
            <div class="text-center mb-8">
                <p class="text-[10px] uppercase tracking-widest text-sky-600 font-bold mb-2">By Appointment</p>
                <h3 class="text-2xl font-extrabold text-slate-900">Special Services & Consultations</h3>
                <p class="text-slate-500 text-sm mt-2 max-w-lg mx-auto">Schedule a visit for record updates, consultations, and other civil registry services.</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php foreach ($appointmentServices as $svc): ?>
                <div class="bg-slate-50 p-6 rounded-2xl border border-slate-200 flex flex-col relative hover:border-sky-200 hover:shadow-sm transition">
                    <span class="absolute top-4 right-4 text-[9px] font-bold text-amber-700 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full uppercase">Appointment</span>
                    <div class="w-10 h-10 bg-sky-50 text-sky-600 rounded-xl flex items-center justify-center mb-4">
                        <i data-lucide="<?= htmlspecialchars($svc['icon']) ?>" class="w-4 h-4"></i>
                    </div>
                    <h4 class="font-bold text-sm text-slate-900 mb-1"><?= htmlspecialchars($svc['label']) ?></h4>
                    <p class="text-slate-500 text-xs mb-5 flex-1"><?= htmlspecialchars($svc['desc']) ?></p>
                    <a href="book_appointment.php?service=<?= urlencode($svc['slug']) ?>" class="text-sky-700 text-[10px] font-bold inline-flex items-center gap-1 hover:gap-2 transition-all">
                        Schedule appointment <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <section id="trust" class="max-w-6xl mx-auto px-4 sm:px-6 py-16 scroll-mt-24">
        <div class="text-center mb-10">
            <p class="text-[10px] uppercase tracking-widest text-emerald-600 font-bold mb-2">Why ALCROS</p>
            <h3 class="text-2xl font-extrabold text-slate-900 mb-2">A System You Can Trust</h3>
            <p class="text-slate-500 text-sm max-w-xl mx-auto">Official, verified civil registry services with privacy and transparency at the core.</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-center hover:shadow-sm transition">
                <div class="w-11 h-11 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="search" class="w-5 h-5"></i>
                </div>
                <h4 class="font-bold text-sm text-slate-900 mb-2">Real-Time Tracking</h4>
                <p class="text-slate-500 text-xs leading-relaxed">Monitor your request from submission to pickup with a unique tracking code.</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-center hover:shadow-sm transition">
                <div class="w-11 h-11 bg-emerald-50 text-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>
                <h4 class="font-bold text-sm text-slate-900 mb-2">Secure & Compliant</h4>
                <p class="text-slate-500 text-xs leading-relaxed">Verified through official channels. Compliant with the Data Privacy Act (RA 10173).</p>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 p-6 text-center hover:shadow-sm transition">
                <div class="w-11 h-11 bg-violet-50 text-violet-600 rounded-xl flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="hand-heart" class="w-5 h-5"></i>
                </div>
                <h4 class="font-bold text-sm text-slate-900 mb-2">Inclusive Service</h4>
                <p class="text-slate-500 text-xs leading-relaxed">Priority queues for Senior Citizens and PWDs for fair, accessible public service.</p>
            </div>
        </div>
    </section>

    <section id="contact" class="max-w-6xl mx-auto px-4 sm:px-6 pb-16 scroll-mt-24">
        <div class="bg-gradient-to-br from-slate-900 via-slate-800 to-blue-950 rounded-3xl p-8 md:p-12 text-white relative overflow-hidden shadow-xl">
            <div class="absolute -right-16 -bottom-16 w-56 h-56 bg-blue-500/10 rounded-full blur-2xl"></div>
            <div class="relative z-10">
                <div class="text-center mb-10">
                    <p class="text-[10px] uppercase tracking-widest text-sky-300 font-bold mb-2">Get In Touch</p>
                    <h2 class="text-2xl md:text-3xl font-extrabold">Contact Our Office</h2>
                    <p class="text-slate-300 text-sm mt-2"><?= htmlspecialchars($site['hours']) ?></p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-5 text-center">
                        <div class="w-10 h-10 bg-sky-500/20 text-sky-300 rounded-xl flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="map-pin" class="w-4 h-4"></i>
                        </div>
                        <h5 class="text-[10px] font-bold text-slate-400 uppercase mb-1">Location</h5>
                        <p class="text-xs text-slate-100 leading-relaxed"><?= htmlspecialchars($site['address']) ?></p>
                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-5 text-center">
                        <div class="w-10 h-10 bg-sky-500/20 text-sky-300 rounded-xl flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="phone" class="w-4 h-4"></i>
                        </div>
                        <h5 class="text-[10px] font-bold text-slate-400 uppercase mb-1">Phone</h5>
                        <a href="tel:<?= htmlspecialchars($site['phone']) ?>" class="text-sm text-sky-300 hover:text-white transition"><?= htmlspecialchars($site['phone']) ?></a>
                    </div>
                    <div class="bg-white/5 border border-white/10 rounded-2xl p-5 text-center">
                        <div class="w-10 h-10 bg-sky-500/20 text-sky-300 rounded-xl flex items-center justify-center mx-auto mb-3">
                            <i data-lucide="mail" class="w-4 h-4"></i>
                        </div>
                        <h5 class="text-[10px] font-bold text-slate-400 uppercase mb-1">Email</h5>
                        <a href="mailto:<?= htmlspecialchars($site['email']) ?>" class="text-sm text-sky-300 hover:text-white transition break-all"><?= htmlspecialchars($site['email']) ?></a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <footer class="bg-slate-950 text-slate-400 py-6 px-4 sm:px-12 text-[10px] flex flex-wrap justify-between items-center gap-4 border-t border-slate-800">
        <div class="flex flex-wrap items-center gap-6">
            <a href="index.php" class="group flex items-center gap-2.5 transition hover:opacity-90">
                <div class="flex items-center justify-center w-7 h-7 bg-gradient-to-br from-blue-600 to-sky-500 rounded-lg shadow-md shadow-blue-900/40 shrink-0">
                    <span class="text-white text-[10px] font-black">A</span>
                </div>
                <div class="flex flex-col leading-none">
                    <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>
                    <span class="text-[8px] font-semibold text-sky-400 tracking-wider uppercase mt-0.5">Civil Registry Portal</span>
                </div>
            </a>
            <span>&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>
        </div>
        <div class="flex flex-wrap gap-4">
            <button type="button" data-open-track class="hover:text-white transition bg-transparent border-0 p-0 cursor-pointer">Track</button>
            <button type="button" data-open-privacy class="hover:text-white transition">Privacy &amp; Safety</button>
            <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="hover:text-white transition">Staff</a>
        </div>
    </footer>

    <script>lucide.createIcons();</script>
    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <script src="includes/track_floating.js"></script>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <script src="includes/reminders.js"></script>
</body>
</html>
