<?php

/**

 * ALCROS - All civil registry services

 */

session_start();

require_once __DIR__ . '/includes/helpers.php';

require_once __DIR__ . '/includes/appointment_service_requirements.php';
require_once __DIR__ . '/includes/appointment_notice_requirements.php';

require_once __DIR__ . '/includes/scripts.php';



$site = getSiteSettings();

$documentTypes = getDocumentTypes();

$appointmentServices = getAppointmentServices();
$requirementsModalMeta = appointmentServiceRequirementsModalMeta();

$isStaffLoggedIn = isset($_SESSION['staff_id']);

$staffPortalUrl = $isStaffLoggedIn ? 'dashboard.php' : 'login.php';

$currentPage = basename($_SERVER['PHP_SELF']);

$year = date('Y');

?>

<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <?= faviconLinkTag() ?>

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>All Services - ALCROS</title>

    <?= vendorScriptTag('tailwindcss.js') ?>

    <?= vendorScriptTag('lucide.min.js') ?>

    <?= interFontTags() ?>

    <?= publicStylesheet('citizen-site') ?>

    <?= publicStylesheet('back-home') ?>

</head>

<body class="citizen-site">



    <header class="citizen-site-header">

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <div class="flex items-center justify-between gap-4 py-3">

                <a href="index.php" class="flex items-center gap-3 min-w-0">

                    <?= alcrosFaviconImg(48, 'citizen-brand-logo shrink-0') ?>

                    <div class="site-brand-text">
                        <div class="site-brand-title">ALCROS</div>
                        <div class="site-brand-subtitle">Aloran Local Civil Registry Online System</div>

                    </div>

                </a>



                <nav class="hidden lg:flex items-center gap-1 xl:gap-2">

                    <a href="index.php" class="citizen-nav-link">Home</a>

                    <a href="services.php" class="citizen-nav-link is-active">Services</a>

                    <button type="button" data-open-track class="citizen-nav-link cursor-pointer bg-transparent border-0 border-b-2 border-transparent">Track Request</button>

                    <a href="index.php#about" class="citizen-nav-link">About</a>

                    <a href="index.php#faqs" class="citizen-nav-link">FAQs</a>

                    <a href="index.php#contact" class="citizen-nav-link">Contact Us</a>

                </nav>



                <div class="flex items-center gap-2 shrink-0">

                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="citizen-btn-login hidden sm:inline-block">

                        <?= $isStaffLoggedIn ? 'Dashboard' : 'Login' ?>

                    </a>

                    <button type="button" id="citizenMobileNavToggle" class="lg:hidden p-2 text-white" aria-label="Open menu">

                        <i data-lucide="menu" class="w-6 h-6"></i>

                    </button>

                </div>

            </div>



            <div id="citizenMobileNav" class="hidden lg:hidden pb-4 border-t border-white/10 pt-3">

                <div class="flex flex-col gap-1">

                    <a href="index.php" class="citizen-nav-link">Home</a>

                    <a href="services.php" class="citizen-nav-link is-active">Services</a>

                    <button type="button" data-open-track class="citizen-nav-link text-left cursor-pointer bg-transparent border-0">Track Request</button>

                    <a href="index.php#about" class="citizen-nav-link">About</a>

                    <a href="index.php#faqs" class="citizen-nav-link">FAQs</a>

                    <a href="index.php#contact" class="citizen-nav-link">Contact Us</a>

                    <a href="<?= htmlspecialchars($staffPortalUrl) ?>" class="citizen-btn-login inline-block text-center mt-2 w-fit"><?= $isStaffLoggedIn ? 'Dashboard' : 'Login' ?></a>

                </div>

            </div>

        </div>

    </header>



    <main class="citizen-site-main">

        <section class="citizen-page-hero">

            <div class="max-w-6xl mx-auto">

                <a href="index.php" class="back-home back-home--inline is-centered">

                    <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>

                    <span>Back to Home</span>

                </a>

                <h1>All Civil Registry Services</h1>

                <p><?= renderOverviewText($site['overview'], $site['office']) ?></p>

            </div>

        </section>



        <section class="max-w-6xl mx-auto px-4 sm:px-6 pb-12">

            <p class="citizen-section-label">Fast-Track Online Services</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-16">

                <?php foreach ($documentTypes as $doc): ?>

                <div class="citizen-service-card p-8 text-center">

                    <div class="w-12 h-12 <?= $doc['iconBg'] ?> rounded-lg flex items-center justify-center mx-auto mb-4">

                        <i data-lucide="<?= htmlspecialchars($doc['icon']) ?>" class="w-5 h-5"></i>

                    </div>

                    <h3 class="font-bold text-sm mb-2 text-slate-900"><?= htmlspecialchars($doc['label']) ?></h3>

                    <p class="text-gray-500 text-[11px] leading-relaxed mb-3"><?= htmlspecialchars($doc['desc']) ?></p>

                    <?= renderOfficeFeesCardHtml(getDocumentCertificateFeeNotes()) ?>

                    <a href="request.php?type=<?= urlencode($doc['slug']) ?>" class="citizen-link-gold">

                        Apply Now <i data-lucide="chevron-right" class="w-3 h-3"></i>

                    </a>

                </div>

                <?php endforeach; ?>

            </div>



            <p class="citizen-section-label mb-2">Special Services & Consultations</p>

            <p class="text-white/65 text-xs mb-8 italic">Schedule an appointment for record updates and civil registry consultations.</p>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 special-services-grid">

                <?php foreach ($appointmentServices as $svc):
                    $reqMeta = getAppointmentServiceRequirements($svc['slug'], $svc['label']);
                    $scheduleUrl = 'book_appointment.php?service=' . rawurlencode($svc['slug']);
                ?>

                <div class="citizen-service-card citizen-service-card--appointment p-6 flex flex-col items-start text-left relative<?= $svc['slug'] === 'request-psa-documents' ? ' citizen-service-card--psa-center' : '' ?>">

                    <span class="absolute top-4 right-4 text-[9px] font-bold text-amber-700 border border-amber-200 bg-amber-50 px-2 py-0.5 rounded uppercase">Appointment</span>

                    <div class="w-8 h-8 <?= $svc['iconBg'] ?> rounded flex items-center justify-center mb-4">

                        <i data-lucide="<?= htmlspecialchars($svc['icon']) ?>" class="w-4 h-4"></i>

                    </div>

                    <h4 class="font-bold text-xs mb-1 uppercase text-slate-900"><?= htmlspecialchars($svc['label']) ?></h4>

                    <p class="text-gray-500 text-[10px] mb-3 flex-1"><?= htmlspecialchars($svc['desc']) ?></p>

                    <?= renderAppointmentServiceFeesCardHtml($svc['slug'], $svc['label']) ?>

                    <div class="citizen-service-card__actions w-full pt-3 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-3">

                        <a href="<?= htmlspecialchars($scheduleUrl) ?>" class="citizen-service-card__btn citizen-service-card__btn--primary">

                            Schedule Appointment <i data-lucide="calendar-plus" class="w-3.5 h-3.5"></i>

                        </a>

                        <button type="button"
                                class="citizen-service-card__btn citizen-service-card__btn--secondary"
                                data-open-service-requirements="<?= htmlspecialchars($svc['slug'], ENT_QUOTES) ?>"
                                data-req-title="<?= htmlspecialchars($reqMeta['title'], ENT_QUOTES) ?>"
                                data-req-subtitle="<?= htmlspecialchars($reqMeta['subtitle'], ENT_QUOTES) ?>"
                                data-schedule-url="<?= htmlspecialchars($scheduleUrl, ENT_QUOTES) ?>">
                            Requirements <i data-lucide="clipboard-list" class="w-3.5 h-3.5"></i>
                        </button>

                    </div>

                </div>

                <?php endforeach; ?>

            </div>

        </section>



        <section class="max-w-4xl mx-auto px-6 pb-16">

            <div class="citizen-help-card p-8 text-center">

                <h2 class="text-lg font-black text-slate-900 mb-2">Need Help Choosing a Service?</h2>

                <p class="text-gray-500 text-sm mb-6">Visit the <?= htmlspecialchars($site['office']) ?> during office hours or contact us directly.</p>

                <div class="flex flex-wrap justify-center gap-4 text-xs text-gray-500">

                    <span class="flex items-center gap-1.5"><i data-lucide="clock" class="w-3.5 h-3.5 text-amber-500"></i> <?= htmlspecialchars($site['hours']) ?></span>

                    <span class="flex items-center gap-1.5"><i data-lucide="phone" class="w-3.5 h-3.5 text-amber-500"></i> <?= htmlspecialchars($site['phone']) ?></span>

                    <span class="flex items-center gap-1.5"><i data-lucide="mail" class="w-3.5 h-3.5 text-amber-500"></i> <?= htmlspecialchars($site['email']) ?></span>

                </div>

            </div>

        </section>



        <footer class="citizen-footer py-6 px-6 sm:px-12 text-[10px]">

            <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-4">

                <div class="flex flex-col sm:flex-row sm:items-center gap-4 sm:gap-6 text-center sm:text-left">

                    <div class="flex items-center justify-center gap-2">

                        <?= alcrosFaviconImg(20, 'citizen-brand-logo w-5 h-5') ?>

                        <span class="font-bold text-white text-[11px]"><?= htmlspecialchars($site['name']) ?></span>

                    </div>

                    <span class="text-white/50">&copy; <?= htmlspecialchars($year) ?> Aloran Civil Registry Office. All rights reserved.</span>

                </div>

                <div class="flex gap-4">

                    <a href="index.php">Home</a>

                    <button type="button" data-open-track class="bg-transparent border-0 p-0 cursor-pointer">Track</button>

                    <a href="privacy.php">Privacy & Safety</a>

                </div>

            </div>

        </footer>

    </main>



    <script type="application/json" id="serviceRequirementsMeta"><?= json_encode($requirementsModalMeta, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

    <?php foreach ($appointmentServices as $svc): ?>
    <template id="svc-req-<?= htmlspecialchars($svc['slug'], ENT_QUOTES) ?>">
        <?= renderAppointmentServiceRequirementsHtml($svc['slug']) ?>
    </template>
    <?php endforeach; ?>

    <div id="serviceRequirementsModal" class="svc-req-modal hidden" aria-hidden="true">
        <div id="serviceRequirementsBackdrop" class="svc-req-modal__backdrop" tabindex="-1" aria-hidden="true"></div>
        <div class="svc-req-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="serviceRequirementsTitle" aria-describedby="serviceRequirementsBody">
            <div class="svc-req-modal__header">
                <div class="svc-req-modal__header-icon" aria-hidden="true">
                    <i data-lucide="clipboard-list" class="w-5 h-5"></i>
                </div>
                <div class="svc-req-modal__header-text min-w-0">
                    <p id="serviceRequirementsSubtitle" class="svc-req-modal__kicker hidden"></p>
                    <h2 id="serviceRequirementsTitle" class="svc-req-modal__title"></h2>
                </div>
                <button type="button" id="serviceRequirementsClose" class="svc-req-modal__close" aria-label="Close">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div id="serviceRequirementsBody" class="svc-req-modal__body"></div>
            <div class="svc-req-modal__footer">
                <p class="svc-req-modal__hint">Prepare these documents before your visit. Originals may be requested for verification.</p>
                <div class="svc-req-modal__footer-actions">
                    <button type="button" id="serviceRequirementsDismiss" class="svc-req-modal__btn svc-req-modal__btn--ghost">Close</button>
                    <a id="serviceRequirementsSchedule" href="book_appointment.php" class="svc-req-modal__btn svc-req-modal__btn--primary">
                        Schedule Appointment <i data-lucide="arrow-right" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php require __DIR__ . '/includes/track_floating.php'; ?>

    <?= scriptTag('public/track-floating.js') ?>

    <?= scriptTag('public/service-requirements-modal.js') ?>

    <?= scriptTag('public/citizen-site.js') ?>

    <?php require __DIR__ . '/includes/maintenance_announcement.php'; ?>

    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>

    <?php require __DIR__ . '/includes/notification_consent.php'; ?>

    <?= lucideInitScript() ?>

</body>

</html>

