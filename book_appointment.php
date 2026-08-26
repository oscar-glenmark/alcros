<?php
/**
 * Citizen appointment booking — saves to MySQL appointments table.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$service = $_GET['service'] ?? $_POST['service'] ?? '';
$serviceLabel = appointmentServiceLabel($service);
$success = null;
$error = null;
$appointmentCode = null;

$citizenName = trim($_POST['citizen_name'] ?? '');
$email       = normalizeGmail(trim($_POST['email'] ?? ''));
$phone       = trim($_POST['phone'] ?? '');
$serviceType = appointmentServiceLabel(trim($_POST['service_type'] ?? $service));
$date        = $_POST['appointment_date'] ?? '';
$time        = $_POST['appointment_time'] ?? '';
$notifyEmail = isset($_POST['notify_email']);
$gmailVerified = isGmailVerifiedInSession($email);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePublicPostCsrf();
    if ($citizenName === '' || $serviceType === '' || $date === '' || $time === '') {
        $error = 'Please complete all required fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Please choose a valid appointment date.';
    } elseif ($date < date('Y-m-d')) {
        $error = 'Appointment date cannot be in the past.';
    } elseif (!isValidGmail($email)) {
        $error = 'Please enter a valid Gmail address (example@gmail.com).';
    } elseif (!isGmailVerifiedInSession($email)) {
        $error = 'Please verify your Gmail is an active Google account before continuing.';
        $gmailVerified = false;
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
            try {
                $pdo = getDB();
                ensureCitizenNotifyColumns($pdo);
                $pdo->beginTransaction();

                $bookingError = validateAppointmentBooking($pdo, $date, $time, $email, true);
                if ($bookingError !== null) {
                    $pdo->rollBack();
                    deleteIdUploadFiles($frontPath, $backPath);
                    $error = $bookingError;
                } else {
                    $appointmentCode = generateAppointmentCode();
                    $stmt = $pdo->prepare(
                        'INSERT INTO appointments (appointment_code, citizen_name, email, phone, notify_email, service_type, appointment_date, appointment_time, status, source, id_front_path, id_back_path)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $appointmentCode,
                        $citizenName,
                        $email,
                        $phone ?: null,
                        $notifyEmail ? 1 : 0,
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
                            'citizen_name'      => $citizenName,
                            'email'             => $email,
                            'service_label'     => $serviceType,
                            'appointment_date'  => $date,
                            'appointment_time'  => $time,
                            'notify_email'      => 1,
                        ]);
                    }
                    $success = true;
                }
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                deleteIdUploadFiles($frontPath, $backPath);
                $error = 'Could not book appointment. ' . dbConnectionHelpMessage();
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
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="includes/back_home.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .upload-box { border: 2px dashed #e2e8f0; transition: border-color 0.2s; }
        .upload-box:hover { border-color: #93c5fd; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen" data-gmail-form="bookAppointmentForm">
    <nav class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2 min-w-0">
            <div class="bg-blue-600 text-white p-1 rounded font-bold text-[10px] w-5 h-5 flex items-center justify-center shrink-0">A</div>
            <span class="font-bold tracking-tight text-blue-900 truncate">ALCROS</span>
        </div>
        <a href="index.php" class="back-home back-home--nav shrink-0">Back to Home</a>
    </nav>
    <main class="max-w-lg mx-auto px-4 py-16">
        <?php if ($success): ?>
        <div class="bg-white rounded-2xl border p-8 text-center shadow-sm">
            <div class="w-12 h-12 bg-green-50 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="calendar-check" class="w-6 h-6"></i>
            </div>
            <h1 class="text-xl font-black mb-2">Appointment Booked</h1>
            <p class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Your Appointment Code</p>
            <p class="text-blue-600 text-2xl font-black tracking-widest mb-4" id="booked-appt-code"><?= htmlspecialchars($appointmentCode) ?></p>
            <p class="text-gray-500 text-sm mb-6">Save this code to track your appointment status anytime.</p>
            <button type="button" data-open-track data-track-code="<?= htmlspecialchars($appointmentCode, ENT_QUOTES) ?>" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full text-sm font-bold">
                Track Appointment <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </button>
        </div>
        <?php else: ?>
        <h1 class="text-2xl font-black mb-2">Schedule Appointment</h1>
        <p class="text-gray-500 text-sm mb-6">Book a consultation for special civil registry services.</p>
        <?php if ($error): ?><div class="mb-4 p-3 bg-red-50 text-red-600 text-sm rounded-lg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" enctype="multipart/form-data" class="bg-white rounded-2xl border p-6 space-y-4 shadow-sm" id="bookAppointmentForm">
            <?= publicCsrfField() ?>
            <input type="hidden" name="service" value="<?= htmlspecialchars($service) ?>">
            <input type="hidden" name="email_verified" id="emailVerified" value="<?= $gmailVerified ? '1' : '0' ?>">
            <div>
                <label class="block text-[11px] font-bold mb-1">Full Name *</label>
                <input type="text" name="citizen_name" required value="<?= htmlspecialchars($citizenName) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Service Type *</label>
                <input type="text" name="service_type" value="<?= htmlspecialchars($serviceType !== '' ? $serviceType : $serviceLabel) ?>" required class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-bold mb-1">Date *</label>
                    <input type="date" id="appointmentDateInput" name="appointment_date" required min="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($date) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold mb-1">Time *</label>
                    <input type="time" id="appointmentTimeInput" name="appointment_time" required min="08:00" max="17:00" value="<?= htmlspecialchars($time) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
            </div>
            <p class="text-[10px] text-gray-500">Office hours: 8:00 AM – 5:00 PM (Monday to Friday). One booking per time slot.</p>
            <p id="slotAvailabilityStatus" class="hidden text-xs mt-2"></p>
            <div>
                <label class="flex items-center gap-2 text-[11px] font-bold mb-1">
                    <i data-lucide="mail" class="w-3.5 h-3.5 text-blue-500"></i> Active Gmail Account *
                </label>
                <div class="flex gap-2">
                    <input type="email" id="gmailInput" name="email" required placeholder="example@gmail.com"
                           value="<?= htmlspecialchars($email) ?>"
                           class="flex-1 bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                    <button type="button" id="verifyGmailBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl text-xs font-bold whitespace-nowrap shrink-0">
                        Verify Gmail
                    </button>
                </div>
                <p class="text-[10px] text-gray-500 mt-1">We check that your @gmail.com account is active. If you agree to notifications, appointment updates are sent to this Gmail.</p>
                <p id="gmailStatus" class="text-xs mt-2 <?= $gmailVerified ? 'font-semibold text-green-600' : 'hidden' ?>"><?= $gmailVerified ? 'Gmail verified — this is an active Google account.' : '' ?></p>
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Phone</label>
                <input type="tel" name="phone" value="<?= htmlspecialchars($phone) ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div>
                <label class="text-[11px] font-bold mb-2 block">Upload Valid IDs (National ID / Driver's License) *</label>
                <div class="grid grid-cols-2 gap-4">
                    <label class="upload-box rounded-xl p-6 text-center cursor-pointer block">
                        <input type="file" name="id_front" accept="image/*,.pdf" class="hidden" id="idFront" required>
                        <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                        <p class="text-xs font-bold text-gray-600">Front Side</p>
                        <p class="text-[10px] text-gray-400 mt-1" id="idFrontLabel">Click to upload ID</p>
                    </label>
                    <label class="upload-box rounded-xl p-6 text-center cursor-pointer block">
                        <input type="file" name="id_back" accept="image/*,.pdf" class="hidden" id="idBack">
                        <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                        <p class="text-xs font-bold text-gray-600">Back Side (Optional)</p>
                        <p class="text-[10px] text-gray-400 mt-1" id="idBackLabel">Click to upload ID</p>
                    </label>
                </div>
            </div>
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="notify_email" value="1" id="notifyEmailCheckbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500" <?= $notifyEmail ? 'checked' : '' ?>>
                <span class="text-xs font-semibold text-slate-700">Send Gmail notifications for this appointment</span>
            </label>
            <button type="submit" id="bookSubmitBtn" class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl py-3 text-sm font-bold" data-loading-text="Booking…" <?= $gmailVerified ? '' : 'disabled' ?>>Book Appointment</button>
        </form>
        <?php endif; ?>
    </main>
    <?= scriptTag('core/loading.js') ?>
    <?= scriptTag('public/appointment-slots.js') ?>
    <?= scriptTag('public/forms.js') ?>
    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?= scriptTag('core/reminders.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
