<?php
/**
 * Citizen appointment booking — saves to MySQL appointments table.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$service = $_GET['service'] ?? $_POST['service'] ?? '';
$serviceLabel = appointmentServiceLabel($service);
$success = null;
$error = null;
$appointmentCode = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $citizenName = trim($_POST['citizen_name'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $serviceType = appointmentServiceLabel(trim($_POST['service_type'] ?? $service));
    $date        = $_POST['appointment_date'] ?? '';
    $time        = $_POST['appointment_time'] ?? '';
    $notifyEmail = isset($_POST['notify_email']);

    if ($citizenName === '' || $serviceType === '' || $date === '' || $time === '') {
        $error = 'Please complete all required fields.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $error = 'Please choose a valid appointment date.';
    } elseif ($date < date('Y-m-d')) {
        $error = 'Appointment date cannot be in the past.';
    } else {
        try {
            $pdo = getDB();
            ensureCitizenNotifyColumns($pdo);
            $appointmentCode = generateAppointmentCode();
            $stmt = $pdo->prepare(
                'INSERT INTO appointments (appointment_code, citizen_name, email, phone, notify_email, service_type, appointment_date, appointment_time, status, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$appointmentCode, $citizenName, $email ?: null, $phone ?: null, $notifyEmail ? 1 : 0, $serviceType, $date, $time, 'scheduled', 'standalone']);
            if ($notifyEmail && $email !== '') {
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
        } catch (PDOException $e) {
            $error = 'Could not book appointment. ' . dbConnectionHelpMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Appointment - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap'); body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-50 min-h-screen">
    <nav class="flex items-center justify-between px-8 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2">
            <div class="bg-blue-600 text-white p-1 rounded font-bold text-[10px] w-5 h-5 flex items-center justify-center">A</div>
            <span class="font-bold tracking-tight text-blue-900">ALCROS</span>
        </div>
        <a href="index.php" class="text-gray-400 text-[10px] hover:text-gray-600">Back to Home</a>
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
            <a href="track.php?code=<?= urlencode($appointmentCode) ?>" class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full text-sm font-bold">
                Track Appointment <i data-lucide="arrow-right" class="w-4 h-4"></i>
            </a>
        </div>
        <?php else: ?>
        <h1 class="text-2xl font-black mb-2">Schedule Appointment</h1>
        <p class="text-gray-500 text-sm mb-6">Book a consultation for special civil registry services.</p>
        <?php if ($error): ?><div class="mb-4 p-3 bg-red-50 text-red-600 text-sm rounded-lg"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST" class="bg-white rounded-2xl border p-6 space-y-4 shadow-sm">
            <input type="hidden" name="service" value="<?= htmlspecialchars($service) ?>">
            <div>
                <label class="block text-[11px] font-bold mb-1">Full Name *</label>
                <input type="text" name="citizen_name" required class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Service Type *</label>
                <input type="text" name="service_type" value="<?= htmlspecialchars($serviceLabel) ?>" required class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-[11px] font-bold mb-1">Date *</label>
                    <input type="date" name="appointment_date" required min="<?= date('Y-m-d') ?>" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold mb-1">Time *</label>
                    <input type="time" name="appointment_time" required class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Email</label>
                <input type="email" name="email" placeholder="example@gmail.com" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-[11px] font-bold mb-1">Phone</label>
                <input type="tel" name="phone" class="w-full bg-gray-50 border rounded-xl px-4 py-2.5 text-sm">
            </div>
            <label class="flex items-start gap-2 cursor-pointer">
                <input type="checkbox" name="notify_email" value="1" id="notifyEmailCheckbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-semibold text-slate-700">Send Gmail notifications for this appointment</span>
            </label>
            <button type="submit" class="w-full bg-blue-600 text-white rounded-xl py-3 text-sm font-bold" data-loading-text="Booking…">Book Appointment</button>
        </form>
        <?php endif; ?>
    </main>
    <script src="includes/loading.js"></script>
    <script>lucide.createIcons();</script>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <script src="includes/reminders.js"></script>
</body>
</html>
