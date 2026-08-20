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

    foreach (['id_front_path', 'id_back_path'] as $field) {
        if (empty($row[$field])) {
            continue;
        }
        $path = __DIR__ . '/../' . ltrim((string) $row[$field], '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

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

function appointmentStatusLabel(string $status): string
{
    return match ($status) {
        'scheduled' => 'Scheduled',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
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
        'cancelled' => 'This appointment was cancelled. Contact the office to reschedule.',
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

function sendSmtpEmail(string $to, string $subject, string $body): bool
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
    $payload = 'From: "' . addcslashes($fromName, '"') . '" <' . $user . ">\r\n"
        . 'To: <' . $to . ">\r\n"
        . 'Reply-To: ' . $user . "\r\n"
        . 'Subject: ' . $encodedSubject . "\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit\r\n"
        . "\r\n"
        . $body . "\r\n";

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

function sendCitizenEmail(string $to, string $subject, string $body): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (sendSmtpEmail($to, $subject, $body)) {
        return true;
    }

    $from    = getSetting('smtp_user', getSetting('notification_email', getSetting('office_email', 'aloran@gov.ph')));
    $office  = getSetting('office_name', 'Local Civil Registrar Office');
    $headers = "From: ALCROS - $office <$from>\r\n"
        . "Reply-To: $from\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

function notifyRequestSubmitted(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    $trackUrl = trackRequestUrl($data['tracking_code']);
    $appt     = '';
    if (!empty($data['appointment_date']) && !empty($data['appointment_time'])) {
        $appt = "\nPreferred visit: " . formatDateDisplay($data['appointment_date'])
            . ' at ' . date('g:i A', strtotime($data['appointment_time'])) . "\n";
    }
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $body = "Dear {$data['citizen_name']},\n\n"
        . "Thank you for submitting your document request through ALCROS.\n\n"
        . "Tracking code: {$data['tracking_code']}\n"
        . "Document: {$data['document_label']}\n"
        . "Current status: Pending review\n"
        . $appt
        . "\nWe will email this Gmail address when staff verifies your request, when the status changes, and 5 hours before your preferred visit.\n\n"
        . "Track your request anytime:\n{$trackUrl}\n\n"
        . "Keep this code safe — you will need it to check your status.\n\n"
        . '— ALCROS, ' . $office;

    return sendCitizenEmail(
        $data['email'],
        'ALCROS — Request received (' . $data['tracking_code'] . ')',
        $body
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

    $trackUrl = trackRequestUrl($row['tracking_code']);
    $label    = requestStatusLabel($newStatus);
    $message  = requestStatusMessage($newStatus);
    $doc      = documentTypeLabel($row['document_type']);
    $office   = getSetting('office_name', 'Local Civil Registrar Office');

    $intro = match (normalizeRequestStatus($newStatus)) {
        'verified'  => 'The civil registry staff has verified your request. It is now being processed.',
        'ready'     => 'Good news — your document is ready for pickup.',
        'completed' => 'Your request has been completed.',
        'rejected'  => 'There is an update on your document request.',
        default     => 'There is an update on your document request.',
    };

    $subject = match (normalizeRequestStatus($newStatus)) {
        'verified'  => 'ALCROS — Your request is being processed (' . $row['tracking_code'] . ')',
        'ready'     => 'ALCROS — Ready for pickup (' . $row['tracking_code'] . ')',
        default     => 'ALCROS — Request update (' . $row['tracking_code'] . ')',
    };

    $body = "Dear {$row['citizen_name']},\n\n"
        . $intro . "\n\n"
        . "Tracking code: {$row['tracking_code']}\n"
        . "Document: {$doc}\n"
        . "New status: {$label}\n\n"
        . "{$message}\n\n"
        . "View full details:\n{$trackUrl}\n\n"
        . '— ALCROS, ' . $office;

    sendCitizenEmail($row['email'], $subject, $body);
}

function notifyAppointmentBooked(array $data): bool
{
    if (empty($data['notify_email']) || empty($data['email'])) {
        return false;
    }

    $trackUrl = trackRequestUrl($data['appointment_code']);
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $when = formatAppointmentDisplay($data['appointment_date'] ?? null, $data['appointment_time'] ?? null);
    $body = "Dear {$data['citizen_name']},\n\n"
        . "Your appointment was booked successfully.\n\n"
        . "Appointment code: {$data['appointment_code']}\n"
        . "Service: {$data['service_label']}\n"
        . "Schedule: {$when}\n"
        . "Status: Scheduled\n\n"
        . "We will email this Gmail address 5 hours before your appointment, and if staff updates the status.\n\n"
        . "Track anytime:\n{$trackUrl}\n\n"
        . '— ALCROS, ' . $office;

    return sendCitizenEmail(
        $data['email'],
        'ALCROS — Appointment booked (' . $data['appointment_code'] . ')',
        $body
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

    $trackUrl = trackRequestUrl($row['appointment_code']);
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $body = "Dear {$row['citizen_name']},\n\n"
        . "There is an update on your appointment.\n\n"
        . "Appointment code: {$row['appointment_code']}\n"
        . "Service: " . appointmentServiceLabel((string) $row['service_type']) . "\n"
        . "New status: " . appointmentStatusLabel($newStatus) . "\n\n"
        . appointmentStatusMessage($newStatus) . "\n\n"
        . "View full details:\n{$trackUrl}\n\n"
        . '— ALCROS, ' . $office;

    sendCitizenEmail(
        $row['email'],
        'ALCROS — Appointment update (' . $row['appointment_code'] . ')',
        $body
    );
}

function notifyAppointmentReminder(array $row): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    $trackUrl = trackRequestUrl((string) $row['appointment_code']);
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $when = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $body = "Dear {$row['citizen_name']},\n\n"
        . "This is a reminder that your appointment is in about 5 hours.\n\n"
        . "Appointment code: {$row['appointment_code']}\n"
        . "Service: " . appointmentServiceLabel((string) ($row['service_type'] ?? '')) . "\n"
        . "Schedule: {$when}\n\n"
        . "Please arrive on time and bring a valid ID.\n\n"
        . "View or track your appointment:\n{$trackUrl}\n\n"
        . '— ALCROS, ' . $office;

    return sendCitizenEmail(
        (string) $row['email'],
        'ALCROS — Appointment in 5 hours (' . $row['appointment_code'] . ')',
        $body
    );
}

function notifyRequestVisitReminder(array $row): bool
{
    if (!citizenWantsEmailNotify($row)) {
        return false;
    }

    $trackUrl = trackRequestUrl((string) $row['tracking_code']);
    $office = getSetting('office_name', 'Local Civil Registrar Office');
    $when = formatAppointmentDisplay($row['appointment_date'] ?? null, $row['appointment_time'] ?? null);
    $status = requestStatusLabel((string) ($row['status'] ?? 'pending'));
    $doc = documentTypeLabel((string) ($row['document_type'] ?? ''));
    $body = "Dear {$row['citizen_name']},\n\n"
        . "This is a reminder that your preferred visit for document pickup is in about 5 hours.\n\n"
        . "Tracking code: {$row['tracking_code']}\n"
        . "Document: {$doc}\n"
        . "Current status: {$status}\n"
        . "Preferred visit: {$when}\n\n"
        . "Please arrive on time and bring your tracking code and a valid ID.\n\n"
        . "Track your request:\n{$trackUrl}\n\n"
        . '— ALCROS, ' . $office;

    return sendCitizenEmail(
        (string) $row['email'],
        'ALCROS — Visit in 5 hours (' . $row['tracking_code'] . ')',
        $body
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
