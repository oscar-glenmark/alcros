<?php
/**
 * ALCROS - Local Civil Registry of Aloran
 * Landing page (index.php)
 *
 * Functional additions (design untouched):
 *  - Session-aware "Staff Portal" button (routes to dashboard.php if logged in, login.php if not)
 *  - Active-page highlighting in the nav bar
 *  - Document request buttons wired to request.php with the document type pre-filled
 *  - "Schedule Appointment" links wired to appointment.php with the service type pre-filled
 *  - Dynamic copyright year
 *  - Contact details pulled from a single config array so they only need to be edited once
 */

session_start();
require_once __DIR__ . '/includes/helpers.php';

// ---- Simple config you can edit in one place ----------------------------
$site = getSiteSettings();
$maintenanceMode = isMaintenanceMode();
$publicRequestsAllowed = arePublicRequestsAllowed();

// ---- Staff login state ----------------------------------------------------
$isStaffLoggedIn = isset($_SESSION['staff_id']);
// Staff Portal always goes to login.php. If the staff member is already
// logged in, login.php itself detects the session and redirects them
// straight to dashboard.php — so this link never needs to branch here.
$staffPortalUrl  = 'login.php';

// ---- Nav helper: highlight the current page --------------------------------
$currentPage = basename($_SERVER['PHP_SELF']);
function navClass($page, $current) {
    return $page === $current
        ? 'text-blue-600 font-semibold'
        : 'hover:text-blue-600';
}

// ---- Document types offered (drives the top request cards) ----------------
$documentTypes = getDocumentTypes();

// ---- Special / appointment-based services ----------------------------------
$appointmentServices = getAppointmentServices();

$year = date('Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($site['name']) ?> - Efficient Civil Registry</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .gradient-text {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-900">

    <?php if ($maintenanceMode): ?>
    <div class="bg-amber-500 text-white text-center text-xs font-bold py-2 px-4">
        The citizen portal is currently under maintenance. Online requests may be temporarily unavailable.
    </div>
    <?php endif; ?>

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

    <section class="text-center py-20 px-4">
        <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-4"><?= htmlspecialchars($site['office']) ?></p>
        <h1 class="text-5xl font-extrabold text-slate-900 mb-2">Efficient Civil Registry</h1>
        <h2 class="text-5xl font-extrabold gradient-text mb-6">Rightsizing Public Service</h2>
        <p class="text-gray-500 max-w-lg mx-auto mb-10 text-sm">
            Request, track, and receive your vital documents with 100% digital transparency and speed.
        </p>
        <div class="flex justify-center gap-4">
            <?php if ($publicRequestsAllowed && !$maintenanceMode): ?>
            <a href="request.php" class="bg-blue-600 text-white px-8 py-3 rounded-full font-semibold text-sm flex items-center gap-2 hover:bg-blue-700 transition">
                Request Now <i data-lucide="arrow-right" class="w-3 h-3"></i>
            </a>
            <?php else: ?>
            <span class="bg-gray-300 text-gray-500 px-8 py-3 rounded-full font-semibold text-sm cursor-not-allowed">Requests Unavailable</span>
            <?php endif; ?>
            <a href="track.php" class="border border-gray-300 text-gray-700 px-8 py-3 rounded-full font-semibold text-sm hover:bg-gray-50 transition">
                Track Status
            </a>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-6 py-12">
        <p class="text-[10px] uppercase tracking-widest text-blue-600 font-bold mb-6 text-center">Fast-Track Online Services</p>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($documentTypes as $doc): ?>
            <a href="request.php?type=<?= urlencode($doc['slug']) ?>"
               class="bg-white p-8 rounded-2xl shadow-sm border border-gray-100 text-center hover:shadow-md transition block">
                <div class="w-12 h-12 <?= $doc['iconBg'] ?> rounded-lg flex items-center justify-center mx-auto mb-4 text-xl">
                    <i data-lucide="<?= htmlspecialchars($doc['icon']) ?>" class="w-5 h-5"></i>
                </div>
                <h3 class="font-bold text-sm mb-2"><?= htmlspecialchars($doc['label']) ?></h3>
                <p class="text-gray-400 text-[11px] leading-relaxed"><?= htmlspecialchars($doc['desc']) ?></p>
            </a>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="max-w-6xl mx-auto px-6 py-12 text-center">
        <h3 class="font-bold text-lg mb-1">Special Services & Consultations</h3>
        <p class="text-gray-400 text-xs mb-10 italic">"Schedule an appointment for record updates and civil registry consultations."</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <?php foreach ($appointmentServices as $svc): ?>
            <div class="bg-white p-6 rounded-xl border border-gray-100 flex flex-col items-start text-left relative">
                <span class="absolute top-4 right-4 text-[9px] font-bold text-orange-400 border border-orange-200 px-2 py-0.5 rounded uppercase">Appointment</span>
                <div class="w-8 h-8 <?= $svc['iconBg'] ?> rounded flex items-center justify-center mb-4">
                    <i data-lucide="<?= htmlspecialchars($svc['icon']) ?>" class="w-4 h-4"></i>
                </div>
                <h4 class="font-bold text-xs mb-1 uppercase"><?= htmlspecialchars($svc['label']) ?></h4>
                <p class="text-gray-400 text-[10px] mb-4"><?= htmlspecialchars($svc['desc']) ?></p>
                <a href="book_appointment.php?service=<?= urlencode($svc['slug']) ?>" class="text-blue-600 text-[10px] font-bold border-b border-blue-600 pb-0.5 inline-flex items-center gap-0.5">SCHEDULE APPOINTMENT <i data-lucide="chevron-right" class="w-3 h-3"></i></a>
            </div>
            <?php endforeach; ?>
        </div>
        <a href="services.php" class="border border-gray-300 text-gray-600 px-6 py-2 rounded-lg text-xs font-medium hover:bg-gray-50 inline-flex items-center gap-1">View More Services <i data-lucide="chevron-right" class="w-3 h-3"></i></a>
    </section>

    <section class="max-w-6xl mx-auto px-6 py-20 text-center">
        <h3 class="text-2xl font-extrabold text-slate-800 mb-2">A System You Can Trust</h3>
        <p class="text-gray-400 text-xs mb-12">We prioritize your privacy and security. Our system is designed to provide official and <br>verified civil registry documents with complete transparency.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-12 px-12">
            <div class="text-center">
                <div class="w-10 h-10 bg-blue-50 text-blue-500 rounded-lg flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="search" class="w-4 h-4"></i>
                </div>
                <h4 class="font-bold text-xs mb-2">Real-Time Tracking</h4>
                <p class="text-gray-400 text-[10px] leading-relaxed">Monitor your request status from submission to pickup with a unique code.</p>
            </div>
            <div class="text-center">
                <div class="w-10 h-10 bg-green-50 text-green-500 rounded-lg flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="shield-check" class="w-4 h-4"></i>
                </div>
                <h4 class="font-bold text-xs mb-2">Secure Verification</h4>
                <p class="text-gray-400 text-[10px] leading-relaxed">Verified through official channels ensuring authenticity. Compliant with Data Privacy Act.</p>
            </div>
            <div class="text-center">
                <div class="w-10 h-10 bg-purple-50 text-purple-500 rounded-lg flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="hand-heart" class="w-4 h-4"></i>
                </div>
                <h4 class="font-bold text-xs mb-2">Priority Service</h4>
                <p class="text-gray-400 text-[10px] leading-relaxed">Special priority queues for Senior Citizens and PWDs for inclusive service.</p>
            </div>
        </div>
    </section>

    <section class="max-w-4xl mx-auto px-6 mb-20">
        <div class="bg-[#0f172a] rounded-3xl p-12 text-white relative overflow-hidden">
            <div class="relative z-10 text-center">
                <h2 class="text-3xl font-bold mb-10">Contact Us</h2>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
                    <div>
                        <i data-lucide="map-pin" class="w-4 h-4 text-blue-400 mb-3 mx-auto"></i>
                        <h5 class="text-[10px] font-bold text-gray-400 uppercase mb-1">Location</h5>
                        <p class="text-xs"><?= htmlspecialchars($site['address']) ?></p>
                    </div>
                    <div>
                        <i data-lucide="phone" class="w-4 h-4 text-blue-400 mb-3 mx-auto"></i>
                        <h5 class="text-[10px] font-bold text-gray-400 uppercase mb-1">Phone</h5>
                        <p class="text-xs">
                            <a href="tel:<?= htmlspecialchars($site['phone']) ?>" class="hover:text-blue-300"><?= htmlspecialchars($site['phone']) ?></a>
                        </p>
                    </div>
                    <div>
                        <i data-lucide="mail" class="w-4 h-4 text-blue-400 mb-3 mx-auto"></i>
                        <h5 class="text-[10px] font-bold text-gray-400 uppercase mb-1">Email</h5>
                        <p class="text-xs">
                            <a href="mailto:<?= htmlspecialchars($site['email']) ?>" class="hover:text-blue-300"><?= htmlspecialchars($site['email']) ?></a>
                        </p>
                    </div>
                </div>
            </div>
            <i data-lucide="phone" class="absolute -right-10 -bottom-10 text-gray-800 w-40 h-40 opacity-20 rotate-12"></i>
        </div>
    </section>

    <footer class="bg-[#0b1120] text-gray-500 py-6 px-12 text-[10px] flex justify-between items-center border-t border-gray-800">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-1">
                <div class="bg-blue-600 text-white p-0.5 rounded text-[8px] font-bold">A</div>
                <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>
            </div>
            <span>&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>
        </div>
        <div class="flex gap-4">
            <a href="track.php" class="hover:text-white">Track</a>
            <a href="kiosk.php" class="hover:text-white">Kiosk</a>
            <a href="queue_display.php" class="hover:text-white">Queue Display</a>
            <a href="privacy.php" class="hover:text-white">Privacy & Safety</a>
            <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="hover:text-white">Staff</a>
        </div>
    </footer>

    <script>
        lucide.createIcons();
    </script>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
</body>
</html> 