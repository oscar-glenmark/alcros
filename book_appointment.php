<?php
/**
 * Citizen appointment booking — saves to MySQL appointments table.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$isStaffLoggedIn = isset($_SESSION['staff_id']);
$staffPortalUrl = $isStaffLoggedIn ? 'dashboard.php' : 'login.php';
$currentPage = basename($_SERVER['PHP_SELF']);

$service = $_GET['service'] ?? $_POST['service'] ?? '';
$serviceLabel = appointmentServiceLabel($service);
$success = null;
$error = null;
$appointmentCode = null;

$nameParts = personNamePartsFromInput($_POST);
$firstName = $nameParts['first_name'];
$middleName = $nameParts['middle_name'];
$lastName = $nameParts['last_name'];
$citizenName = formatPersonName($firstName, $middleName, $lastName);
$email       = normalizeGmail(trim($_POST['email'] ?? ''));
$phone       = trim($_POST['phone'] ?? '');
$serviceType = appointmentServiceLabel(trim($_POST['service_type'] ?? $service));
$date        = $_POST['appointment_date'] ?? '';
$time        = $_POST['appointment_time'] ?? '';
$notifyEmail = isset($_POST['notify_email']) && (string) $_POST['notify_email'] === '1';
$notifySms   = isset($_POST['notify_sms']) && (string) $_POST['notify_sms'] === '1';
$gmailVerified = isGmailVerifiedInSession($email);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePublicPostCsrf();
    if (($nameError = validatePersonNameParts($nameParts)) !== null || $serviceType === '' || $date === '' || $time === '') {
        $error = $nameError ?? 'Please complete all required fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Please choose a valid appointment date.';
    } elseif ($date < date('Y-m-d')) {
        $error = 'Appointment date cannot be in the past.';
    } elseif (!isValidGmail($email)) {
        $error = 'Please enter a valid Gmail address (example@gmail.com).';
    } elseif (!isGmailVerifiedInSession($email)) {
        $error = 'Please verify your Gmail is an active Google account before continuing.';
        $gmailVerified = false;
    } elseif (!isValidPhilippineMobile($phone)) {
        $error = 'Please enter a valid cellphone number (09XXXXXXXXX).';
    } elseif (empty($_FILES['id_front']['name'])) {
        $error = 'Please upload the front side of your valid ID.';
    } else {
        $frontPath = saveIdUpload($_FILES['id_front'] ?? [], 'front');
        $backPath = null;
        if ($frontPath === null) {
            $error = 'Invalid ID front upload. Use JPG, PNG, WEBP, or PDF.';
        } elseif (!empty($_FILES['id_back']['name'])) {
            $backPath = saveIdUpload($_FILES['id_back'] ?? [], 'back');
            if ($backPath === null) {
                $error = 'Invalid ID back upload. Use JPG, PNG, WEBP, or PDF.';
                deleteIdUploadFiles($frontPath);
            }
        }

        if ($error === null) {
            $slotLocked = false;
            try {
                $pdo = getDB();
                ensureCitizenNotifyColumns($pdo);
                $pdo->beginTransaction();

                if (!acquireAppointmentSlotLock($pdo, $date, $time)) {
                    $pdo->rollBack();
                    deleteIdUploadFiles($frontPath, $backPath);
                    $error = 'This time slot is being booked by someone else. Please choose another time.';
                } else {
                    $slotLocked = true;
                    $bookingError = validateAppointmentBooking($pdo, $date, $time, $email, true, 'standalone');
                    if ($bookingError !== null) {
                        $pdo->rollBack();
                        deleteIdUploadFiles($frontPath, $backPath);
                        $error = $bookingError;
                    } else {
                        $appointmentCode = generateAppointmentCode();
                        $stmt = $pdo->prepare(
                            'INSERT INTO appointments (appointment_code, first_name, middle_name, last_name, email, phone, notify_email, notify_sms, service_type, appointment_date, appointment_time, status, source, id_front_path, id_back_path)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $appointmentCode,
                            $firstName,
                            $middleName,
                            $lastName,
                            $email,
                            $phone,
                            $notifyEmail ? 1 : 0,
                            $notifySms ? 1 : 0,
                            $serviceType,
                            $date,
                            normalizeAppointmentTime($time),
                            'scheduled',
                            'standalone',
                            $frontPath,
                            $backPath,
                        ]);
                        $pdo->commit();

                        if ($notifyEmail) {
                            notifyAppointmentBooked([
                                'appointment_code'  => $appointmentCode,
                                'first_name'        => $firstName,
                                'middle_name'       => $middleName,
                                'last_name'         => $lastName,
                                'email'             => $email,
                                'service_label'     => $serviceType,
                                'appointment_date'  => $date,
                                'appointment_time'  => $time,
                                'notify_email'      => 1,
                            ]);
                        }
                        $success = true;
                    }
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                deleteIdUploadFiles($frontPath, $backPath);
                $error = 'Could not book appointment. ' . dbConnectionHelpMessage();
            } finally {
                if ($slotLocked) {
                    releaseAppointmentSlotLock($pdo, $date, $time);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Appointment - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?= interFontTags() ?>
    <?= publicStylesheet('citizen-site') ?>
    <?= publicStylesheet('citizen-request') ?>
    <?= publicStylesheet('id-upload') ?>
    <?= publicStylesheet('back-home') ?>
</head>
<body class="citizen-site" data-gmail-form="bookAppointmentForm">

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
        <section class="citizen-page-hero citizen-page-hero--compact">
            <div class="max-w-lg mx-auto">
                <a href="services.php" class="back-home back-home--inline is-centered">
                    <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                    <span>Back to Services</span>
                </a>
                <h1>Schedule Appointment</h1>
                <p>Book a consultation for special civil registry services.</p>
            </div>
        </section>

        <section class="max-w-lg mx-auto px-4 pb-12">
        <?php if ($success): ?>
        <div class="citizen-request-card p-4 sm:p-6 md:p-8 text-center">
            <div class="w-12 h-12 bg-green-50 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="calendar-check" class="w-6 h-6"></i>
            </div>
            <h2 class="text-xl font-black text-slate-900 mb-2">Appointment Booked</h2>
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Your Appointment Code</p>
            <p class="text-blue-600 text-2xl font-black tracking-widest mb-4" id="booked-appt-code"><?= htmlspecialchars($appointmentCode) ?></p>
            <p class="text-gray-500 text-sm mb-6">Save this code to track your appointment status anytime.</p>
            <button type="button" data-open-track data-track-code="<?= htmlspecialchars($appointmentCode, ENT_QUOTES) ?>" class="citizen-btn-gold inline-flex items-center gap-2 px-6 py-3 rounded-full text-sm">
                Track Appointment <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
        </div>
        <?php else: ?>
        <form method="POST" enctype="multipart/form-data" class="citizen-request-card p-4 sm:p-6 space-y-4" id="bookAppointmentForm" data-slot-type="standalone">
            <?= publicCsrfField() ?>
            <input type="hidden" name="service" value="<?= htmlspecialchars($service) ?>">
            <input type="hidden" name="email_verified" id="emailVerified" value="<?= $gmailVerified ? '1' : '0' ?>">
            <input type="hidden" name="notify_email" id="notifyEmailHidden" value="<?= $notifyEmail ? '1' : '0' ?>">
            <input type="hidden" name="notify_sms" id="notifySmsHidden" value="<?= $notifySms ? '1' : '0' ?>">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-[11px] font-bold mb-1">First Name *</label>
                    <input type="text" name="first_name" required placeholder="First name" value="<?= htmlspecialchars($firstName) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold mb-1">Middle Name</label>
                    <input type="text" name="middle_name" placeholder="Middle name (optional)" value="<?= htmlspecialchars((string) ($middleName ?? '')) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold mb-1">Last Name *</label>
                    <input type="text" name="last_name" required placeholder="Last name" value="<?= htmlspecialchars($lastName) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Service Type *</label>
                <input type="text" name="service_type" value="<?= htmlspecialchars($serviceType !== '' ? $serviceType : $serviceLabel) ?>" required placeholder="Service type" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-bold mb-1">Date *</label>
                    <input type="date" id="appointmentDateInput" name="appointment_date" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($date) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold mb-1">Time *</label>
                    <select id="appointmentTimeInput" name="appointment_time" required
                            class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm" disabled>
                        <option value="" disabled <?= $time === '' ? 'selected' : '' ?>>Select a time slot</option>
                        <?php if ($time !== ''): ?>
                        <option value="<?= htmlspecialchars(substr(normalizeAppointmentTime($time), 0, 5)) ?>" selected>
                            <?= htmlspecialchars(formatAppointmentSlotLabel($time)) ?>
                        </option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <p class="text-[10px] text-gray-500">Special service appointments: Monday to Friday only, excluding holidays. Morning 8:00 AM–12:00 NN and afternoon 1:00–5:00 PM, every 20 minutes. Lunch break 12:00–1:00 PM. One booking per slot.</p>
            <p id="slotAvailabilityStatus" class="hidden text-xs mt-2"></p>
            <div>
                <label class="flex items-center gap-2 text-[11px] font-bold mb-1">
                    <i data-lucide="mail" class="w-3.5 h-3.5 text-blue-500"></i> Active Gmail Account *
                </label>
                <div class="flex gap-2">
                    <input type="email" id="gmailInput" name="email" required placeholder="example@gmail.com"
                           value="<?= htmlspecialchars($email) ?>"
                           class="flex-1 bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                    <button type="button" id="verifyGmailBtn" class="citizen-btn-navy px-4 py-2.5 rounded-xl text-xs whitespace-nowrap shrink-0">
                        Verify Gmail
                    </button>
                </div>
                <p class="text-[10px] text-gray-500 mt-1">We check that your @gmail.com account is active. If you agree to notifications, status updates are sent to this Gmail.</p>
                <p id="gmailStatus" class="text-xs mt-2 <?= $gmailVerified ? 'font-semibold text-green-600' : 'hidden' ?>"><?= $gmailVerified ? 'Gmail verified — this is an active Google account.' : '' ?></p>
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Phone *</label>
                <input type="tel" name="phone" required placeholder="09XXXXXXXXX" pattern="09[0-9]{9}" value="<?= htmlspecialchars($phone) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-[11px] font-bold mb-2 block">Upload Valid IDs (National ID / Driver's License) *</label>
                <div class="grid grid-cols-2 gap-4">
                    <label class="citizen-id-upload" data-id-upload>
                        <input type="file" name="id_front" accept="image/*,.pdf" class="hidden" id="idFront" required>
                        <div class="citizen-id-upload__empty" data-id-upload-empty>
                            <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                            <p class="text-xs font-bold text-gray-600">Front Side</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="idFrontLabel">Click to upload ID</p>
                        </div>
                        <div class="citizen-id-upload__preview hidden" data-id-upload-preview>
                            <span class="citizen-id-upload__side">Front</span>
                            <img class="citizen-id-upload__image hidden" data-id-upload-image alt="Front ID preview">
                            <div class="citizen-id-upload__pdf hidden" data-id-upload-pdf>
                                <i data-lucide="file-text" class="w-8 h-8 mx-auto mb-2 opacity-60"></i>
                                <span>PDF uploaded</span>
                            </div>
                            <p class="citizen-id-upload__caption" data-id-upload-caption></p>
                        </div>
                    </label>
                    <label class="citizen-id-upload" data-id-upload>
                        <input type="file" name="id_back" accept="image/*,.pdf" class="hidden" id="idBack">
                        <div class="citizen-id-upload__empty" data-id-upload-empty>
                            <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                            <p class="text-xs font-bold text-gray-600">Back Side (Optional)</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="idBackLabel">Click to upload ID</p>
                        </div>
                        <div class="citizen-id-upload__preview hidden" data-id-upload-preview>
                            <span class="citizen-id-upload__side">Back</span>
                            <img class="citizen-id-upload__image hidden" data-id-upload-image alt="Back ID preview">
                            <div class="citizen-id-upload__pdf hidden" data-id-upload-pdf>
                                <i data-lucide="file-text" class="w-8 h-8 mx-auto mb-2 opacity-60"></i>
                                <span>PDF uploaded</span>
                            </div>
                            <p class="citizen-id-upload__caption" data-id-upload-caption></p>
                        </div>
                    </label>
                </div>
            </div>
            <div class="citizen-form-actions pt-2">
                <a href="services.php" class="back-home back-home--step">
                    <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                    <span>Back</span>
                </a>
                <div class="citizen-form-actions__forward w-full sm:w-auto">
                    <button type="submit" id="bookSubmitBtn" class="citizen-btn-gold w-full sm:w-auto sm:min-w-[12rem] rounded-xl py-3 text-sm" data-loading-text="Booking…">Book Appointment</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
        </section>
    </main>
    <?= actionCoreScripts() ?>
    <?= actionResultScript($error ? ['error', $error] : null) ?>
    <?= scriptTag('public/citizen-site.js') ?>
    <?= scriptTag('public/appointment-slots.js') ?>
    <?= scriptTag('public/forms.js') ?>
    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/maintenance_announcement.php'; ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= scriptTag('core/reminders.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
