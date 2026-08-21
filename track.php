<?php
/**
 * Track document requests (ALR-*) and appointments (APT-*) by code.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

$code = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
$request = null;
$appointment = null;
$error = null;

if ($code !== '') {
    try {
        $pdo = getDB();

        if (isAppointmentTrackingCode($code)) {
            $stmt = $pdo->prepare('SELECT * FROM appointments WHERE appointment_code = ?');
            $stmt->execute([$code]);
            $appointment = $stmt->fetch();
            if (!$appointment) {
                $error = 'No appointment found with that code. Please check the code and try again.';
            }
        } else {
            $stmt = $pdo->prepare('SELECT * FROM document_requests WHERE tracking_code = ?');
            $stmt->execute([$code]);
            $request = $stmt->fetch();
            if (!$request) {
                $error = 'No request or appointment found with that code. Use ALR- for document requests or APT- for appointments.';
            }
        }
    } catch (PDOException $e) {
        $error = dbConnectionHelpMessage();
    }
}

$statusSteps = requestStatusWorkflow();
$currentIdx = $request ? requestStatusProgressIndex($request['status']) : false;
$apptSteps = appointmentStatusWorkflow();
$apptIdx = $appointment ? appointmentStatusProgressIndex($appointment['status']) : false;
$site = getSiteSettings();
$trackRealtime = $appointment ? 'track-appointment' : ($request ? 'track' : '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Your Request - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .track-card { box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col"<?= $trackRealtime ? ' data-realtime="' . htmlspecialchars($trackRealtime) . '" data-tracking-code="' . htmlspecialchars($code) . '"' : '' ?>>

    <nav class="flex items-center justify-between px-6 md:px-8 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2">
            <div class="bg-blue-600 text-white p-1 rounded font-bold text-[10px] w-5 h-5 flex items-center justify-center">A</div>
            <span class="font-bold tracking-tight text-blue-900 text-xs">ALCROS</span>
        </div>
        <a href="index.php" class="text-gray-400 text-[10px] hover:text-gray-600 transition">Back to Home</a>
    </nav>

    <main class="flex-grow flex flex-col items-center justify-start pt-12 md:pt-16 px-4 pb-16">
        <h1 class="text-2xl font-black text-slate-800 mb-2">Track Your Request</h1>
        <p class="text-gray-500 text-sm text-center mb-8 max-w-md">
            Enter your tracking code to see live status updates on your document request or appointment.
        </p>

        <div class="w-full max-w-lg bg-white border border-gray-100 rounded-xl p-4 track-card mb-6">
            <form method="GET" class="flex gap-2">
                <div class="relative flex-grow">
                    <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none">
                        <i data-lucide="search" class="w-4 h-4 text-gray-300"></i>
                    </div>
                    <input type="text" name="code" id="track-code-input" value="<?= htmlspecialchars($code) ?>"
                        placeholder="e.g. ALR-XXXXXXXX or APT-XXXXXX"
                        class="w-full bg-gray-50 border border-gray-200 rounded-lg py-3 pl-10 pr-4 text-sm font-semibold tracking-wide text-gray-700 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition uppercase">
                </div>
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg text-sm font-bold transition shadow-sm shrink-0" data-loading-text="Tracking…">Track</button>
            </form>
        </div>

        <?php if ($error): ?>
        <div class="w-full max-w-lg p-4 bg-red-50 border border-red-100 rounded-xl text-red-600 text-sm text-center"><?= htmlspecialchars($error) ?></div>
        <?php elseif ($appointment): ?>
        <div class="w-full max-w-lg bg-white border border-gray-100 rounded-xl p-6 track-card">
            <div class="flex justify-between items-start gap-3 mb-4">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Appointment Code</p>
                    <p class="text-xl font-black text-blue-600 tracking-widest"><?= htmlspecialchars($appointment['appointment_code']) ?></p>
                </div>
                <div id="track-status-badge"><?= appointmentStatusBadge($appointment['status']) ?></div>
            </div>

            <div id="track-status-message" class="rounded-xl p-4 mb-5 text-sm leading-relaxed <?= in_array($appointment['status'], ['cancelled', 'no_show'], true) ? 'bg-red-50 text-red-800 border border-red-100' : ($appointment['status'] === 'completed' ? 'bg-green-50 text-green-800 border border-green-100' : 'bg-blue-50 text-blue-800 border border-blue-100') ?>">
                <?= htmlspecialchars(appointmentStatusMessage($appointment['status'])) ?>
            </div>

            <div class="space-y-2 text-sm mb-6">
                <p><span class="font-bold text-gray-500">Name:</span> <?= htmlspecialchars($appointment['citizen_name']) ?></p>
                <p><span class="font-bold text-gray-500">Service:</span> <?= htmlspecialchars(appointmentServiceLabel($appointment['service_type'])) ?></p>
                <p><span class="font-bold text-gray-500">Scheduled:</span> <?= htmlspecialchars(formatAppointmentDisplay($appointment['appointment_date'], $appointment['appointment_time'])) ?></p>
                <p><span class="font-bold text-gray-500">Booked:</span> <?= htmlspecialchars(formatDateDisplay($appointment['created_at'])) ?></p>
            </div>

            <?php if (!in_array($appointment['status'], ['cancelled', 'no_show'], true)): ?>
            <div class="mb-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase mb-3">Progress</p>
                <div class="flex justify-between text-[9px] font-bold uppercase text-gray-400">
                    <?php foreach ($apptSteps as $i => $step): ?>
                    <div class="text-center flex-1" data-track-step>
                        <div class="track-step-dot w-3 h-3 rounded-full mx-auto mb-1 <?= ($apptIdx !== false && $i <= $apptIdx) ? 'bg-blue-600' : 'bg-gray-200' ?>"></div>
                        <span class="track-step-label <?= ($apptIdx !== false && $i <= $apptIdx) ? 'text-blue-600' : '' ?>"><?= htmlspecialchars(appointmentStatusLabel($step)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-red-600 text-sm font-semibold mb-4">Please contact the registry office to reschedule.</p>
            <?php endif; ?>

            <p id="track-updated-at" class="text-[10px] text-gray-400 text-center">
                Status updates automatically · Last checked <?= date('g:i A') ?>
            </p>
        </div>
        <?php elseif ($request): ?>
        <div class="w-full max-w-lg bg-white border border-gray-100 rounded-xl p-6 track-card">
            <div class="flex justify-between items-start gap-3 mb-4">
                <div>
                    <p class="text-[10px] font-bold text-gray-400 uppercase">Tracking Code</p>
                    <p class="text-xl font-black text-blue-600 tracking-widest"><?= htmlspecialchars($request['tracking_code']) ?></p>
                </div>
                <div id="track-status-badge"><?= requestStatusBadge($request['status']) ?></div>
            </div>

            <div id="track-status-message" class="rounded-xl p-4 mb-5 text-sm leading-relaxed <?= $request['status'] === 'ready' ? 'bg-green-50 text-green-800 border border-green-100' : ($request['status'] === 'rejected' ? 'bg-red-50 text-red-800 border border-red-100' : 'bg-blue-50 text-blue-800 border border-blue-100') ?>">
                <?= htmlspecialchars(requestStatusMessage($request['status'])) ?>
            </div>

            <div class="space-y-2 text-sm mb-6">
                <p><span class="font-bold text-gray-500">Name:</span> <?= htmlspecialchars($request['citizen_name']) ?></p>
                <p><span class="font-bold text-gray-500">Document:</span> <?= htmlspecialchars(documentTypeLabel($request['document_type'])) ?></p>
                <p><span class="font-bold text-gray-500">Submitted:</span> <?= htmlspecialchars(formatDateDisplay($request['submitted_at'])) ?></p>
                <?php if (!empty($request['appointment_date'])): ?>
                <p><span class="font-bold text-gray-500">Preferred visit:</span> <?= htmlspecialchars(formatAppointmentDisplay($request['appointment_date'], $request['appointment_time'])) ?></p>
                <?php endif; ?>
            </div>

            <?php if ($request['status'] !== 'rejected'): ?>
            <div class="mb-4">
                <p class="text-[10px] font-bold text-gray-400 uppercase mb-3">Progress</p>
                <div class="flex justify-between text-[9px] font-bold uppercase text-gray-400">
                    <?php foreach ($statusSteps as $i => $step): ?>
                    <div class="text-center flex-1" data-track-step>
                        <div class="track-step-dot w-3 h-3 rounded-full mx-auto mb-1 <?= ($currentIdx !== false && $i <= $currentIdx) ? 'bg-blue-600' : 'bg-gray-200' ?>"></div>
                        <span class="track-step-label <?= ($currentIdx !== false && $i <= $currentIdx) ? 'text-blue-600' : '' ?>"><?= htmlspecialchars(requestStatusLabel($step)) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <p class="text-red-600 text-sm font-semibold mb-4">Please contact the registry office for assistance.</p>
            <?php endif; ?>

            <p id="track-updated-at" class="text-[10px] text-gray-400 text-center">
                Status updates automatically · Last checked <?= date('g:i A') ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($request || $appointment): ?>
        <p class="mt-6 text-xs text-gray-400 text-center max-w-md">
            Office hours: <?= htmlspecialchars($site['hours']) ?>. For questions, call <?= htmlspecialchars($site['phone']) ?>.
        </p>
        <?php endif; ?>
    </main>

    <script src="includes/loading.js"></script>
    <script>lucide.createIcons();</script>
    <?php if ($request || $appointment): ?>
    <script src="includes/poll.js"></script>
    <script src="includes/realtime.js"></script>
    <?php endif; ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <script src="includes/reminders.js"></script>
</body>
</html>
