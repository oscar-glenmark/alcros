<?php
/**
 * Citizen document request — multi-step wizard.
 */
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';

if (isMaintenanceMode() || !arePublicRequestsAllowed()) {
    header('Location: index.php');
    exit;
}

$validTypes = array_column(getDocumentTypes(), 'slug');
$purposeOptions = getPurposeOptions();

if (!isset($_SESSION['request_draft'])) {
    $_SESSION['request_draft'] = [];
}

$draft = &$_SESSION['request_draft'];
$error = null;
$trackingCode = null;
$success = !empty($_SESSION['request_success']);
$successData = $success ? $_SESSION['request_success'] : null;
if ($success) {
    unset($_SESSION['request_success']);
    $trackingCode = $successData['tracking_code'] ?? null;
}

$type = $_GET['type'] ?? $draft['document_type'] ?? '';
if ($type && in_array($type, $validTypes, true)) {
    $draft['document_type'] = $type;
}

$step = max(1, min(4, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
$recordVerified = isCivilRecordVerifiedInSession(
    (string) ($draft['citizen_name'] ?? ''),
    (string) ($draft['date_of_birth'] ?? '')
);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $action = $_POST['action'] ?? 'next';

    if ($action === 'back') {
        $step = max(1, $step - 1);
        header('Location: request.php?step=' . $step);
        exit;
    }

    if ($step === 1) {
        $citizenName  = trim($_POST['citizen_name'] ?? '');
        $dateOfBirth  = $_POST['date_of_birth'] ?? '';
        $sex          = $_POST['sex'] ?? '';
        $documentType = $_POST['document_type'] ?? '';

        if ($citizenName === '' || !in_array($documentType, $validTypes, true)) {
            $error = 'Please enter your full name and select a service type.';
        } elseif ($dateOfBirth === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
            $error = 'Please enter a valid date of birth.';
        } elseif (!in_array($sex, ['male', 'female'], true)) {
            $error = 'Please select your sex.';
        } elseif (!isCivilRecordVerifiedInSession($citizenName, $dateOfBirth)) {
            $error = 'Please check your civil registry record before continuing. If you are not registered, visit the LCRO office in person.';
            $recordVerified = false;
        } else {
            $draft['citizen_name']   = $citizenName;
            $draft['date_of_birth']  = $dateOfBirth;
            $draft['sex']            = $sex;
            $draft['document_type']  = $documentType;
            header('Location: request.php?step=2');
            exit;
        }
    }

    if ($step === 2) {
        $email         = trim($_POST['email'] ?? '');
        $purpose       = trim($_POST['purpose'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $privacyAgreed = isset($_POST['privacy_agreed']);
        $notifyEmail   = isset($_POST['notify_email']);

        if (!isValidGmail($email)) {
            $error = 'Please enter a valid Gmail address (example@gmail.com).';
        } elseif (!isGmailVerifiedInSession($email)) {
            $error = 'Please verify your Gmail is an active Google account before continuing.';
        } elseif ($purpose === '' || !array_key_exists($purpose, $purposeOptions)) {
            $error = 'Please select the purpose of your request.';
        } elseif (!isValidPhilippineMobile($phone)) {
            $error = 'Please enter a valid cellphone number (09XXXXXXXXX).';
        } elseif (empty($_FILES['id_front']['name']) && empty($draft['id_front_path'])) {
            $error = 'Please upload the front side of your valid ID.';
        } elseif (!$privacyAgreed) {
            $error = 'You must agree to the Data Privacy Notice to continue.';
        } else {
            $frontPath = saveIdUpload($_FILES['id_front'] ?? [], 'front');
            if (!empty($_FILES['id_front']['name']) && $frontPath === null) {
                $error = 'Invalid ID front upload. Use JPG, PNG, WEBP, or PDF.';
            } else {
                $backPath = saveIdUpload($_FILES['id_back'] ?? [], 'back');
                if (!empty($_FILES['id_back']['name']) && $backPath === null) {
                    $error = 'Invalid ID back upload. Use JPG, PNG, WEBP, or PDF.';
                } else {
                    $draft['email']           = $email;
                    $draft['email_verified']  = 1;
                    $draft['purpose']         = $purpose;
                    $draft['phone']           = $phone;
                    $draft['privacy_agreed']  = 1;
                    $draft['notify_email']    = $notifyEmail ? 1 : 0;
                    if ($frontPath) {
                        $draft['id_front_path'] = $frontPath;
                    }
                    if ($backPath) {
                        $draft['id_back_path'] = $backPath;
                    }
                    header('Location: request.php?step=3');
                    exit;
                }
            }
        }
    }

    if ($step === 3) {
        $appointmentDate = $_POST['appointment_date'] ?? '';
        $appointmentTime = $_POST['appointment_time'] ?? '';

        if ($appointmentDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointmentDate)) {
            $error = 'Please select a preferred appointment date.';
        } elseif ($appointmentDate < date('Y-m-d')) {
            $error = 'Appointment date cannot be in the past.';
        } elseif ($appointmentTime === '') {
            $error = 'Please select a preferred appointment time.';
        } else {
            $draft['appointment_date'] = $appointmentDate;
            $draft['appointment_time'] = $appointmentTime;

            try {
                $pdo = getDB();
                ensureCitizenNotifyColumns($pdo);
                $pdo->beginTransaction();

                $bookingError = validateAppointmentBooking(
                    $pdo,
                    $appointmentDate,
                    $appointmentTime,
                    $draft['email'] ?? null,
                    true
                );
                if ($bookingError !== null) {
                    $pdo->rollBack();
                    $error = $bookingError;
                } else {
                    $trackingCode = generateTrackingCode();
                    $stmt = $pdo->prepare(
                        'INSERT INTO document_requests
                         (tracking_code, citizen_name, date_of_birth, sex, email, email_verified, phone,
                          document_type, purpose, id_front_path, id_back_path, privacy_agreed, notify_email,
                          appointment_date, appointment_time, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $trackingCode,
                        $draft['citizen_name'],
                        $draft['date_of_birth'],
                        $draft['sex'],
                        $draft['email'],
                        (int) ($draft['email_verified'] ?? 0),
                        $draft['phone'],
                        $draft['document_type'],
                        $purposeOptions[$draft['purpose']] ?? $draft['purpose'],
                        $draft['id_front_path'] ?? null,
                        $draft['id_back_path'] ?? null,
                        (int) ($draft['privacy_agreed'] ?? 0),
                        (int) ($draft['notify_email'] ?? 0),
                        $draft['appointment_date'],
                        normalizeAppointmentTime($draft['appointment_time']),
                        'pending',
                    ]);

                    $apptCode = generateAppointmentCode();
                    $apptStmt = $pdo->prepare(
                        'INSERT INTO appointments
                         (appointment_code, citizen_name, email, phone, notify_email, service_type, appointment_date, appointment_time, status, source, tracking_code)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $apptStmt->execute([
                        $apptCode,
                        $draft['citizen_name'],
                        $draft['email'],
                        $draft['phone'],
                        (int) ($draft['notify_email'] ?? 0),
                        documentTypeLabel($draft['document_type']),
                        $draft['appointment_date'],
                        normalizeAppointmentTime($draft['appointment_time']),
                        'scheduled',
                        'document_request',
                        $trackingCode,
                    ]);
                    $pdo->commit();

                    $emailSent = notifyRequestSubmitted([
                        'tracking_code'    => $trackingCode,
                        'citizen_name'     => $draft['citizen_name'],
                        'email'            => $draft['email'],
                        'document_label'   => documentTypeLabel($draft['document_type']),
                        'appointment_date' => $draft['appointment_date'],
                        'appointment_time' => $draft['appointment_time'],
                        'notify_email'     => (int) ($draft['notify_email'] ?? 0),
                    ]);

                    $_SESSION['request_success'] = [
                        'tracking_code'    => $trackingCode,
                        'citizen_name'     => $draft['citizen_name'],
                        'email'            => $draft['email'],
                        'document_label'   => documentTypeLabel($draft['document_type']),
                        'appointment_date' => $draft['appointment_date'],
                        'appointment_time' => $draft['appointment_time'],
                        'email_sent'       => $emailSent,
                        'notify_email'     => (int) ($draft['notify_email'] ?? 0),
                    ];
                    unset($_SESSION['request_draft']);
                    header('Location: request.php?step=4&success=1');
                    exit;
                }
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not submit request. ' . dbConnectionHelpMessage();
            }
        }
    }
}

$stepLabels = [
    1 => ['key' => 'identification', 'label' => 'Identification', 'icon' => 'user'],
    2 => ['key' => 'requirements',   'label' => 'Requirements',   'icon' => 'mail'],
    3 => ['key' => 'appointment',    'label' => 'Appointment',    'icon' => 'calendar'],
    4 => ['key' => 'finish',         'label' => 'Finish',         'icon' => 'check'],
];

$stepTitles = [
    1 => ['title' => 'Request Details', 'subtitle' => 'Please provide your identity and active contact details.'],
    2 => ['title' => 'Requirement Checklist', 'subtitle' => 'Please provide your Gmail and upload necessary documents.'],
    3 => ['title' => 'Schedule Appointment', 'subtitle' => 'Choose your preferred date and time for document pickup.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Document - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="includes/loading.js" defer></script>
    <link rel="stylesheet" href="includes/back_home.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
        body { font-family: 'Inter', sans-serif; }
        .step-active { color: #2563eb; }
        .step-done { color: #2563eb; }
        .step-pending { color: #94a3b8; }
        .upload-box { border: 2px dashed #e2e8f0; transition: border-color 0.2s; }
        .upload-box:hover { border-color: #93c5fd; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">

    <nav class="flex items-center justify-between px-4 sm:px-6 py-3 border-b border-gray-100 bg-white">
        <div class="flex items-center gap-2 min-w-0">
            <div class="bg-blue-600 text-white p-1 rounded font-bold text-[10px] w-5 h-5 flex items-center justify-center shrink-0">A</div>
            <span class="font-bold tracking-tight text-blue-900 truncate">ALCROS</span>
        </div>
        <a href="index.php" class="back-home back-home--nav shrink-0">Back to Home</a>
    </nav>

    <main class="max-w-2xl mx-auto px-4 py-10">
        <?php if ($success && $step === 4 && $successData): ?>
        <div class="flex justify-center mb-10">
            <?php foreach ($stepLabels as $i => $s): ?>
            <div class="flex items-center <?= $i < 4 ? 'flex-1' : '' ?>">
                <div class="flex flex-col items-center">
                    <div class="w-8 h-8 rounded-full bg-blue-600 text-white flex items-center justify-center">
                        <i data-lucide="check" class="w-4 h-4"></i>
                    </div>
                    <span class="text-[9px] font-bold uppercase mt-1 step-done"><?= $s['label'] ?></span>
                </div>
                <?php if ($i < 4): ?><div class="flex-1 h-0.5 bg-blue-600 mx-2 mb-4"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="bg-white rounded-2xl border border-green-100 p-8 md:p-10 shadow-sm">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="check-circle" class="w-8 h-8"></i>
                </div>
                <h1 class="text-2xl font-black text-slate-900 mb-2">Request Submitted Successfully</h1>
                <p class="text-gray-500 text-sm">
                    Thank you, <strong><?= htmlspecialchars($successData['citizen_name']) ?></strong>.
                    Your <?= htmlspecialchars($successData['document_label']) ?> request is now in our system.
                </p>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-xl p-6 text-center mb-6">
                <p class="text-[10px] font-bold text-blue-600 uppercase tracking-widest mb-2">Your tracking code</p>
                <p id="success-tracking-code" class="text-3xl md:text-4xl font-black text-blue-700 tracking-widest"><?= htmlspecialchars($successData['tracking_code']) ?></p>
                <button type="button" id="copy-tracking-btn" class="mt-4 inline-flex items-center gap-2 bg-white border border-blue-200 text-blue-700 px-4 py-2 rounded-lg text-xs font-bold hover:bg-blue-100">
                    <i data-lucide="copy" class="w-3.5 h-3.5"></i> Copy code
                </button>
            </div>

            <div class="space-y-3 text-sm text-slate-600 mb-6">
                <?php if (!empty($successData['appointment_date'])): ?>
                <p><span class="font-bold text-slate-500">Preferred visit:</span> <?= htmlspecialchars(formatAppointmentDisplay($successData['appointment_date'], $successData['appointment_time'])) ?></p>
                <?php endif; ?>
                <p><span class="font-bold text-slate-500">Status:</span> Pending review — you can follow every update online.</p>
                <?php if (!empty($successData['email_sent'])): ?>
                <p class="flex items-start gap-2 text-green-700 bg-green-50 border border-green-100 rounded-lg p-3">
                    <i data-lucide="mail" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    A Gmail confirmation was sent to <strong><?= htmlspecialchars($successData['email']) ?></strong>. We will also email you when staff verifies your request and when the status changes.
                </p>
                <?php elseif (!empty($successData['notify_email'])): ?>
                <p class="flex items-start gap-2 text-amber-700 bg-amber-50 border border-amber-100 rounded-lg p-3">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    We could not send the Gmail confirmation right now. Please save your tracking code and check Track Request. Staff can still email later updates once Gmail sending is configured.
                </p>
                <?php else: ?>
                <p class="flex items-start gap-2 text-amber-700 bg-amber-50 border border-amber-100 rounded-lg p-3">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    Please save your tracking code. You can always check status at the Track Request page.
                </p>
                <?php endif; ?>
            </div>

            <div class="flex flex-col sm:flex-row justify-center gap-3">
                <a href="track.php?code=<?= urlencode($successData['tracking_code']) ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-full text-xs font-bold text-center">Track My Request</a>
                <a href="index.php" class="back-home back-home--btn">Back to Home</a>
            </div>
        </div>
        <script>
            (function () {
                var code = <?= json_encode($successData['tracking_code']) ?>;
                var btn = document.getElementById('copy-tracking-btn');
                if (btn) {
                    btn.addEventListener('click', function () {
                        btn.disabled = true;
                        var orig = btn.innerHTML;
                        btn.innerHTML = 'Copying…';
                        navigator.clipboard.writeText(code).then(function () {
                            btn.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Copied!';
                            lucide.createIcons();
                            setTimeout(function () {
                                btn.disabled = false;
                                btn.innerHTML = orig;
                                lucide.createIcons();
                            }, 2000);
                        }).catch(function () {
                            btn.disabled = false;
                            btn.innerHTML = orig;
                        });
                    });
                }
            })();
        </script>

        <?php else: ?>
        <!-- Progress bar -->
        <div class="flex justify-center mb-10 px-2 sm:px-4 overflow-x-auto">
            <?php foreach ($stepLabels as $i => $s): ?>
            <?php if ($i <= 3): ?>
            <div class="flex items-center flex-1 last:flex-none min-w-[4.5rem]">
                <div class="flex flex-col items-center">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center border-2
                        <?= $i < $step ? 'bg-blue-600 border-blue-600 text-white' : ($i === $step ? 'border-blue-600 text-blue-600 bg-white' : 'border-gray-200 text-gray-300 bg-white') ?>">
                        <?php if ($i < $step): ?>
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <?php else: ?>
                        <i data-lucide="<?= $s['icon'] ?>" class="w-4 h-4"></i>
                        <?php endif; ?>
                    </div>
                    <span class="hidden sm:block text-[9px] font-bold uppercase mt-1.5 tracking-wide text-center <?= $i <= $step ? 'step-active' : 'step-pending' ?>"><?= $s['label'] ?></span>
                </div>
                <?php if ($i < 3): ?>
                <div class="flex-1 h-0.5 mx-2 sm:mx-3 mb-0 sm:mb-5 <?= $i < $step ? 'bg-blue-600' : 'bg-gray-200' ?>"></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="bg-white rounded-2xl border border-gray-100 p-8 shadow-sm">
            <h1 class="text-xl font-black text-slate-900 mb-1"><?= htmlspecialchars($stepTitles[$step]['title'] ?? 'Request') ?></h1>
            <p class="text-gray-400 text-sm mb-6"><?= htmlspecialchars($stepTitles[$step]['subtitle'] ?? '') ?></p>

            <?php if ($error): ?>
            <div class="mb-4 p-3 bg-red-50 border border-red-100 text-red-600 text-sm rounded-lg"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if ($step === 1): ?>
            <form method="POST" class="space-y-5" id="identificationForm">
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="record_verified" id="recordVerified" value="<?= $recordVerified ? '1' : '0' ?>">
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-blue-500"></i> Current Service Type
                    </label>
                    <select name="document_type" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="">Select document</option>
                        <?php foreach ($validTypes as $t): ?>
                        <option value="<?= $t ?>" <?= ($draft['document_type'] ?? '') === $t ? 'selected' : '' ?>><?= documentTypeLabel($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="user" class="w-3.5 h-3.5 text-blue-500"></i> Full Name on Record
                    </label>
                    <div class="flex gap-2">
                        <input type="text" id="citizenNameInput" name="citizen_name" required value="<?= htmlspecialchars($draft['citizen_name'] ?? '') ?>"
                               class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <button type="button" id="checkRecordBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-xl text-xs font-bold whitespace-nowrap shrink-0">
                            Check Record
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">We verify that your name and date of birth are registered in LCRO civil records before you can continue.</p>
                    <p id="recordStatus" class="text-xs mt-2 <?= $recordVerified ? 'font-semibold text-green-600' : 'hidden' ?>"><?= $recordVerified ? 'Record found — you are registered with the Local Civil Registry Office.' : '' ?></p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-500"></i> Date of Birth
                    </label>
                    <input type="date" id="dateOfBirthInput" name="date_of_birth" required value="<?= htmlspecialchars($draft['date_of_birth'] ?? '') ?>"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="users" class="w-3.5 h-3.5 text-blue-500"></i> Sex
                    </label>
                    <div class="flex gap-3">
                        <?php foreach (['male' => 'Male', 'female' => 'Female'] as $val => $label): ?>
                        <label class="flex-1 cursor-pointer">
                            <input type="radio" name="sex" value="<?= $val ?>" class="sr-only peer" <?= ($draft['sex'] ?? '') === $val ? 'checked' : '' ?> required>
                            <span class="block text-center py-3 rounded-xl border text-sm font-semibold peer-checked:bg-blue-600 peer-checked:text-white peer-checked:border-blue-600 border-gray-200 text-gray-600 hover:border-blue-300 transition"><?= $label ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="flex justify-between items-center pt-4">
                    <a href="index.php" class="text-gray-400 text-sm flex items-center gap-1 hover:text-gray-600"><i data-lucide="chevron-left" class="w-4 h-4"></i> Back</a>
                    <button type="submit" name="action" value="next" id="step1ContinueBtn" class="bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white px-8 py-3 rounded-full text-sm font-bold flex items-center gap-2" data-loading-text="Saving…" <?= $recordVerified ? '' : 'disabled' ?>>Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                </div>
            </form>

            <?php elseif ($step === 2): ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-5" id="requirementsForm">
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="email_verified" id="emailVerified" value="<?= isGmailVerifiedInSession($draft['email'] ?? '') ? '1' : '0' ?>">
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="mail" class="w-3.5 h-3.5 text-blue-500"></i> Active Gmail Account
                    </label>
                    <div class="flex gap-2">
                        <input type="email" id="gmailInput" name="email" required placeholder="example@gmail.com"
                               value="<?= htmlspecialchars($draft['email'] ?? '') ?>"
                               class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <button type="button" id="verifyGmailBtn" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-3 rounded-xl text-xs font-bold whitespace-nowrap shrink-0">
                            Verify Gmail
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">We check that your @gmail.com account is active. If you agree to notifications, status updates are sent to this Gmail.</p>
                    <p id="gmailStatus" class="text-xs mt-2 hidden"></p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="clipboard-list" class="w-3.5 h-3.5 text-blue-500"></i> Purpose of Request
                    </label>
                    <select name="purpose" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="">Select Purpose</option>
                        <?php foreach ($purposeOptions as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($draft['purpose'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="phone" class="w-3.5 h-3.5 text-blue-500"></i> Cellphone Number
                    </label>
                    <input type="tel" name="phone" required placeholder="09XXXXXXXXX" pattern="09[0-9]{9}"
                           value="<?= htmlspecialchars($draft['phone'] ?? '') ?>"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <div>
                    <label class="text-[11px] font-bold text-gray-700 mb-2 block">Upload Valid IDs (National ID / Driver's License)</label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="upload-box rounded-xl p-6 text-center cursor-pointer block">
                            <input type="file" name="id_front" accept="image/*,.pdf" class="hidden" id="idFront" <?= empty($draft['id_front_path']) ? 'required' : '' ?>>
                            <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                            <p class="text-xs font-bold text-gray-600">Front Side</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="idFrontLabel"><?= !empty($draft['id_front_path']) ? 'Previously uploaded' : 'Click to upload ID' ?></p>
                        </label>
                        <label class="upload-box rounded-xl p-6 text-center cursor-pointer block">
                            <input type="file" name="id_back" accept="image/*,.pdf" class="hidden" id="idBack">
                            <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                            <p class="text-xs font-bold text-gray-600">Back Side (Optional)</p>
                            <p class="text-[10px] text-gray-400 mt-1" id="idBackLabel"><?= !empty($draft['id_back_path']) ? 'Previously uploaded' : 'Click to upload ID' ?></p>
                        </label>
                    </div>
                </div>
                <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                    <div class="flex gap-2 mb-2">
                        <i data-lucide="alert-circle" class="w-4 h-4 text-amber-500 shrink-0 mt-0.5"></i>
                        <div>
                            <p class="text-xs font-bold text-amber-800">Pickup Policy & Data Privacy</p>
                            <p class="text-[11px] text-amber-700 mt-1 leading-relaxed">Please visit the LGU office during business hours (8:00 AM - 5:00 PM) once notified. Bring your tracking code and a valid ID. By submitting this request, you agree to the processing of your personal data for civil registry purposes.</p>
                        </div>
                    </div>
                    <label class="flex items-center gap-2 mt-3 cursor-pointer">
                        <input type="checkbox" name="privacy_agreed" value="1" class="rounded border-amber-300 text-blue-600 focus:ring-blue-500" required>
                        <span class="text-xs font-semibold text-amber-800">I Agree to Data Privacy Notice</span>
                    </label>
                    <label class="flex items-start gap-2 mt-3 cursor-pointer">
                        <input type="checkbox" name="notify_email" value="1" id="notifyEmailCheckbox" class="mt-0.5 rounded border-amber-300 text-blue-600 focus:ring-blue-500" <?= !empty($draft['notify_email']) ? 'checked' : '' ?>>
                        <span class="text-xs font-semibold text-amber-800">Send Gmail notifications when my request is received, verified, updated, and 5 hours before my visit</span>
                    </label>
                </div>
                <div class="flex justify-between items-center pt-4">
                    <button type="submit" name="action" value="back" class="text-gray-400 text-sm flex items-center gap-1 hover:text-gray-600"><i data-lucide="chevron-left" class="w-4 h-4"></i> Back</button>
                    <button type="submit" name="action" value="next" id="step2ContinueBtn" class="bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white px-8 py-3 rounded-full text-sm font-bold flex items-center gap-2" data-loading-text="Saving…" <?= isGmailVerifiedInSession($draft['email'] ?? '') ? '' : 'disabled' ?>>Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                </div>
            </form>

            <?php elseif ($step === 3): ?>
            <form method="POST" class="space-y-5">
                <input type="hidden" name="step" value="3">
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-2">
                    <p class="text-xs text-blue-800"><strong><?= htmlspecialchars($draft['citizen_name'] ?? '') ?></strong> — <?= htmlspecialchars(documentTypeLabel($draft['document_type'] ?? '')) ?></p>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                            <i data-lucide="calendar" class="w-3.5 h-3.5 text-blue-500"></i> Preferred Date *
                        </label>
                        <input type="date" id="appointmentDateInput" name="appointment_date" required min="<?= date('Y-m-d') ?>"
                               value="<?= htmlspecialchars($draft['appointment_date'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                    <div>
                        <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                            <i data-lucide="clock" class="w-3.5 h-3.5 text-blue-500"></i> Preferred Time *
                        </label>
                        <input type="time" id="appointmentTimeInput" name="appointment_time" required min="08:00" max="17:00"
                               value="<?= htmlspecialchars($draft['appointment_time'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                </div>
                <p class="text-[11px] text-gray-400">Office hours: 8:00 AM – 5:00 PM (Monday to Friday). One booking per time slot.</p>
                <p id="slotAvailabilityStatus" class="hidden text-xs mt-2"></p>
                <div class="flex justify-between items-center pt-4">
                    <button type="submit" name="action" value="back" class="text-gray-400 text-sm flex items-center gap-1 hover:text-gray-600"><i data-lucide="chevron-left" class="w-4 h-4"></i> Back</button>
                    <button type="submit" name="action" value="next" data-appointment-submit class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-full text-sm font-bold flex items-center gap-2" data-loading-text="Submitting…">Submit Request <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <script>
        lucide.createIcons();

        (function () {
            var checkBtn = document.getElementById('checkRecordBtn');
            var nameInput = document.getElementById('citizenNameInput');
            var dobInput = document.getElementById('dateOfBirthInput');
            var recordVerified = document.getElementById('recordVerified');
            var recordStatus = document.getElementById('recordStatus');
            var continueBtn = document.getElementById('step1ContinueBtn');
            var form = document.getElementById('identificationForm');

            function setRecordStatus(text, type) {
                if (!recordStatus) return;
                recordStatus.classList.remove('hidden');
                recordStatus.textContent = text;
                recordStatus.className = 'text-xs mt-2 font-semibold ' + (
                    type === 'ok' ? 'text-green-600' : 'text-red-600'
                );
            }

            function setRecordVerified(ok) {
                if (recordVerified) recordVerified.value = ok ? '1' : '0';
                if (continueBtn) continueBtn.disabled = !ok;
            }

            function resetRecordCheck() {
                setRecordVerified(false);
                if (recordStatus) recordStatus.classList.add('hidden');
            }

            if (checkBtn && nameInput && dobInput) {
                checkBtn.addEventListener('click', function () {
                    var name = nameInput.value.trim();
                    var dob = dobInput.value.trim();
                    if (!name) {
                        setRecordStatus('Enter your full name on record first.', 'err');
                        return;
                    }
                    if (!dob) {
                        setRecordStatus('Enter your date of birth first.', 'err');
                        return;
                    }
                    setRecordVerified(false);
                    checkBtn.disabled = true;
                    checkBtn.textContent = 'Checking…';

                    var body = new FormData();
                    body.append('citizen_name', name);
                    body.append('date_of_birth', dob);

                    fetch('api/check_civil_record.php', { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.ok) {
                                setRecordVerified(true);
                                setRecordStatus(data.message || 'Record found.', 'ok');
                            } else {
                                setRecordVerified(false);
                                setRecordStatus(data.error || 'Record not found.', 'err');
                            }
                        })
                        .catch(function () {
                            setRecordVerified(false);
                            setRecordStatus('Network error. Try again.', 'err');
                        })
                        .finally(function () {
                            checkBtn.disabled = false;
                            checkBtn.textContent = 'Check Record';
                        });
                });
            }

            if (nameInput) nameInput.addEventListener('input', resetRecordCheck);
            if (dobInput) dobInput.addEventListener('input', resetRecordCheck);

            if (form) {
                form.addEventListener('submit', function (e) {
                    if (recordVerified && recordVerified.value !== '1') {
                        e.preventDefault();
                        setRecordStatus('Click Check Record before continuing.', 'err');
                    }
                });
            }
        })();

        (function () {
            var verifyBtn = document.getElementById('verifyGmailBtn');
            var gmailInput = document.getElementById('gmailInput');
            var emailVerified = document.getElementById('emailVerified');
            var gmailStatus = document.getElementById('gmailStatus');
            var continueBtn = document.getElementById('step2ContinueBtn');
            var form = document.getElementById('requirementsForm');

            function setStatus(text, type) {
                if (!gmailStatus) return;
                gmailStatus.classList.remove('hidden');
                gmailStatus.textContent = text;
                gmailStatus.className = 'text-xs mt-2 font-semibold ' + (
                    type === 'ok' ? 'text-green-600' : 'text-red-600'
                );
            }

            function setVerified(ok, email) {
                if (emailVerified) emailVerified.value = ok ? '1' : '0';
                if (continueBtn) continueBtn.disabled = !ok;
                if (ok && email && gmailInput) gmailInput.value = email;
            }

            if (verifyBtn && gmailInput) {
                verifyBtn.addEventListener('click', function () {
                    var email = gmailInput.value.trim();
                    if (!email) {
                        setStatus('Enter your Gmail address first.', 'err');
                        return;
                    }
                    setVerified(false);
                    verifyBtn.disabled = true;
                    verifyBtn.textContent = 'Checking…';

                    var body = new FormData();
                    body.append('email', email);

                    fetch('api/verify_email.php', { method: 'POST', body: body, credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            if (data.ok) {
                                setVerified(true, data.email);
                                setStatus(data.message || 'Gmail verified.', 'ok');
                            } else {
                                setVerified(false);
                                setStatus(data.error || 'Verification failed.', 'err');
                            }
                        })
                        .catch(function () {
                            setVerified(false);
                            setStatus('Network error. Try again.', 'err');
                        })
                        .finally(function () {
                            verifyBtn.disabled = false;
                            verifyBtn.textContent = 'Verify Gmail';
                        });
                });
            }

            if (gmailInput) {
                gmailInput.addEventListener('input', function () {
                    setVerified(false);
                    if (gmailStatus) gmailStatus.classList.add('hidden');
                });
            }

            if (form) {
                form.addEventListener('submit', function (e) {
                    if (emailVerified && emailVerified.value !== '1') {
                        e.preventDefault();
                        setStatus('Click Verify Gmail before continuing.', 'err');
                    }
                });
            }

            ['idFront', 'idBack'].forEach(function (id) {
                var input = document.getElementById(id);
                var label = document.getElementById(id + 'Label');
                if (input && label) {
                    input.addEventListener('change', function () {
                        if (input.files && input.files[0]) {
                            label.textContent = input.files[0].name;
                        }
                    });
                }
            });
        })();
    </script>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?php if ($step === 3): ?><script src="includes/appointment_slots.js"></script><?php endif; ?>
    <script src="includes/reminders.js"></script>
</body>
</html>
