<?php
/**
 * Citizen document request — multi-step wizard.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

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
$draftCitizenName = personNameFromRow($draft);
$error = null;
$trackingCode = null;
$success = !empty($_SESSION['request_success']);
$successData = $success ? $_SESSION['request_success'] : null;
if ($success) {
    unset($_SESSION['request_success']);
    $trackingCode = $successData['tracking_code'] ?? null;
}

$type = isset($_GET['type']) ? (string) $_GET['type'] : '';
if ($type !== '' && in_array($type, $validTypes, true)) {
    $draft['document_type'] = $type;
}

if (isset($_GET['entry']) && (string) $_GET['entry'] === 'hero') {
    $_SESSION['request_wizard_flow'] = 'hero';
    $heroEntryStep = isset($_GET['step']) ? max(1, (int) $_GET['step']) : 1;
    if ($heroEntryStep === 1) {
        unset($draft['document_type']);
        unset($_SESSION['alcros_civil_record_verified']);
    }
} elseif ($type !== '' && in_array($type, $validTypes, true)) {
    $_SESSION['request_wizard_flow'] = 'card';
} elseif (!isset($_SESSION['request_wizard_flow'])) {
    $_SESSION['request_wizard_flow'] = 'hero';
}
$isHeroFlow = (($_SESSION['request_wizard_flow'] ?? 'hero') === 'hero');
$requirementsStep = $isHeroFlow ? 3 : 2;
$appointmentStep = $isHeroFlow ? 4 : 3;
$successStep = $isHeroFlow ? 5 : 4;

$requestWizardMaxStep = $successStep;
$step = max(1, min($requestWizardMaxStep, (int) ($_GET['step'] ?? $_POST['step'] ?? 1)));
if (!empty($_GET['success']) && $success) {
    $step = $requestWizardMaxStep;
}
$recordVerified = isRequestDraftIdentificationVerified($draft);

if ($step > 1 && $step < $requestWizardMaxStep
    && (trim((string) ($draft['requester_first_name'] ?? '')) === ''
        || trim((string) ($draft['requester_last_name'] ?? '')) === '')) {
    header('Location: request.php?step=1');
    exit;
}

$typeGateStep = $isHeroFlow ? 2 : 1;
if ($step > $typeGateStep && $step < $requestWizardMaxStep) {
    $draftDocumentType = (string) ($draft['document_type'] ?? '');
    if (!in_array($draftDocumentType, $validTypes, true)) {
        header('Location: request.php?step=1');
        exit;
    }
}

$recordGateStep = $isHeroFlow ? 3 : 2;
$recordRedirectStep = $isHeroFlow ? 2 : 1;
if ($step >= $recordGateStep && $step < $requestWizardMaxStep && !$recordVerified) {
    header('Location: request.php?step=' . $recordRedirectStep);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$success) {
    $postedStep = max(1, min($requestWizardMaxStep, (int) ($_POST['step'] ?? $step)));
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!validateCsrf(is_string($csrfToken) ? $csrfToken : '')) {
        $step = $postedStep;
        $error = 'Your session expired or the form is out of date. Please refresh this page and try again.';
    } else {
    $action = $_POST['action'] ?? 'next';

    if ($action === 'back') {
        $step = max(1, $step - 1);
        header('Location: request.php?step=' . $step);
        exit;
    }

    if ($isHeroFlow && $step === 1) {
        $documentType   = $_POST['document_type'] ?? '';
        $requesterParts = personNamePartsFromInput($_POST, 'requester_');

        if (($requesterNameError = validatePersonNameParts($requesterParts)) !== null) {
            $error = 'Please enter your first and last name as the person requesting this certificate.';
        } elseif (!in_array($documentType, $validTypes, true)) {
            $error = 'Please select a service type.';
        } else {
            $previousType = (string) ($draft['document_type'] ?? '');
            if ($previousType !== $documentType) {
                unset(
                    $draft['first_name'],
                    $draft['middle_name'],
                    $draft['last_name'],
                    $draft['husband_first_name'],
                    $draft['husband_middle_name'],
                    $draft['husband_last_name'],
                    $draft['wife_first_name'],
                    $draft['wife_middle_name'],
                    $draft['wife_last_name'],
                    $draft['date_of_birth'],
                    $draft['date_of_marriage'],
                    $draft['date_of_death'],
                    $draft['sex'],
                    $draft['citizen_name']
                );
                unset($_SESSION['alcros_civil_record_verified']);
            }
            $draft['document_type']         = $documentType;
            $draft['requester_first_name']  = $requesterParts['first_name'];
            $draft['requester_middle_name'] = $requesterParts['middle_name'];
            $draft['requester_last_name']   = $requesterParts['last_name'];
            header('Location: request.php?step=2');
            exit;
        }
    }

    $processingIdentification = ($isHeroFlow && $step === 2) || (!$isHeroFlow && $step === 1);
    if ($processingIdentification) {
        $requesterParts = personNamePartsFromInput($_POST, 'requester_');
        $documentType   = $_POST['document_type'] ?? ($draft['document_type'] ?? '');
        $sex            = verifiedCivilRecordSexFromSession();

        if (!$isHeroFlow && ($requesterNameError = validatePersonNameParts($requesterParts)) !== null) {
            $error = 'Please enter your first and last name as the person requesting this certificate.';
        } elseif (!in_array($documentType, $validTypes, true)) {
            $error = 'Please select a service type.';
        } elseif ($documentType === 'marriage') {
            $husbandParts   = personNamePartsFromInput($_POST, 'husband_');
            $wifeParts      = personNamePartsFromInput($_POST, 'wife_');
            $dateOfMarriage = trim($_POST['date_of_marriage'] ?? '');

            if (($nameError = validatePersonNameParts($husbandParts)) !== null) {
                $error = 'Please enter the husband\'s first and last name on record.';
            } elseif (($nameError = validatePersonNameParts($wifeParts)) !== null) {
                $error = 'Please enter the wife\'s first and last name on record.';
            } elseif ($dateOfMarriage === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfMarriage)) {
                $error = 'Please enter a valid date of marriage.';
            } elseif (!isMarriageIdentificationVerifiedInSession($husbandParts, $wifeParts, $dateOfMarriage)) {
                $error = 'Please check your civil registry record before continuing. If you are not registered, visit the LCRO office in person.';
                $recordVerified = false;
            } else {
                $draft['husband_first_name']  = $husbandParts['first_name'];
                $draft['husband_middle_name'] = $husbandParts['middle_name'];
                $draft['husband_last_name']   = $husbandParts['last_name'];
                $draft['wife_first_name']     = $wifeParts['first_name'];
                $draft['wife_middle_name']    = $wifeParts['middle_name'];
                $draft['wife_last_name']      = $wifeParts['last_name'];
                $draft['first_name']          = $husbandParts['first_name'];
                $draft['middle_name']         = $husbandParts['middle_name'];
                $draft['last_name']           = $husbandParts['last_name'];
                $draft['date_of_birth']       = null;
                $draft['date_of_death']       = null;
                $draft['date_of_marriage']    = $dateOfMarriage;
                $draft['sex']                 = $sex;
                $draft['document_type']       = $documentType;
                if (!$isHeroFlow) {
                    $draft['requester_first_name']  = $requesterParts['first_name'];
                    $draft['requester_middle_name'] = $requesterParts['middle_name'];
                    $draft['requester_last_name']   = $requesterParts['last_name'];
                }
                unset($draft['citizen_name']);
                header('Location: request.php?step=' . $requirementsStep);
                exit;
            }
        } elseif ($documentType === 'death') {
            $nameParts    = personNamePartsFromInput($_POST);
            $dateOfDeath  = trim($_POST['date_of_death'] ?? '');

            if (($nameError = validatePersonNameParts($nameParts)) !== null) {
                $error = $nameError;
            } elseif ($dateOfDeath === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfDeath)) {
                $error = 'Please enter a valid date of death.';
            } elseif (!isDeathIdentificationVerifiedInSession($nameParts, $dateOfDeath)) {
                $error = 'Please check your civil registry record before continuing. If you are not registered, visit the LCRO office in person.';
                $recordVerified = false;
            } else {
                $draft['first_name']        = $nameParts['first_name'];
                $draft['middle_name']       = $nameParts['middle_name'];
                $draft['last_name']         = $nameParts['last_name'];
                $draft['date_of_birth']     = null;
                $draft['date_of_death']     = $dateOfDeath;
                $draft['date_of_marriage']  = null;
                $draft['sex']               = $sex;
                $draft['document_type']     = $documentType;
                unset($draft['husband_first_name'], $draft['husband_middle_name'], $draft['husband_last_name']);
                unset($draft['wife_first_name'], $draft['wife_middle_name'], $draft['wife_last_name']);
                if (!$isHeroFlow) {
                    $draft['requester_first_name']  = $requesterParts['first_name'];
                    $draft['requester_middle_name'] = $requesterParts['middle_name'];
                    $draft['requester_last_name']   = $requesterParts['last_name'];
                }
                unset($draft['citizen_name']);
                header('Location: request.php?step=' . $requirementsStep);
                exit;
            }
        } else {
            $nameParts    = personNamePartsFromInput($_POST);
            $dateOfBirth  = $_POST['date_of_birth'] ?? '';
            $citizenName  = formatPersonName($nameParts['first_name'], $nameParts['middle_name'], $nameParts['last_name']);

            if (($nameError = validatePersonNameParts($nameParts)) !== null) {
                $error = $nameError;
            } elseif ($dateOfBirth === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
                $error = 'Please enter a valid date of birth.';
            } elseif (!isCivilRecordVerifiedInSession($citizenName, $dateOfBirth, $documentType)) {
                $error = 'Please check your civil registry record before continuing. If you are not registered, visit the LCRO office in person.';
                $recordVerified = false;
            } else {
                $draft['first_name']        = $nameParts['first_name'];
                $draft['middle_name']       = $nameParts['middle_name'];
                $draft['last_name']         = $nameParts['last_name'];
                $draft['date_of_birth']     = $dateOfBirth;
                $draft['date_of_marriage']  = null;
                $draft['date_of_death']     = null;
                $draft['sex']               = $sex;
                $draft['document_type']     = $documentType;
                unset($draft['husband_first_name'], $draft['husband_middle_name'], $draft['husband_last_name']);
                unset($draft['wife_first_name'], $draft['wife_middle_name'], $draft['wife_last_name']);
                if (!$isHeroFlow) {
                    $draft['requester_first_name']  = $requesterParts['first_name'];
                    $draft['requester_middle_name'] = $requesterParts['middle_name'];
                    $draft['requester_last_name']   = $requesterParts['last_name'];
                }
                unset($draft['citizen_name']);
                header('Location: request.php?step=' . $requirementsStep);
                exit;
            }
        }
    }

    if ($step === $requirementsStep) {
        $email         = trim($_POST['email'] ?? '');
        $purpose       = trim($_POST['purpose'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $notifyEmail = isset($_POST['notify_email']) && (string) $_POST['notify_email'] === '1';
        $notifySms   = isset($_POST['notify_sms']) && (string) $_POST['notify_sms'] === '1';

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
                    $draft['notify_email']    = 1;
                    $draft['notify_sms']      = $notifySms ? 1 : 0;
                    if ($frontPath) {
                        $draft['id_front_path'] = $frontPath;
                    }
                    if ($backPath) {
                        $draft['id_back_path'] = $backPath;
                    }
                    header('Location: request.php?step=' . $appointmentStep);
                    exit;
                }
            }
        }
    }

    if ($step === $appointmentStep) {
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

            $slotLocked = false;
            $dayLocked = false;
            try {
                $pdo = getDB();
                ensureCitizenNotifyColumns($pdo);

                if (!acquireAppointmentDayLock($pdo, $appointmentDate, 10, 'certificate')) {
                    $error = 'This date is busy with other bookings. Please try again in a moment.';
                } elseif (!acquireAppointmentSlotLock($pdo, $appointmentDate, $appointmentTime, 10, 'certificate')) {
                    releaseAppointmentDayLock($pdo, $appointmentDate, 'certificate');
                    $error = 'This time slot is being booked by someone else. Please choose another time.';
                } else {
                    $dayLocked = true;
                    $slotLocked = true;
                    $pdo->beginTransaction();

                    $bookingError = validateAppointmentBooking(
                        $pdo,
                        $appointmentDate,
                        $appointmentTime,
                        $draft['email'] ?? null,
                        true,
                        'certificate'
                    );
                    if ($bookingError !== null) {
                        $pdo->rollBack();
                        $error = $bookingError;
                    } else {
                        ensureVisitSlotAvailableForWrite(
                            $pdo,
                            $appointmentDate,
                            $appointmentTime,
                            null,
                            null,
                            true,
                            'certificate'
                        );

                        $trackingCode = generateTrackingCode();
                        $marriagePrintFill = null;
                        if (($draft['document_type'] ?? '') === 'marriage') {
                            $marriagePrintFill = json_encode([
                                'husband_first_name'  => $draft['husband_first_name'] ?? '',
                                'husband_middle_name' => $draft['husband_middle_name'] ?? '',
                                'husband_last_name'   => $draft['husband_last_name'] ?? '',
                                'wife_first_name'     => $draft['wife_first_name'] ?? '',
                                'wife_middle_name'    => $draft['wife_middle_name'] ?? '',
                                'wife_last_name'      => $draft['wife_last_name'] ?? '',
                            ], JSON_UNESCAPED_UNICODE);
                        }
                        $stmt = $pdo->prepare(
                            'INSERT INTO document_requests
                             (tracking_code, first_name, middle_name, last_name,
                              requester_first_name, requester_middle_name, requester_last_name,
                              date_of_birth, date_of_marriage, date_of_death, sex, email, email_verified, phone,
                              document_type, purpose, id_front_path, id_back_path, privacy_agreed, notify_email, notify_sms,
                              appointment_date, appointment_time, status, print_fill_data)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([
                            $trackingCode,
                            $draft['first_name'],
                            $draft['middle_name'] ?? null,
                            $draft['last_name'],
                            $draft['requester_first_name'],
                            $draft['requester_middle_name'] ?? null,
                            $draft['requester_last_name'],
                            !empty($draft['date_of_birth']) ? $draft['date_of_birth'] : null,
                            !empty($draft['date_of_marriage']) ? $draft['date_of_marriage'] : null,
                            !empty($draft['date_of_death']) ? $draft['date_of_death'] : null,
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
                            (int) ($draft['notify_sms'] ?? 0),
                            $draft['appointment_date'],
                            normalizeAppointmentTime($draft['appointment_time']),
                            'pending',
                            $marriagePrintFill,
                        ]);

                        $requestId = (int) $pdo->lastInsertId();
                        $pdo->commit();

                        $notifyPayload = array_merge($draft, [
                            'tracking_code'    => $trackingCode,
                            'document_label'   => documentTypeLabel($draft['document_type']),
                            'appointment_date' => $draft['appointment_date'],
                            'appointment_time' => $draft['appointment_time'],
                            'notify_email'     => (int) ($draft['notify_email'] ?? 0),
                            'notify_sms'       => (int) ($draft['notify_sms'] ?? 0),
                        ]);
                        $emailSent = notifyRequestSubmitted($notifyPayload);
                        require_once __DIR__ . '/includes/sms.php';
                        notifyRequestSubmittedSms($notifyPayload);
                        maybeSendVisitSoonEmail($pdo, 'document_requests', $requestId);

                        runReminderSchedulerIfDue($pdo, 60);

                        $_SESSION['request_success'] = [
                            'tracking_code'    => $trackingCode,
                            'citizen_name'     => personNameFromRow($draft),
                            'requester_name'   => personRequesterNameFromRow($draft),
                            'first_name'       => $draft['first_name'],
                            'middle_name'      => $draft['middle_name'] ?? '',
                            'last_name'        => $draft['last_name'],
                            'email'            => $draft['email'],
                            'document_label'   => documentTypeLabel($draft['document_type']),
                            'appointment_date' => $draft['appointment_date'],
                            'appointment_time' => $draft['appointment_time'],
                            'email_sent'       => $emailSent,
                            'notify_email'     => (int) ($draft['notify_email'] ?? 0),
                        ];
                        unset($_SESSION['request_draft']);
                        header('Location: request.php?step=' . $successStep . '&success=1');
                        exit;
                    }
                }
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Could not submit request. ' . dbConnectionHelpMessage();
            } finally {
                if ($slotLocked) {
                    releaseAppointmentSlotLock($pdo, $appointmentDate, $appointmentTime, 'certificate');
                }
                if ($dayLocked) {
                    releaseAppointmentDayLock($pdo, $appointmentDate, 'certificate');
                }
            }
        }
    }
    }
}

$stepLabelsHero = [
    1 => ['key' => 'requester',        'label' => 'Request details',  'icon' => 'user-check'],
    2 => ['key' => 'identification', 'label' => 'Identification',   'icon' => 'user'],
    3 => ['key' => 'requirements',   'label' => 'Requirements',     'icon' => 'mail'],
    4 => ['key' => 'appointment',    'label' => 'Appointment',      'icon' => 'calendar'],
    5 => ['key' => 'finish',         'label' => 'Finish',           'icon' => 'check'],
];
$stepLabelsCard = [
    1 => ['key' => 'identification', 'label' => 'Identification',   'icon' => 'user'],
    2 => ['key' => 'requirements',   'label' => 'Requirements',     'icon' => 'mail'],
    3 => ['key' => 'appointment',    'label' => 'Appointment',      'icon' => 'calendar'],
    4 => ['key' => 'finish',         'label' => 'Finish',           'icon' => 'check'],
];
$stepLabels = $isHeroFlow ? $stepLabelsHero : $stepLabelsCard;

$stepTitlesHero = [
    1 => ['title' => 'REQUEST DETAILS', 'subtitle' => 'Enter your name and choose the certificate you need.'],
    2 => ['title' => 'IDENTIFICATION', 'subtitle' => 'Verify the name on record with the Local Civil Registry Office.'],
    3 => ['title' => 'REQUIREMENTS CHECKLIST', 'subtitle' => 'Please provide required documents.'],
    4 => ['title' => 'SCHEDULE APPOINTMENT', 'subtitle' => 'Choose your preferred date and time for document pickup.'],
];
$stepTitlesCard = [
    1 => ['title' => 'REQUEST DETAILS', 'subtitle' => 'Please provide your identity.'],
    2 => ['title' => 'REQUIREMENTS CHECKLIST', 'subtitle' => 'Please provide required documents.'],
    3 => ['title' => 'SCHEDULE APPOINTMENT', 'subtitle' => 'Choose your preferred date and time for document pickup.'],
];
$stepTitles = $isHeroFlow ? $stepTitlesHero : $stepTitlesCard;

$wizardProgressSteps = $isHeroFlow ? 4 : 3;
$showRequestStart = $isHeroFlow && $step === 1;
$showHeroIdentification = $isHeroFlow && $step === 2;
$showCardIdentification = !$isHeroFlow && $step === 1;
$showRequirements = ($step === $requirementsStep);
$showAppointment = ($step === $appointmentStep);
$identBackStep = $isHeroFlow ? 2 : 1;

$requestDocumentLabel = !empty($draft['document_type'])
    ? documentTypeLabel($draft['document_type'])
    : 'Certificate';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Document - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorScriptTag('lucide.min.js') ?>
    <?= interFontTags() ?>
    <?= publicStylesheet('citizen-site') ?>
    <?= publicStylesheet('citizen-request') ?>
    <?= publicStylesheet('id-upload') ?>
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
                </div>
            </div>
        </div>
    </header>

    <main class="citizen-site-main">
        <section class="citizen-page-hero citizen-page-hero--compact">
            <div class="max-w-2xl mx-auto">
                <a href="index.php" class="back-home back-home--inline is-centered">
                    <i data-lucide="chevron-left" class="back-home__icon w-3 h-3"></i>
                    <span>Back to Home</span>
                </a>
                <h1>Request a <?= htmlspecialchars($requestDocumentLabel) ?></h1>
                <p>Complete each step below to submit your document request online.</p>
            </div>
        </section>

        <section class="max-w-2xl mx-auto px-4 pb-12">
        <?php if ($success && $step === $successStep && $successData): ?>
        <div class="flex justify-center mb-10">
            <?php foreach ($stepLabels as $i => $s): ?>
            <div class="flex items-center <?= $i < $successStep ? 'flex-1' : '' ?>">
                <div class="flex flex-col items-center">
                    <div class="w-8 h-8 rounded-full citizen-request-progress-dot is-done flex items-center justify-center">
                        <i data-lucide="check" class="w-4 h-4"></i>
                    </div>
                    <span class="text-[9px] font-bold uppercase mt-1 citizen-request-step-done"><?= $s['label'] ?></span>
                </div>
                <?php if ($i < $successStep): ?><div class="flex-1 h-0.5 citizen-request-progress-line is-done mx-2 mb-4"></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="citizen-request-card p-4 sm:p-6 md:p-8 lg:p-10">
            <div class="text-center mb-8">
                <div class="w-16 h-16 bg-green-100 text-green-600 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i data-lucide="check-circle" class="w-8 h-8"></i>
                </div>
                <h1 class="text-2xl font-black text-slate-900 mb-2">Request Submitted Successfully</h1>
                <p class="text-gray-500 text-sm">
                    Thank you, <strong><?= htmlspecialchars($successData['requester_name'] ?? $successData['citizen_name']) ?></strong>.
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
                <p class="citizen-request-notice citizen-request-notice--success">
                    <i data-lucide="mail" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>A Gmail confirmation was sent to <strong><?= htmlspecialchars($successData['email']) ?></strong>. We will also email you when staff verifies your request and when the status changes.</span>
                </p>
                <?php elseif (!empty($successData['notify_email'])): ?>
                <p class="citizen-request-notice citizen-request-notice--warn">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>We could not send the Gmail confirmation right now. Please save your tracking code and check Track Request. Staff can still email later updates once Gmail sending is configured.</span>
                </p>
                <?php else: ?>
                <p class="citizen-request-notice citizen-request-notice--warn">
                    <i data-lucide="alert-circle" class="w-4 h-4 shrink-0 mt-0.5"></i>
                    <span>Gmail notifications were not enabled for this request, so confirmation and visit reminders will not be emailed. Please save your tracking code and use <strong>Track</strong> to follow your status.</span>
                </p>
                <?php endif; ?>
            </div>

            <div id="citizen-success-cancel-wrap" class="citizen-success-cancel citizen-success-cancel--actions"
                 data-cancel-type="request"
                 data-cancel-code="<?= htmlspecialchars($successData['tracking_code'], ENT_QUOTES) ?>"
                 data-cancel-label="Cancel Request">
                <?= publicCsrfField() ?>
                <div class="flex flex-col sm:flex-row justify-center gap-3">
                    <button type="button" id="citizen-success-cancel-btn" class="citizen-success-cancel__btn citizen-success-cancel__btn--action">Cancel Request</button>
                    <button type="button" data-open-track data-track-code="<?= htmlspecialchars($successData['tracking_code'], ENT_QUOTES) ?>" class="citizen-btn-gold px-6 py-3 rounded-full text-xs text-center">Track My Request</button>
                </div>
            </div>
        </div>
        <?= pageConfigJson([
            'trackingCode' => $successData['tracking_code'],
            'cancelType'   => 'request',
            'canCancel'    => true,
        ], 'request-success-config') ?>
        <?= scriptTag('core/page-config.js') ?>
        <?= scriptTag('public/request-success.js') ?>

        <?php else: ?>
        <?php $identDocType = (string) ($draft['document_type'] ?? ''); ?>
        <p class="citizen-request-step-mobile sm:hidden text-center text-[10px] font-bold uppercase tracking-wide mb-3 citizen-request-step-active">
            Step <?= min($step, $wizardProgressSteps) ?> of <?= $wizardProgressSteps ?> — <?= htmlspecialchars($stepLabels[$step]['label'] ?? '') ?>
        </p>
        <div class="flex justify-center mb-6 sm:mb-10 px-2 sm:px-4 overflow-x-auto">
            <?php foreach ($stepLabels as $i => $s): ?>
            <?php if ($i <= $wizardProgressSteps): ?>
            <div class="flex items-center flex-1 last:flex-none min-w-[4.5rem]">
                <div class="flex flex-col items-center">
                    <?php
                    $dotClass = $i < $step ? 'is-done' : ($i === $step ? 'is-active' : 'is-pending');
                    $labelClass = $i <= $step ? ($i < $step ? 'citizen-request-step-done' : 'citizen-request-step-active') : 'citizen-request-step-pending';
                    ?>
                    <div class="w-8 h-8 rounded-full flex items-center justify-center border-2 citizen-request-progress-dot <?= $dotClass ?>">
                        <?php if ($i < $step): ?>
                        <i data-lucide="check" class="w-4 h-4"></i>
                        <?php else: ?>
                        <i data-lucide="<?= $s['icon'] ?>" class="w-4 h-4"></i>
                        <?php endif; ?>
                    </div>
                    <span class="hidden sm:block text-[9px] font-bold uppercase mt-1.5 tracking-wide text-center <?= $labelClass ?>"><?= $s['label'] ?></span>
                </div>
                <?php if ($i < $wizardProgressSteps): ?>
                <div class="flex-1 h-0.5 mx-2 sm:mx-3 mb-0 sm:mb-5 citizen-request-progress-line <?= $i < $step ? 'is-done' : 'is-pending' ?>"></div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>

        <div class="citizen-request-card p-4 sm:p-6 md:p-8">
            <h1 class="text-xl font-black text-slate-900 mb-1"><?= htmlspecialchars($stepTitles[$step]['title'] ?? 'Request') ?></h1>
            <p class="text-gray-400 text-sm mb-6"><?= htmlspecialchars($stepTitles[$step]['subtitle'] ?? '') ?></p>

            <?php if ($showRequestStart): ?>
            <form method="POST" class="space-y-5" id="requestStartForm" data-no-loading>
                <?= publicCsrfField() ?>
                <input type="hidden" name="step" value="1">
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="user-check" class="w-3.5 h-3.5 text-blue-500"></i> Your Name (Requester) *
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="requesterNameGrid">
                        <input type="text" id="requesterFirstNameInput" name="requester_first_name" required placeholder="First name"
                               value="<?= htmlspecialchars($draft['requester_first_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <input type="text" id="requesterMiddleNameInput" name="requester_middle_name" placeholder="Middle name(Optional)"
                               value="<?= htmlspecialchars($draft['requester_middle_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <input type="text" id="requesterLastNameInput" name="requester_last_name" required placeholder="Last name"
                               value="<?= htmlspecialchars($draft['requester_last_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">Full name of the person submitting this request. This may differ from the name on the certificate.</p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-blue-500"></i> Select Certificate Type *
                    </label>
                    <?php $heroStartDocType = (string) ($draft['document_type'] ?? ''); ?>
                    <select name="document_type" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="" disabled hidden <?= $heroStartDocType === '' ? 'selected' : '' ?>>Select Certificate Type</option>
                        <?php foreach ($validTypes as $t): ?>
                        <option value="<?= $t ?>" <?= $heroStartDocType === $t ? 'selected' : '' ?>><?= documentTypeLabel($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="citizen-form-actions">
                    <a href="index.php" class="back-home back-home--step">
                        <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                        <span>Back</span>
                    </a>
                    <div class="citizen-form-actions__forward">
                        <button type="submit" name="action" value="next" id="step1ContinueBtn" class="citizen-btn-gold px-8 py-3 rounded-full text-sm inline-flex items-center gap-2 ml-auto" data-loading-text="Saving…">Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </form>

            <?php elseif ($showCardIdentification): ?>
            <?php $identDocType = (string) ($draft['document_type'] ?? ''); ?>
            <form method="POST" class="space-y-5" id="identificationForm">
                <?= publicCsrfField() ?>
                <input type="hidden" name="step" value="1">
                <input type="hidden" name="record_verified" id="recordVerified" value="<?= $recordVerified ? '1' : '0' ?>">
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="user-check" class="w-3.5 h-3.5 text-blue-500"></i> Your Name (Requester) *
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3" id="requesterNameGrid">
                        <input type="text" id="requesterFirstNameInput" name="requester_first_name" required placeholder="First name"
                               value="<?= htmlspecialchars($draft['requester_first_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <input type="text" id="requesterMiddleNameInput" name="requester_middle_name" placeholder="Middle name(Optional)"
                               value="<?= htmlspecialchars($draft['requester_middle_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <input type="text" id="requesterLastNameInput" name="requester_last_name" required placeholder="Last name"
                               value="<?= htmlspecialchars($draft['requester_last_name'] ?? '') ?>"
                               class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">Full name of the person submitting this request. This may differ from the name on the certificate.</p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="file-text" class="w-3.5 h-3.5 text-blue-500"></i> Current Service Type *
                    </label>
                    <select name="document_type" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="" disabled <?= empty($draft['document_type']) ? 'selected' : '' ?>>Select document</option>
                        <?php foreach ($validTypes as $t): ?>
                        <option value="<?= $t ?>" <?= ($draft['document_type'] ?? '') === $t ? 'selected' : '' ?>><?= documentTypeLabel($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php require __DIR__ . '/includes/request_identification_fields.php'; ?>
                <div class="citizen-form-actions">
                    <a href="index.php" class="back-home back-home--step">
                        <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                        <span>Back</span>
                    </a>
                    <div class="citizen-form-actions__forward">
                        <button type="submit" name="action" value="next" id="step1ContinueBtn" class="citizen-btn-gold px-8 py-3 rounded-full text-sm inline-flex items-center gap-2 ml-auto" data-loading-text="Saving…">Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </form>

            <?php elseif ($showHeroIdentification): ?>
            <?php $identDocType = (string) ($draft['document_type'] ?? ''); ?>
            <form method="POST" class="space-y-5" id="identificationForm">
                <?= publicCsrfField() ?>
                <input type="hidden" name="step" value="2">
                <input type="hidden" name="document_type" value="<?= htmlspecialchars($identDocType) ?>">
                <input type="hidden" name="record_verified" id="recordVerified" value="<?= $recordVerified ? '1' : '0' ?>">
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-1">
                    <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wide mb-1">Requester</p>
                    <p class="text-sm font-bold text-blue-900"><?= htmlspecialchars(personRequesterNameFromRow($draft)) ?></p>
                    <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wide mt-3 mb-1">Service</p>
                    <p class="text-sm font-semibold text-blue-900"><?= htmlspecialchars(documentTypeLabel($identDocType)) ?></p>
                </div>
                <?php require __DIR__ . '/includes/request_identification_fields.php'; ?>
                <div class="citizen-form-actions">
                    <a href="request.php?step=1" class="back-home back-home--step">
                        <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                        <span>Back</span>
                    </a>
                    <div class="citizen-form-actions__forward">
                        <button type="submit" name="action" value="next" id="step2ContinueBtn" class="citizen-btn-gold px-8 py-3 rounded-full text-sm inline-flex items-center gap-2 ml-auto" data-loading-text="Saving…">Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </form>

            <?php elseif ($showRequirements): ?>
            <?php $draftGmailVerified = isGmailVerifiedInSession($draft['email'] ?? ''); ?>
            <form method="POST" enctype="multipart/form-data" class="space-y-5" id="requirementsForm">
                <?= publicCsrfField() ?>
                <input type="hidden" name="step" value="<?= (int) $requirementsStep ?>">
                <input type="hidden" name="email_verified" id="emailVerified" value="<?= isGmailVerifiedInSession($draft['email'] ?? '') ? '1' : '0' ?>">
                <input type="hidden" name="privacy_agreed" value="1">
                <input type="hidden" name="notify_email" id="notifyEmailHidden" value="<?= !empty($draft['notify_email']) ? '1' : '0' ?>">
                <input type="hidden" name="notify_sms" id="notifySmsHidden" value="<?= !empty($draft['notify_sms']) ? '1' : '0' ?>">
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="mail" class="w-3.5 h-3.5 text-blue-500"></i> Active Gmail Account *
                    </label>
                    <div class="flex gap-2">
                        <input type="email" id="gmailInput" name="email" required placeholder="example@gmail.com"
                               value="<?= htmlspecialchars($draft['email'] ?? '') ?>"
                               class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <button type="button" id="verifyGmailBtn" class="citizen-btn-navy px-4 py-3 rounded-xl text-xs whitespace-nowrap shrink-0<?= $draftGmailVerified ? ' opacity-80 cursor-default' : '' ?>"<?= $draftGmailVerified ? ' disabled aria-disabled="true"' : '' ?>>
                            <?= $draftGmailVerified ? 'Verified' : 'Verify Gmail' ?>
                        </button>
                    </div>
                    <p class="text-[10px] text-gray-500 mt-1">We check that your @gmail.com account is active. If you agree to notifications, status updates are sent to this Gmail.</p>
                    <p id="gmailStatus" class="text-xs mt-2 <?= $draftGmailVerified ? 'font-semibold text-green-600' : 'hidden' ?>"><?= $draftGmailVerified ? 'Gmail verified — this is an active Google account.' : '' ?></p>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="clipboard-list" class="w-3.5 h-3.5 text-blue-500"></i> Purpose of Request *
                    </label>
                    <select name="purpose" required class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                        <option value="" disabled <?= empty($draft['purpose']) ? 'selected' : '' ?>>Select purpose</option>
                        <?php foreach ($purposeOptions as $key => $label): ?>
                        <option value="<?= $key ?>" <?= ($draft['purpose'] ?? '') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-[11px] font-bold text-gray-700 mb-1.5">
                        <i data-lucide="phone" class="w-3.5 h-3.5 text-blue-500"></i> Cellphone Number *
                    </label>
                    <input type="tel" name="phone" required placeholder="09XXXXXXXXX" pattern="09[0-9]{9}"
                           value="<?= htmlspecialchars($draft['phone'] ?? '') ?>"
                           class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                </div>
                <div>
                    <label class="text-[11px] font-bold text-gray-700 mb-2 block">Upload Valid IDs (National ID / Driver's License) *</label>
                    <div class="grid grid-cols-2 gap-4">
                        <label class="citizen-id-upload" data-id-upload>
                            <input type="file" name="id_front" accept="image/*,.pdf" class="hidden" id="idFront" <?= empty($draft['id_front_path']) ? 'required' : '' ?>>
                            <div class="citizen-id-upload__empty" data-id-upload-empty>
                                <i data-lucide="upload" class="w-6 h-6 text-gray-300 mx-auto mb-2"></i>
                                <p class="text-xs font-bold text-gray-600">Front Side</p>
                                <p class="text-[10px] text-gray-400 mt-1" id="idFrontLabel"><?= !empty($draft['id_front_path']) ? 'Previously uploaded' : 'Click to upload ID' ?></p>
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
                                <p class="text-[10px] text-gray-400 mt-1" id="idBackLabel"><?= !empty($draft['id_back_path']) ? 'Previously uploaded' : 'Click to upload ID' ?></p>
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
                <div class="citizen-form-actions">
                    <a href="request.php?step=<?= (int) $identBackStep ?>" class="back-home back-home--step">
                        <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                        <span>Back</span>
                    </a>
                    <div class="citizen-form-actions__forward">
                        <button type="submit" name="action" value="next" id="step3ContinueBtn" class="citizen-btn-gold px-8 py-3 rounded-full text-sm inline-flex items-center gap-2 ml-auto" data-loading-text="Saving…">Continue <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </form>

            <?php elseif ($showAppointment): ?>
            <form method="POST" class="space-y-5" id="requestScheduleForm" data-slot-type="certificate">
                <?= publicCsrfField() ?>
                <input type="hidden" name="step" value="<?= (int) $appointmentStep ?>">
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-2">
                    <?php if (($draft['document_type'] ?? '') === 'marriage'): ?>
                    <p class="text-xs text-blue-800">
                        <strong><?= htmlspecialchars(formatPersonName($draft['husband_first_name'] ?? '', $draft['husband_middle_name'] ?? null, $draft['husband_last_name'] ?? '')) ?></strong>
                        &amp;
                        <strong><?= htmlspecialchars(formatPersonName($draft['wife_first_name'] ?? '', $draft['wife_middle_name'] ?? null, $draft['wife_last_name'] ?? '')) ?></strong>
                        — <?= htmlspecialchars(documentTypeLabel($draft['document_type'] ?? '')) ?>
                    </p>
                    <?php elseif (($draft['document_type'] ?? '') === 'death'): ?>
                    <p class="text-xs text-blue-800"><strong><?= htmlspecialchars($draftCitizenName) ?></strong> — <?= htmlspecialchars(documentTypeLabel($draft['document_type'] ?? '')) ?><?php if (!empty($draft['date_of_death'])): ?> · <?= htmlspecialchars(formatDateDisplay($draft['date_of_death'])) ?><?php endif; ?></p>
                    <?php else: ?>
                    <p class="text-xs text-blue-800"><strong><?= htmlspecialchars($draftCitizenName) ?></strong> — <?= htmlspecialchars(documentTypeLabel($draft['document_type'] ?? '')) ?></p>
                    <?php endif; ?>
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
                        <select id="appointmentTimeInput" name="appointment_time" required
                                class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500" disabled>
                            <option value="" disabled <?= empty($draft['appointment_time']) ? 'selected' : '' ?>>Select a time slot</option>
                            <?php if (!empty($draft['appointment_time'])): ?>
                            <option value="<?= htmlspecialchars(substr(normalizeAppointmentTime($draft['appointment_time']), 0, 5)) ?>" selected>
                                <?= htmlspecialchars(formatAppointmentSlotLabel($draft['appointment_time'])) ?>
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                </div>
                <p class="text-[11px] text-gray-400">Certificate pickup visits: Monday to Friday only, excluding holidays. Morning 8:00 AM–12:00 NN and afternoon 1:00–5:00 PM, every 10 minutes. Lunch break 12:00–1:00 PM. One booking per slot.</p>
                <p id="slotAvailabilityStatus" class="hidden text-xs mt-2"></p>
                <div class="citizen-form-actions">
                    <a href="request.php?step=<?= (int) $requirementsStep ?>" class="back-home back-home--step">
                        <i data-lucide="chevron-left" class="back-home__icon w-4 h-4"></i>
                        <span>Back</span>
                    </a>
                    <div class="citizen-form-actions__forward">
                        <button type="submit" name="action" value="next" id="step4SubmitBtn" data-appointment-submit class="citizen-btn-gold px-8 py-3 rounded-full text-sm inline-flex items-center gap-2 ml-auto" data-loading-text="Submitting…">Submit Request <i data-lucide="chevron-right" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        </section>
    </main>

    <?= actionCoreScripts() ?>
    <?= actionResultScript($error ? ['error', $error] : null) ?>
    <?= scriptTag('public/citizen-site.js') ?>
    <?= scriptTag('public/forms.js') ?>
    <?php require __DIR__ . '/includes/track_floating.php'; ?>
    <?= scriptTag('public/track-floating.js') ?>
    <?php require __DIR__ . '/includes/privacy_agreement.php'; ?>
    <?php require __DIR__ . '/includes/notification_consent.php'; ?>
    <?php if ($showAppointment): ?>
    <script type="application/json" id="alcrosOfficeHolidayConfig"><?= json_encode(officeHolidayClientConfig(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?></script>
    <?= scriptTag('public/appointment-slots.js') ?>
    <?php endif; ?>
    <?= lucideInitScript() ?>
</body>
</html>
