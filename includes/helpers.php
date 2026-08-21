<?php
require_once __DIR__ . '/../config/database.php';

function generateCode(string $prefix, int $length = 6): string
{
    return $prefix . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, $length));
}

function generateTrackingCode(): string
{
    return generateCode('ALR', 8);
}

function generateAppointmentCode(): string
{
    return generateCode('APT', 6);
}

function generateTicketNumber(PDO $pdo, string $purpose = 'walk_in'): string
{
    $prefix = match ($purpose) {
        'walk_in'        => 'W',
        'appointment'    => 'A',
        'document_claim' => 'C',
        default          => 'Q',
    };
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM queue_tickets WHERE DATE(created_at) = CURDATE() AND purpose = ?'
    );
    $stmt->execute([$purpose]);
    $count = (int) $stmt->fetchColumn() + 1;
    return $prefix . str_pad((string) $count, 3, '0', STR_PAD_LEFT);
}

function queuePurposeConfig(): array
{
    return [
        'walk_in' => [
            'label'       => 'Walk-in',
            'table'       => 1,
            'description' => 'General inquiries & new requests',
            'icon'        => 'users',
            'color'       => 'orange',
            'border'      => 'border-orange-400',
            'bg'          => 'bg-orange-50',
            'text'        => 'text-orange-600',
            'btn'         => 'bg-orange-600 hover:bg-orange-700',
        ],
        'appointment' => [
            'label'       => 'Appointment',
            'table'       => 2,
            'description' => 'Scheduled visits & consultations',
            'icon'        => 'calendar-days',
            'color'       => 'blue',
            'border'      => 'border-blue-400',
            'bg'          => 'bg-blue-50',
            'text'        => 'text-blue-600',
            'btn'         => 'bg-blue-600 hover:bg-blue-700',
        ],
        'document_claim' => [
            'label'       => 'Claim',
            'table'       => 3,
            'description' => 'Document pickup & release',
            'icon'        => 'package',
            'color'       => 'emerald',
            'border'      => 'border-emerald-400',
            'bg'          => 'bg-emerald-50',
            'text'        => 'text-emerald-600',
            'btn'         => 'bg-emerald-600 hover:bg-emerald-700',
        ],
    ];
}

function queueTableForPurpose(string $purpose): int
{
    return queuePurposeConfig()[$purpose]['table'] ?? 1;
}

function logActivity(?string $staffId, string $action, string $details = ''): void
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('INSERT INTO activity_logs (staff_id, action, details) VALUES (?, ?, ?)');
        $stmt->execute([$staffId, $action, $details]);
    } catch (PDOException $e) {
        // Non-fatal if logging fails
    }
}

function getSetting(string $key, string $default = ''): string
{
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        return $row ? (string) $row['setting_value'] : $default;
    } catch (PDOException $e) {
        return $default;
    }
}

function setSetting(string $key, string $value): void
{
    $pdo = getDB();
    $stmt = $pdo->prepare(
        'INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function getSiteSettings(): array
{
    return [
        'name'               => getSetting('site_name', 'ALCROS'),
        'office'             => getSetting('office_name', 'Local Civil Registrar Office (LCRO) of Aloran'),
        'address'            => getSetting('office_address', 'Municipal Hall, Aloran, Misamis Occidental, Philippines'),
        'phone'              => getSetting('office_phone', '+639473212350'),
        'email'              => getSetting('office_email', 'aloran@gov.ph'),
        'head'               => getSetting('office_head', 'ATTY. LOCAL CIVIL REGISTRAR'),
        'hours'              => getSetting('office_hours', '8:00 AM - 5:00 PM (Monday to Friday)'),
        'overview'           => getSetting('overview_text', 'This guide covers the requirements, steps, and fees for all core civil registration services handled by the <strong>{office}</strong>.'),
        'portal_title'       => getSetting('portal_title', 'ALCROS Online Request Portal'),
        'portal_description' => getSetting('portal_description', 'Request document submissions or track application statuses online.'),
    ];
}

function renderOverviewText(string $template, string $officeName): string
{
    $text = str_replace('{office}', htmlspecialchars($officeName), $template);
    if (strpos($text, '<') !== false) {
        return $text;
    }
    return nl2br(htmlspecialchars($text));
}

function documentTypeLabel(string $type): string
{
    $labels = [
        'birth'    => 'Birth Certificate',
        'death'    => 'Death Certificate',
        'marriage' => 'Marriage Certificate',
        'cenomar'  => 'CENOMAR',
    ];
    return $labels[$type] ?? ucfirst($type);
}

function requestStatusWorkflow(): array
{
    return ['pending', 'verified', 'ready', 'completed'];
}

function requestStatusOptions(): array
{
    return ['pending', 'verified', 'ready', 'completed', 'rejected'];
}

function requestStatusUpdateOptions(): array
{
    return ['verified', 'ready', 'completed', 'rejected'];
}

function normalizeRequestStatus(string $status): string
{
    return $status === 'processing' ? 'verified' : $status;
}

function requestStatusProgressIndex(string $status): int|false
{
    if ($status === 'rejected') {
        return false;
    }
    $idx = array_search(normalizeRequestStatus($status), requestStatusWorkflow(), true);

    return $idx === false ? false : (int) $idx;
}

function migrateLegacyProcessingStatus(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    $migrated = true;

    try {
        $pdo->exec("UPDATE document_requests SET status = 'verified' WHERE status = 'processing'");
    } catch (Throwable $e) {
        // ignore if migration cannot run
    }
}

function ensureCitizenNotifyColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT notify_email FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER privacy_agreed');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT reminder_sent_at FROM document_requests LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE document_requests ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT notify_email FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN notify_email TINYINT(1) NOT NULL DEFAULT 0 AFTER phone');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT reminder_sent_at FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN reminder_sent_at TIMESTAMP NULL DEFAULT NULL AFTER notify_email');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT source FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE appointments ADD COLUMN source VARCHAR(32) NOT NULL DEFAULT 'standalone' AFTER status");
            $pdo->exec('ALTER TABLE appointments ADD COLUMN tracking_code VARCHAR(20) DEFAULT NULL AFTER source');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->query('SELECT id_front_path FROM appointments LIMIT 1');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE appointments ADD COLUMN id_front_path VARCHAR(255) DEFAULT NULL AFTER tracking_code');
            $pdo->exec('ALTER TABLE appointments ADD COLUMN id_back_path VARCHAR(255) DEFAULT NULL AFTER id_front_path');
        } catch (Throwable $ignored) {
        }
    }

    try {
        $pdo->exec(
            "UPDATE appointments a
             INNER JOIN document_requests r
                ON r.appointment_date = a.appointment_date
               AND r.appointment_time = a.appointment_time
               AND (
                    (r.email IS NOT NULL AND r.email != '' AND r.email = a.email)
                    OR (r.citizen_name = a.citizen_name)
               )
             SET a.source = 'document_request', a.tracking_code = r.tracking_code
             WHERE a.source = 'standalone'
               AND (a.tracking_code IS NULL OR a.tracking_code = '')"
        );
    } catch (Throwable $ignored) {
    }
}

function deleteIdUploadFiles(?string ...$paths): void
{
    foreach ($paths as $rel) {
        $rel = trim((string) $rel);
        if ($rel === '') {
            continue;
        }
        $path = __DIR__ . '/../' . ltrim($rel, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

function deleteCompletedDocumentRequest(PDO $pdo, int $id): bool
{
    $stmt = $pdo->prepare(
        'SELECT tracking_code, status, id_front_path, id_back_path FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || normalizeRequestStatus((string) $row['status']) !== 'completed') {
        return false;
    }

    deleteIdUploadFiles($row['id_front_path'] ?? null, $row['id_back_path'] ?? null);

    $pdo->prepare('DELETE FROM document_requests WHERE id = ?')->execute([$id]);
    logActivity(staffId(), 'Request Deleted', 'Deleted completed request ' . $row['tracking_code']);

    return true;
}

function requestStatusBadge(string $status): string
{
    $status = normalizeRequestStatus($status);
    $classes = [
        'pending'    => 'bg-yellow-100 text-yellow-700',
        'verified'   => 'bg-blue-100 text-blue-700',
        'ready'      => 'bg-green-100 text-green-700',
        'completed'  => 'bg-gray-100 text-gray-600',
        'rejected'   => 'bg-red-100 text-red-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';
    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">' . htmlspecialchars($status) . '</span>';
}

function formatTimeAgo(string $datetime): string
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return $datetime;
    }
    return date('g:i A', $ts);
}

function formatDateDisplay(string $date): string
{
    $ts = strtotime($date);
    return $ts ? date('m/d/Y', $ts) : $date;
}

function getDocumentTypes(): array
{
    return [
        ['slug' => 'birth',    'label' => 'Birth Certificate',    'desc' => 'Request your official birth certificate document online.',    'icon' => 'users',     'iconBg' => 'bg-blue-100 text-blue-600'],
        ['slug' => 'death',    'label' => 'Death Certificate',    'desc' => 'Request your official death certificate document online.',    'icon' => 'activity',  'iconBg' => 'bg-teal-100 text-teal-600'],
        ['slug' => 'marriage', 'label' => 'Marriage Certificate', 'desc' => 'Request your official marriage certificate document online.', 'icon' => 'heart',     'iconBg' => 'bg-pink-100 text-pink-500'],
    ];
}

function getAppointmentServices(): array
{
    return [
        ['slug' => 'supplemental-report', 'label' => 'Supplemental Report', 'desc' => 'Add missing birth/death details to existing records.',       'icon' => 'file-signature', 'iconBg' => 'bg-orange-100 text-orange-500'],
        ['slug' => 'report-correction',   'label' => 'Report Correction',   'desc' => 'Correct clerical errors in names, dates, or typos.',          'icon' => 'search',         'iconBg' => 'bg-purple-100 text-purple-500'],
        ['slug' => 'legitimation',        'label' => 'Legitimation',        'desc' => 'Update child status to legitimate after parents marry.',     'icon' => 'users',          'iconBg' => 'bg-blue-100 text-blue-500'],
        ['slug' => 'acknowledgement',     'label' => 'Acknowledgement',     'desc' => 'Official parent acknowledgement of a child.',                'icon' => 'shield-check',   'iconBg' => 'bg-teal-100 text-teal-500'],
        ['slug' => 'certified-true-copy', 'label' => 'Certified True Copy', 'desc' => 'Request an official certified copy of any record.',          'icon' => 'copy',           'iconBg' => 'bg-gray-100 text-gray-600'],
        ['slug' => 'cenomar',             'label' => 'CENOMAR',             'desc' => 'Certificate of No Marriage — schedule an appointment.',        'icon' => 'file-text',      'iconBg' => 'bg-green-100 text-green-600'],
    ];
}

function appointmentServiceLabel(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    foreach (getAppointmentServices() as $svc) {
        if ($svc['slug'] === $value || strcasecmp($svc['label'], $value) === 0) {
            return $svc['label'];
        }
    }

    return ucwords(str_replace('-', ' ', $value));
}

function appointmentStatusWorkflow(): array
{
    return ['scheduled', 'confirmed', 'completed'];
}

function appointmentStatusUpdateOptions(): array
{
    return ['confirmed', 'completed', 'cancelled', 'no_show'];
}

function appointmentStatusLabel(string $status): string
{
    return match ($status) {
        'scheduled' => 'Scheduled',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Rejected',
        'no_show'   => 'No Show',
        default     => ucfirst(str_replace('_', ' ', $status)),
    };
}

function appointmentStatusMessage(string $status): string
{
    return match ($status) {
        'scheduled' => 'Your appointment is scheduled. Please arrive on time with a valid ID.',
        'confirmed' => 'Your appointment has been confirmed by our office.',
        'completed' => 'This appointment has been completed. Thank you for visiting ALCROS.',
        'cancelled' => 'This appointment was rejected. Contact the office to reschedule.',
        'no_show'   => 'You were marked as a no-show. Please contact the office to reschedule.',
        default     => 'Track your appointment status below.',
    };
}

function appointmentStatusBadge(string $status): string
{
    $classes = [
        'scheduled' => 'bg-blue-100 text-blue-700',
        'confirmed' => 'bg-purple-100 text-purple-700',
        'completed' => 'bg-gray-100 text-gray-600',
        'cancelled' => 'bg-red-100 text-red-700',
        'no_show'   => 'bg-amber-100 text-amber-700',
    ];
    $class = $classes[$status] ?? 'bg-gray-100 text-gray-600';

    return '<span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase ' . $class . '">'
        . htmlspecialchars(appointmentStatusLabel($status)) . '</span>';
}

function appointmentStatusProgressIndex(string $status): int|false
{
    if (in_array($status, ['cancelled', 'no_show'], true)) {
        return false;
    }
    $idx = array_search($status, appointmentStatusWorkflow(), true);

    return $idx === false ? false : (int) $idx;
}

function isAppointmentTrackingCode(string $code): bool
{
    return (bool) preg_match('/^APT-/i', $code);
}

function getPurposeOptions(): array
{
    return [
        'passport'      => 'Passport Application',
        'employment'    => 'Employment Requirement',
        'school'        => 'School Enrollment',
        'legal'         => 'Legal Proceedings',
        'insurance'     => 'Insurance Claim',
        'travel'        => 'Travel Abroad',
        'personal'      => 'Personal Record',
        'other'         => 'Other',
    ];
}

function isValidPhilippineMobile(string $phone): bool
{
    return (bool) preg_match('/^09\d{9}$/', $phone);
}

function isValidGmail(string $email): bool
{
    return (bool) preg_match('/^[a-zA-Z0-9._%+-]+@gmail\.com$/i', $email);
}

function normalizeGmail(string $email): string
{
    return strtolower(trim($email));
}

function markGmailVerified(string $email): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['alcros_email_verified'] = [
        'email'   => normalizeGmail($email),
        'expires' => time() + 7200,
    ];
}

function getFirebaseWebApiKey(): string
{
    $configFile = __DIR__ . '/../config/google.php';
    if (is_file($configFile) && !defined('FIREBASE_WEB_API_KEY')) {
        require_once $configFile;
    }
    return defined('FIREBASE_WEB_API_KEY') ? trim((string) FIREBASE_WEB_API_KEY) : '';
}

/** Same check Google uses on the signup form — username taken means Gmail is active. */
function gmailExistsViaSignupValidator(string $email): ?bool
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $username = explode('@', normalizeGmail($email))[0];
    if ($username === '') {
        return false;
    }

    $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    $signupUrl = 'https://accounts.google.com/signup/v2/createaccount?flowName=GlifWebSignIn&flowEntry=SignUp&hl=en';

    $ch = curl_init($signupUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HEADER         => true,
        CURLOPT_USERAGENT      => $userAgent,
    ]);
    $page = curl_exec($ch);
    curl_close($ch);

    if ($page === false) {
        return null;
    }

    preg_match_all('/^Set-Cookie:\s*([^;\r\n]+)/mi', $page, $cookieMatches);
    $cookieHeader = implode('; ', $cookieMatches[1] ?? []);
    if ($cookieHeader === '') {
        return null;
    }

    $payload = json_encode([
        'input01' => [
            'Input'        => 'GmailAddress',
            'GmailAddress' => $username,
            'FirstName'    => '',
            'LastName'     => '',
        ],
        'Locale' => 'en',
    ]);

    $ch = curl_init('https://accounts.google.com/InputValidator?resource=SignUp');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Origin: https://accounts.google.com',
            'Referer: ' . $signupUrl,
            'User-Agent: ' . $userAgent,
            'Cookie: ' . $cookieHeader,
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['input01']['Valid'])) {
        return null;
    }

    $valid = $data['input01']['Valid'];
    // Signup API: Valid=false → username already taken → active Gmail.
    if ($valid === false || $valid === 'false') {
        return true;
    }
    if ($valid === true || $valid === 'true') {
        return false;
    }

    return null;
}

/** Gmail MX servers used for SMTP verification. */
function gmailMxHosts(): array
{
    $hosts = [];
    if (getmxrr('gmail.com', $hosts) && $hosts !== []) {
        return $hosts;
    }
    return ['gmail-smtp-in.l.google.com'];
}

/**
 * Check mailbox via SMTP RCPT (no email is sent to the user).
 * Returns true = exists, false = not found, null = could not check.
 */
function smtpRcptCheck(string $host, string $email): ?bool
{
    $errno = 0;
    $errstr = '';
    $fp = @stream_socket_client('tcp://' . $host . ':25', $errno, $errstr, 10);
    if (!$fp) {
        return null;
    }

    stream_set_timeout($fp, 10);

    $read = static function () use ($fp): string {
        $data = '';
        while ($line = fgets($fp, 512)) {
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $data;
    };

    $write = static function (string $cmd) use ($fp): void {
        fwrite($fp, $cmd . "\r\n");
    };

    $banner = $read();
    if ($banner === '' || (int) substr($banner, 0, 3) !== 220) {
        fclose($fp);
        return null;
    }

    $write('EHLO alcros.local');
    if ((int) substr($read(), 0, 3) !== 250) {
        fclose($fp);
        return null;
    }

    $write('MAIL FROM:<>');
    if ((int) substr($read(), 0, 3) !== 250) {
        fclose($fp);
        return null;
    }

    $write('RCPT TO:<' . $email . '>');
    $rcpt = $read();
    $write('QUIT');
    fclose($fp);

    $code = (int) substr($rcpt, 0, 3);
    if (in_array($code, [250, 251], true)) {
        return true;
    }
    if (in_array($code, [550, 551, 552, 553, 554], true)) {
        return false;
    }

    return null;
}

function gmailExistsViaSmtp(string $email): ?bool
{
    foreach (gmailMxHosts() as $host) {
        $result = smtpRcptCheck($host, $email);
        if ($result !== null) {
            return $result;
        }
    }
    return null;
}

/** HTTPS check — works on localhost where port 25 is often blocked. */
function gmailExistsViaHttp(string $email): ?bool
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $url = 'https://mail.google.com/mail/gxlu?email=' . urlencode($email);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; ALCROS/1.0)',
        CURLOPT_HEADER         => true,
    ]);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($response === false || $errno !== 0) {
        return null;
    }

    if (preg_match('/^set-cookie:/im', $response)) {
        return true;
    }

    // Google no longer sends Set-Cookie on this endpoint — result is inconclusive, not "not found".
    if (preg_match('/^HTTP\/[\d.]+ 204/m', $response)) {
        return null;
    }

    if (preg_match('/^HTTP\/[\d.]+ (\d+)/m', $response, $match)) {
        $status = (int) $match[1];
        if ($status >= 200 && $status < 400) {
            return null;
        }
    }

    return null;
}

/** Firebase Identity Toolkit — reliable HTTPS check (works on XAMPP). */
function gmailExistsViaFirebase(string $email): ?bool
{
    $apiKey = getFirebaseWebApiKey();
    if ($apiKey === '') {
        return null;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $continueUri = $scheme . '://' . $host . '/';

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:createAuthUri?key=' . urlencode($apiKey);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'identifier'  => $email,
            'continueUri' => $continueUri,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }
    if (array_key_exists('registered', $data)) {
        return (bool) $data['registered'];
    }
    if (isset($data['error'])) {
        return null;
    }

    return null;
}

/** Returns true if active, false if not found, null if check failed. */
function gmailAccountIsActive(string $email): ?bool
{
    foreach ([
        'gmailExistsViaSignupValidator',
        'gmailExistsViaFirebase',
        'gmailExistsViaSmtp',
        'gmailExistsViaHttp',
    ] as $checker) {
        $result = $checker($email);
        if ($result !== null) {
            return $result;
        }
    }

    return null;
}

/** Verify typed Gmail for the request form — no Google Sign-In required. */
function verifyActiveGmailAccount(string $email): array
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $email = normalizeGmail($email);
    if (!isValidGmail($email)) {
        return ['ok' => false, 'error' => 'Please enter a valid @gmail.com address.'];
    }

    $active = gmailAccountIsActive($email);

    if ($active === true) {
        markGmailVerified($email);
        return [
            'ok'      => true,
            'email'   => $email,
            'message' => 'Gmail verified — this is an active Google account.',
        ];
    }

    if ($active === false) {
        return [
            'ok'    => false,
            'error' => 'This Gmail is not an active Google account. Check the spelling or try another Gmail.',
        ];
    }

    return [
        'ok'    => false,
        'error' => 'Could not verify right now. Check your internet connection and try again in a moment.',
    ];
}

function isGmailVerifiedInSession(string $email): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $email = normalizeGmail($email);
    $verified = $_SESSION['alcros_email_verified'] ?? null;
    if (!$verified || ($verified['email'] ?? '') !== $email) {
        return false;
    }
    if (time() > (int) ($verified['expires'] ?? 0)) {
        unset($_SESSION['alcros_email_verified']);
        return false;
    }
    return true;
}

function ensureDocumentRequestSchema(?PDO $pdo = null): void
{
    // Schema is created by install.php only — no runtime ALTER TABLE (safer for XAMPP MySQL).
}

function saveIdUpload(array $file, string $prefix): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return null;
    }

    $ext = match ($mime) {
        'image/jpeg'        => 'jpg',
        'image/png'         => 'png',
        'image/webp'        => 'webp',
        'application/pdf'   => 'pdf',
        default             => 'bin',
    };

    $dir = __DIR__ . '/../uploads/ids';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $filename = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }

    return 'uploads/ids/' . $filename;
}

function civilRecordTypeLabel(string $type): string
{
    return match ($type) {
        'birth'    => 'Birth',
        'death'    => 'Death',
        'marriage' => 'Marriage',
        default    => ucfirst($type),
    };
}

function formatRecordDate(?string $date): string
{
    if ($date === null || $date === '') {
        return '—';
    }
    $ts = strtotime($date);
    return $ts ? date('M j, Y', $ts) : $date;
}

function recordsFlashSet(string $type, string $message): void
{
    $_SESSION['records_flash'] = [$type, $message];
}

function recordsFlashGet(): ?array
{
    $flash = $_SESSION['records_flash'] ?? null;
    unset($_SESSION['records_flash']);
    return $flash;
}

function settingsFlashSet(string $type, string $message): void
{
    $_SESSION['settings_flash'] = [$type, $message];
}

function settingsFlashGet(): ?array
{
    $flash = $_SESSION['settings_flash'] ?? null;
    unset($_SESSION['settings_flash']);
    return $flash;
}

function manageRequestsFlashSet(string $type, string $message): void
{
    $_SESSION['manage_requests_flash'] = [$type, $message];
}

function manageRequestsFlashGet(): ?array
{
    $flash = $_SESSION['manage_requests_flash'] ?? null;
    unset($_SESSION['manage_requests_flash']);
    return $flash;
}

function appointmentFlashSet(string $type, string $message): void
{
    $_SESSION['appointment_flash'] = [$type, $message];
}

function appointmentFlashGet(): ?array
{
    $flash = $_SESSION['appointment_flash'] ?? null;
    unset($_SESSION['appointment_flash']);
    return $flash;
}

function queueFlashSet(string $type, string $message): void
{
    $_SESSION['queue_flash'] = [$type, $message];
}

function queueFlashGet(): ?array
{
    $flash = $_SESSION['queue_flash'] ?? null;
    unset($_SESSION['queue_flash']);
    return $flash;
}

function documentRequestViewData(array $row): array
{
    $appointment = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);

    return [
        'tracking_code'  => (string) ($row['tracking_code'] ?? ''),
        'citizen_name'   => (string) ($row['citizen_name'] ?? ''),
        'date_of_birth'  => !empty($row['date_of_birth']) ? formatDateDisplay($row['date_of_birth']) : '—',
        'sex'            => !empty($row['sex']) ? ucfirst((string) $row['sex']) : '—',
        'email'          => !empty($row['email']) ? (string) $row['email'] : '—',
        'email_verified' => !empty($row['email_verified']) ? 'Yes' : 'No',
        'phone'          => !empty($row['phone']) ? (string) $row['phone'] : '—',
        'document_type'  => documentTypeLabel((string) ($row['document_type'] ?? '')),
        'purpose'        => !empty($row['purpose']) ? (string) $row['purpose'] : '—',
        'id_front_path'  => !empty($row['id_front_path']) ? (string) $row['id_front_path'] : null,
        'id_back_path'   => !empty($row['id_back_path']) ? (string) $row['id_back_path'] : null,
        'appointment'    => $appointment !== '' ? $appointment : '—',
        'status'         => requestStatusLabel((string) ($row['status'] ?? 'pending')),
        'privacy_agreed' => !empty($row['privacy_agreed']) ? 'Yes' : 'No',
        'submitted_at'   => !empty($row['submitted_at']) ? formatDateDisplay($row['submitted_at']) : '—',
        'updated_at'     => !empty($row['updated_at']) ? formatDateDisplay($row['updated_at']) : '—',
        'notes'          => !empty($row['notes']) ? (string) $row['notes'] : null,
    ];
}

function appointmentViewData(array $row): array
{
    $isRequest = (($row['source'] ?? '') === 'document_request') || !empty($row['tracking_code']);

    return [
        'appointment_code' => (string) ($row['appointment_code'] ?? ''),
        'citizen_name'     => (string) ($row['citizen_name'] ?? ''),
        'email'            => !empty($row['email']) ? (string) $row['email'] : '—',
        'phone'            => !empty($row['phone']) ? (string) $row['phone'] : '—',
        'service_type'     => appointmentServiceLabel((string) ($row['service_type'] ?? '')),
        'schedule'         => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null) ?: '—',
        'status'           => appointmentStatusLabel((string) ($row['status'] ?? 'scheduled')),
        'source'           => $isRequest ? 'Document request visit' : 'Special service appointment',
        'tracking_code'    => !empty($row['tracking_code']) ? (string) $row['tracking_code'] : '',
        'notify_email'     => !empty($row['notify_email']) ? 'Yes' : 'No',
        'id_front_path'    => !empty($row['id_front_path']) ? (string) $row['id_front_path'] : null,
        'id_back_path'     => !empty($row['id_back_path']) ? (string) $row['id_back_path'] : null,
        'created_at'       => !empty($row['created_at']) ? formatDateDisplay($row['created_at']) : '—',
        'notes'            => !empty($row['notes']) ? (string) $row['notes'] : null,
    ];
}

function requestStatusLabel(string $status): string
{
    return match (normalizeRequestStatus($status)) {
        'pending'   => 'Pending',
        'verified'  => 'Verified',
        'ready'     => 'Ready for Pickup',
        'completed' => 'Completed',
        'rejected'  => 'Rejected',
        default     => ucfirst($status),
    };
}

function requestStatusMessage(string $status): string
{
    return match (normalizeRequestStatus($status)) {
        'pending'   => 'We received your request. Staff will review your documents soon.',
        'verified'  => 'Your request has been verified. The civil registry office is now processing your document.',
        'ready'     => 'Your document is ready for pickup! Visit the office with your tracking code and valid ID.',
        'completed' => 'This request is complete. Thank you for using ALCROS.',
        'rejected'  => 'This request could not be approved. Please contact the registry office for help.',
        default     => 'Track your request status below.',
    };
}

function appBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $base   = rtrim(str_replace('\\', '/', dirname($script)), '/');
    if ($base === '' || $base === '.') {
        return $scheme . '://' . $host;
    }
    return $scheme . '://' . $host . $base;
}

function trackRequestUrl(string $trackingCode): string
{
    return appBaseUrl() . '/track.php?code=' . urlencode($trackingCode);
}

function citizenWantsEmailNotify(?array $row): bool
{
    if (!$row) {
        return false;
    }
    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return !empty($row['notify_email']);
}

function smtpRead($fp): string
{
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtpCommand($fp, string $command, string $expectPrefix): bool
{
    fwrite($fp, $command . "\r\n");
    $response = smtpRead($fp);
    return str_starts_with($response, $expectPrefix);
}

function citizenEmailText(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function citizenEmailPlain(array $mail): string
{
    $lines = [];
    $name = trim((string) ($mail['name'] ?? ''));
    $lines[] = $name !== '' ? 'Dear ' . $name . ',' : 'Hello,';
    $lines[] = '';
    $lines[] = trim((string) ($mail['intro'] ?? ''));
    $lines[] = '';
    if (!empty($mail['code'])) {
        $lines[] = ($mail['code_label'] ?? 'Code') . ': ' . $mail['code'];
    }
    foreach ($mail['details'] ?? [] as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $lines[] = $label . ': ' . $value;
    }
    $lines[] = '';
    if (!empty($mail['note'])) {
        $lines[] = trim((string) $mail['note']);
        $lines[] = '';
    }
    if (!empty($mail['button_url'])) {
        $lines[] = ($mail['button_label'] ?? 'Open link') . ':';
        $lines[] = (string) $mail['button_url'];
        $lines[] = '';
    }
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $lines[] = '— ALCROS, ' . $office;

    return implode("\n", $lines);
}

function citizenEmailHtml(array $mail): string
{
    $site    = getSetting('site_name', 'ALCROS');
    $office  = getSetting('office_name', 'Local Civil Registrar Office');
    $phone   = getSetting('office_phone', '');
    $hours   = getSetting('office_hours', '');
    $accent  = (string) ($mail['accent'] ?? '#2563eb');
    $heading = (string) ($mail['heading'] ?? 'Update from ALCROS');
    $name    = trim((string) ($mail['name'] ?? ''));
    $intro   = trim((string) ($mail['intro'] ?? ''));
    $note    = trim((string) ($mail['note'] ?? ''));
    $code    = trim((string) ($mail['code'] ?? ''));
    $preheader = $intro !== '' ? $intro : $heading;

    $detailRows = '';
    foreach ($mail['details'] ?? [] as $label => $value) {
        $value = trim((string) $value);
        if ($value === '') {
            continue;
        }
        $detailRows .= '<tr>'
            . '<td style="padding:8px 0;font-size:11px;font-weight:bold;letter-spacing:.04em;text-transform:uppercase;color:#94a3b8;width:38%;vertical-align:top;">'
            . citizenEmailText((string) $label) . '</td>'
            . '<td style="padding:8px 0;font-size:14px;color:#0f172a;font-weight:600;">'
            . citizenEmailText($value) . '</td>'
            . '</tr>';
    }

    $codeBlock = '';
    if ($code !== '') {
        $codeBlock = '<p style="margin:0 0 4px;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;">'
            . citizenEmailText((string) ($mail['code_label'] ?? 'Code')) . '</p>'
            . '<p style="margin:0 0 16px;font-size:22px;letter-spacing:.12em;font-weight:800;color:' . citizenEmailText($accent) . ';font-family:Consolas,\'Courier New\',monospace;">'
            . citizenEmailText($code) . '</p>';
    }

    $button = '';
    if (!empty($mail['button_url'])) {
        $label = citizenEmailText((string) ($mail['button_label'] ?? 'View details'));
        $url   = citizenEmailText((string) $mail['button_url']);
        $button = '<table cellpadding="0" cellspacing="0" border="0" style="margin:4px 0 8px;">'
            . '<tr><td style="background:' . citizenEmailText($accent) . ';border-radius:8px;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:12px 22px;color:#ffffff;text-decoration:none;font-size:13px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;">'
            . $label . '</a></td></tr></table>';
    }

    $introHtml = $intro !== ''
        ? '<p style="margin:0 0 20px;font-size:14px;line-height:1.65;color:#475569;">' . nl2br(citizenEmailText($intro)) . '</p>'
        : '';
    $noteHtml = $note !== ''
        ? '<p style="margin:18px 0 20px;font-size:14px;line-height:1.65;color:#475569;">' . nl2br(citizenEmailText($note)) . '</p>'
        : '';
    $hello = $name !== '' ? 'Hello, ' . $name : 'Hello';
    $footerBits = array_filter([$hours !== '' ? 'Office hours: ' . $hours : '', $phone !== '' ? 'Contact: ' . $phone : '']);
    $footer = implode(' · ', $footerBits);

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"></head>'
        . '<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . citizenEmailText($preheader) . '</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;padding:24px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e2e8f0;">'
        . '<tr><td style="background:' . citizenEmailText($accent) . ';padding:22px 28px;">'
        . '<p style="margin:0;color:#ffffff;font-size:11px;letter-spacing:.18em;font-weight:bold;">' . citizenEmailText($site) . '</p>'
        . '<p style="margin:6px 0 0;color:#ffffff;font-size:13px;opacity:.9;">' . citizenEmailText($office) . '</p>'
        . '</td></tr>'
        . '<tr><td style="padding:28px;">'
        . '<p style="margin:0 0 6px;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:' . citizenEmailText($accent) . ';">'
        . citizenEmailText($heading) . '</p>'
        . '<h1 style="margin:0 0 14px;font-size:22px;line-height:1.3;color:#0f172a;">' . citizenEmailText($hello) . '</h1>'
        . $introHtml
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<tr><td style="padding:18px 20px;">' . $codeBlock
        . ($detailRows !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $detailRows . '</table>' : '')
        . '</td></tr></table>'
        . $noteHtml
        . $button
        . '</td></tr>'
        . '<tr><td style="padding:16px 28px 24px;border-top:1px solid #e2e8f0;">'
        . '<p style="margin:0;font-size:12px;line-height:1.6;color:#94a3b8;">'
        . citizenEmailText($footer) . ($footer !== '' ? '<br>' : '')
        . 'This is an automated message from ALCROS. Please do not reply to this email.'
        . '</p></td></tr>'
        . '</table></td></tr></table></body></html>';
}

function sendCitizenNotice(string $to, string $subject, array $mail): bool
{
    return sendCitizenEmail($to, $subject, citizenEmailPlain($mail), citizenEmailHtml($mail));
}

function buildEmailMime(string $fromName, string $fromEmail, string $body, ?string $html = null): array
{
    $fromHeader = 'From: "' . addcslashes($fromName, '"') . '" <' . $fromEmail . '>';
    $headers = $fromHeader . "\r\n"
        . 'Reply-To: ' . $fromEmail . "\r\n"
        . "MIME-Version: 1.0\r\n";

    $plain = str_replace(["\r\n", "\r"], "\n", $body);
    if ($html === null || $html === '') {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n";
        return [$headers, $plain];
    }

    $boundary = 'alcros_' . bin2hex(random_bytes(8));
    $headers .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n";
    $htmlNorm = str_replace(["\r\n", "\r"], "\n", $html);
    $mime = "--{$boundary}\n"
        . "Content-Type: text/plain; charset=UTF-8\n"
        . "Content-Transfer-Encoding: 8bit\n\n"
        . $plain . "\n\n"
        . "--{$boundary}\n"
        . "Content-Type: text/html; charset=UTF-8\n"
        . "Content-Transfer-Encoding: 8bit\n\n"
        . $htmlNorm . "\n\n"
        . "--{$boundary}--";

    return [$headers, $mime];
}

function sendSmtpEmail(string $to, string $subject, string $body, ?string $html = null): bool
{
    $host = trim(getSetting('smtp_host', 'smtp.gmail.com')) ?: 'smtp.gmail.com';
    $port = (int) getSetting('smtp_port', '587');
    if ($port <= 0) {
        $port = 587;
    }
    $user = trim(getSetting('smtp_user', getSetting('notification_email', '')));
    $pass = (string) getSetting('smtp_pass', '');
    if ($user === '' || $pass === '') {
        return false;
    }

    $fromName = getSetting('site_name', 'ALCROS') . ' - ' . getSetting('office_name', 'Local Civil Registrar Office');
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    [$headers, $mimeBody] = buildEmailMime($fromName, $user, $body, $html);
    $payload = $headers
        . 'To: <' . $to . ">\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n\r\n"
        . str_replace("\n", "\r\n", $mimeBody) . "\r\n";
    $payload = preg_replace('/^\./m', '..', $payload);

    $errno = 0;
    $errstr = '';
    $remote = ($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        return false;
    }
    stream_set_timeout($fp, 15);

    if (!str_starts_with(smtpRead($fp), '220')) {
        fclose($fp);
        return false;
    }

    $ehloHost = $_SERVER['SERVER_NAME'] ?? 'localhost';
    if (!smtpCommand($fp, 'EHLO ' . $ehloHost, '250')) {
        fclose($fp);
        return false;
    }

    if ($port !== 465) {
        if (!smtpCommand($fp, 'STARTTLS', '220')) {
            fclose($fp);
            return false;
        }
        if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($fp);
            return false;
        }
        if (!smtpCommand($fp, 'EHLO ' . $ehloHost, '250')) {
            fclose($fp);
            return false;
        }
    }

    if (!smtpCommand($fp, 'AUTH LOGIN', '334')
        || !smtpCommand($fp, base64_encode($user), '334')
        || !smtpCommand($fp, base64_encode($pass), '235')
        || !smtpCommand($fp, 'MAIL FROM:<' . $user . '>', '250')
        || !smtpCommand($fp, 'RCPT TO:<' . $to . '>', '250')
        || !smtpCommand($fp, 'DATA', '354')
    ) {
        fclose($fp);
        return false;
    }

    fwrite($fp, $payload . "\r\n.\r\n");
    $dataOk = str_starts_with(smtpRead($fp), '250');
    smtpCommand($fp, 'QUIT', '221');
    fclose($fp);

    return $dataOk;
}

function sendCitizenEmail(string $to, string $subject, string $body, ?string $html = null): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (sendSmtpEmail($to, $subject, $body, $html)) {
        return true;
    }

    $from    = getSetting('smtp_user', getSetting('notification_email', getSetting('office_email', 'aloran@gov.ph')));
    $office  = getSetting('office_name', 'Local Civil Registrar Office');
    [$headers, $mimeBody] = buildEmailMime('ALCROS - ' . $office, $from, $body, $html);
    return @mail($to, $subject, str_replace("\n", "\r\n", $mimeBody), $headers);
}

function notifyRequestSubmitted(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    $details = [
        'Document'       => (string) ($data['document_label'] ?? ''),
        'Current status' => 'Pending review',
    ];
    if (!empty($data['appointment_date']) && !empty($data['appointment_time'])) {
        $details['Preferred visit'] = formatDateDisplay($data['appointment_date'])
            . ' at ' . date('g:i A', strtotime($data['appointment_time']));
    }

    return sendCitizenNotice(
        $data['email'],
        'ALCROS — Request received (' . $data['tracking_code'] . ')',
        [
            'heading'      => 'Request received',
            'name'         => $data['citizen_name'],
            'intro'        => 'Thank you for submitting your document request through ALCROS.',
            'code_label'   => 'Tracking code',
            'code'         => $data['tracking_code'],
            'details'      => $details,
            'note'         => "We will email this Gmail address when staff verifies your request, when the status changes, and 5 hours before your preferred visit.\n\nKeep this code safe — you will need it to check your status.",
            'button_label' => 'Track your request',
            'button_url'   => trackRequestUrl($data['tracking_code']),
            'accent'       => '#2563eb',
        ]
    );
}

function notifyRequestStatusChange(PDO $pdo, int $requestId, string $newStatus): void
{
    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT tracking_code, citizen_name, email, document_type, status, appointment_date, appointment_time, notify_email
         FROM document_requests WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$requestId]);
    $row = $stmt->fetch();
    if (!citizenWantsEmailNotify($row)) {
        return;
    }

    $status = normalizeRequestStatus($newStatus);
    $intro = match ($status) {
        'verified'  => 'The civil registry staff has verified your request. It is now being processed.',
        'ready'     => 'Good news — your document is ready for pickup.',
        'completed' => 'Your request has been completed.',
        'rejected'  => 'There is an update on your document request.',
        default     => 'There is an update on your document request.',
    };
    $subject = match ($status) {
        'verified'  => 'ALCROS — Your request is being processed (' . $row['tracking_code'] . ')',
        'ready'     => 'ALCROS — Ready for pickup (' . $row['tracking_code'] . ')',
        default     => 'ALCROS — Request update (' . $row['tracking_code'] . ')',
    };
    $accent = match ($status) {
        'ready'     => '#16a34a',
        'completed' => '#475569',
        'rejected'  => '#dc2626',
        default     => '#2563eb',
    };
    $heading = match ($status) {
        'verified'  => 'Now being processed',
        'ready'     => 'Ready for pickup',
        'completed' => 'Request completed',
        'rejected'  => 'Request update',
        default     => 'Request update',
    };

    sendCitizenNotice($row['email'], $subject, [
        'heading'      => $heading,
        'name'         => $row['citizen_name'],
        'intro'        => $intro,
        'code_label'   => 'Tracking code',
        'code'         => $row['tracking_code'],
        'details'      => [
            'Document'   => documentTypeLabel($row['document_type']),
            'New status' => requestStatusLabel($newStatus),
        ],
        'note'         => requestStatusMessage($newStatus),
        'button_label' => 'View full details',
        'button_url'   => trackRequestUrl($row['tracking_code']),
        'accent'       => $accent,
    ]);
}

function notifyAppointmentBooked(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    return sendCitizenNotice(
        $data['email'],
        'ALCROS — Appointment booked (' . $data['appointment_code'] . ')',
        [
            'heading'      => 'Appointment booked',
            'name'         => $data['citizen_name'],
            'intro'        => 'Your appointment was booked successfully.',
            'code_label'   => 'Appointment code',
            'code'         => $data['appointment_code'],
            'details'      => [
                'Service'  => (string) ($data['service_label'] ?? ''),
                'Schedule' => formatAppointmentDisplay($data['appointment_date'] ?? null, $data['appointment_time'] ?? null),
                'Status'   => 'Scheduled',
            ],
            'note'         => 'We will email this Gmail address 5 hours before your appointment, and if staff updates the status.',
            'button_label' => 'Track appointment',
            'button_url'   => trackRequestUrl($data['appointment_code']),
            'accent'       => '#2563eb',
        ]
    );
}

function notifyAppointmentStatusChange(PDO $pdo, int $appointmentId, string $newStatus): void
{
    ensureCitizenNotifyColumns($pdo);
    $stmt = $pdo->prepare(
        'SELECT appointment_code, citizen_name, email, service_type, appointment_date, appointment_time, notify_email
         FROM appointments WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$appointmentId]);
    $row = $stmt->fetch();
    if (!citizenWantsEmailNotify($row)) {
        return;
    }

    $accent = match ($newStatus) {
        'completed' => '#16a34a',
        'cancelled', 'no_show' => '#dc2626',
        'confirmed' => '#2563eb',
        default => '#2563eb',
    };

    sendCitizenNotice(
        $row['email'],
        'ALCROS — Appointment update (' . $row['appointment_code'] . ')',
        [
            'heading'      => 'Appointment update',
            'name'         => $row['citizen_name'],
            'intro'        => 'There is an update on your appointment.',
            'code_label'   => 'Appointment code',
            'code'         => $row['appointment_code'],
            'details'      => [
                'Service'    => appointmentServiceLabel((string) $row['service_type']),
                'New status' => appointmentStatusLabel($newStatus),
            ],
            'note'         => appointmentStatusMessage($newStatus),
            'button_label' => 'View full details',
            'button_url'   => trackRequestUrl($row['appointment_code']),
            'accent'       => $accent,
        ]
    );
}

function notifyAppointmentReminder(array $row): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    return sendCitizenNotice(
        (string) $row['email'],
        'ALCROS — Appointment in 5 hours (' . $row['appointment_code'] . ')',
        [
            'heading'      => 'Appointment reminder',
            'name'         => $row['citizen_name'],
            'intro'        => 'This is a reminder that your appointment is in about 5 hours.',
            'code_label'   => 'Appointment code',
            'code'         => $row['appointment_code'],
            'details'      => [
                'Service'  => appointmentServiceLabel((string) ($row['service_type'] ?? '')),
                'Schedule' => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null),
            ],
            'note'         => 'Please arrive on time and bring a valid ID.',
            'button_label' => 'View appointment',
            'button_url'   => trackRequestUrl((string) $row['appointment_code']),
            'accent'       => '#d97706',
        ]
    );
}

function notifyRequestVisitReminder(array $row): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    return sendCitizenNotice(
        (string) $row['email'],
        'ALCROS — Visit in 5 hours (' . $row['tracking_code'] . ')',
        [
            'heading'      => 'Visit reminder',
            'name'         => $row['citizen_name'],
            'intro'        => 'This is a reminder that your preferred visit for document pickup is in about 5 hours.',
            'code_label'   => 'Tracking code',
            'code'         => $row['tracking_code'],
            'details'      => [
                'Document'        => documentTypeLabel((string) ($row['document_type'] ?? '')),
                'Current status'  => requestStatusLabel((string) ($row['status'] ?? 'pending')),
                'Preferred visit' => formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null),
            ],
            'note'         => 'Please arrive on time and bring your tracking code and a valid ID.',
            'button_label' => 'Track your request',
            'button_url'   => trackRequestUrl((string) $row['tracking_code']),
            'accent'       => '#d97706',
        ]
    );
}

function sendDueAppointmentReminders(PDO $pdo): int
{
    ensureCitizenNotifyColumns($pdo);

    $lockDir = __DIR__ . '/../storage';
    if (!is_dir($lockDir)) {
        @mkdir($lockDir, 0755, true);
    }
    $lockFile = $lockDir . '/appointment_reminders.lock';
    $lock = @fopen($lockFile, 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return 0;
    }

    $sent = 0;
    try {
        $reqStmt = $pdo->query(
            "SELECT id, tracking_code, citizen_name, email, document_type, status, appointment_date, appointment_time, notify_email
             FROM document_requests
             WHERE notify_email = 1
               AND email IS NOT NULL AND email != ''
               AND reminder_sent_at IS NULL
               AND status IN ('pending', 'verified', 'ready')
               AND appointment_date IS NOT NULL
               AND appointment_time IS NOT NULL
               AND TIMESTAMP(appointment_date, appointment_time) > NOW()
               AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 5 HOUR)"
        );
        $requests = $reqStmt ? $reqStmt->fetchAll() : [];

        $claimReq = $pdo->prepare('UPDATE document_requests SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL');
        $undoReq  = $pdo->prepare('UPDATE document_requests SET reminder_sent_at = NULL WHERE id = ?');
        $markLinkedAppt = $pdo->prepare(
            'UPDATE appointments SET reminder_sent_at = NOW()
             WHERE email = ? AND appointment_date = ? AND appointment_time = ? AND reminder_sent_at IS NULL'
        );

        foreach ($requests as $row) {
            $claimReq->execute([(int) $row['id']]);
            if ($claimReq->rowCount() === 0) {
                continue;
            }
            if (notifyRequestVisitReminder($row)) {
                $sent++;
                $markLinkedAppt->execute([$row['email'], $row['appointment_date'], $row['appointment_time']]);
            } else {
                $undoReq->execute([(int) $row['id']]);
            }
        }

        $apptStmt = $pdo->query(
            "SELECT id, appointment_code, citizen_name, email, service_type, appointment_date, appointment_time, notify_email
             FROM appointments
             WHERE notify_email = 1
               AND email IS NOT NULL AND email != ''
               AND reminder_sent_at IS NULL
               AND status IN ('scheduled', 'confirmed')
               AND TIMESTAMP(appointment_date, appointment_time) > NOW()
               AND TIMESTAMP(appointment_date, appointment_time) <= DATE_ADD(NOW(), INTERVAL 5 HOUR)"
        );
        $appointments = $apptStmt ? $apptStmt->fetchAll() : [];

        $claimAppt = $pdo->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE id = ? AND reminder_sent_at IS NULL');
        $undoAppt  = $pdo->prepare('UPDATE appointments SET reminder_sent_at = NULL WHERE id = ?');

        foreach ($appointments as $row) {
            $claimAppt->execute([(int) $row['id']]);
            if ($claimAppt->rowCount() === 0) {
                continue;
            }
            if (notifyAppointmentReminder($row)) {
                $sent++;
            } else {
                $undoAppt->execute([(int) $row['id']]);
            }
        }
    } catch (Throwable $e) {
        $sent = 0;
    }

    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return $sent;
}

function formatAppointmentDisplay(?string $date, ?string $time): string
{
    if (!$date) {
        return '';
    }
    $out = formatDateDisplay($date);
    if ($time) {
        $ts = strtotime($time);
        $out .= $ts ? ' · ' . date('g:i A', $ts) : '';
    }
    return $out;
}

function isMaintenanceMode(): bool
{
    return getSetting('maintenance_mode', '0') === '1';
}

function arePublicRequestsAllowed(): bool
{
    return getSetting('allow_public_requests', '1') === '1';
}

function getSystemStats(PDO $pdo): array
{
    $tables = [
        'document_requests' => 'Requests',
        'appointments'      => 'Appointments',
        'civil_records'     => 'Civil Records',
        'queue_tickets'     => 'Queue Tickets',
        'staff'             => 'Staff Accounts',
        'activity_logs'     => 'Activity Logs',
    ];
    $stats = [];
    foreach ($tables as $table => $label) {
        try {
            $stats[$table] = [
                'label' => $label,
                'count' => (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(),
            ];
        } catch (PDOException $e) {
            $stats[$table] = ['label' => $label, 'count' => 0];
        }
    }
    return $stats;
}
