<?php
/**
 * ALCROS security helpers — headers, CSRF, rate limits, session hardening.
 */

function securityStoragePath(string $file): string
{
    $dir = __DIR__ . '/../storage';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir . '/' . $file;
}

function authSecretKey(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }

    $path = securityStoragePath('auth_secret.txt');
    if (is_readable($path)) {
        $secret = trim((string) file_get_contents($path));
        if ($secret !== '') {
            return $secret;
        }
    }

    $secret = bin2hex(random_bytes(32));
    file_put_contents($path, $secret, LOCK_EX);

    return $secret;
}

function cronSecretKey(): string
{
    $path = securityStoragePath('cron_secret.txt');
    if (is_readable($path)) {
        $stored = trim((string) file_get_contents($path));
        if ($stored !== '') {
            return $stored;
        }
    }

    $secret = bin2hex(random_bytes(24));
    file_put_contents($path, $secret, LOCK_EX);

    return $secret;
}

function bootstrapSecurity(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    sendSecurityHeaders();
    ensureCsrfToken();
}

function sendSecurityHeaders(): void
{
    static $sent = false;
    if ($sent || headers_sent()) {
        return;
    }
    $sent = true;

    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        'Content-Security-Policy: default-src \'self\'; '
        . 'script-src \'self\' \'unsafe-inline\' https://cdn.tailwindcss.com https://unpkg.com https://cdn.jsdelivr.net; '
        . 'style-src \'self\' \'unsafe-inline\' https://fonts.googleapis.com https://cdn.tailwindcss.com; '
        . 'font-src \'self\' https://fonts.gstatic.com data:; '
        . 'img-src \'self\' data: https:; '
        . 'connect-src \'self\'; '
        . 'frame-ancestors \'self\'; '
        . 'base-uri \'self\'; '
        . 'form-action \'self\''
    );

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function ensureCsrfToken(): void
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function csrfToken(): string
{
    ensureCsrfToken();

    return (string) $_SESSION['csrf_token'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="'
        . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function validateCsrf(?string $token): bool
{
    ensureCsrfToken();
    if (!is_string($token) || $token === '') {
        return false;
    }

    return hash_equals((string) $_SESSION['csrf_token'], $token);
}

function requireCsrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!validateCsrf(is_string($token) ? $token : '')) {
        http_response_code(419);
        exit('Invalid or expired security token. Please refresh the page and try again.');
    }
}

function requireStaffPostCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'login.php') {
        return;
    }

    requireCsrf();
}

function publicCsrfField(): string
{
    return csrfField();
}

function requirePublicPostCsrf(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    requireCsrf();
}

function rateLimitKey(string $action, ?string $suffix = null): string
{
    $ip = clientIpAddress();
    $part = preg_replace('/[^a-z0-9_-]+/i', '_', $action) ?: 'action';
    $extra = $suffix !== null ? '_' . preg_replace('/[^a-z0-9_-]+/i', '_', $suffix) : '';

    return $part . '_' . md5($ip . $extra);
}

function clientIpAddress(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function rateLimitCheck(string $key, int $maxAttempts, int $windowSeconds): bool
{
    $dir = securityStoragePath('rate_limits');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = $dir . '/' . hash('sha256', $key) . '.json';
    $now = time();
    $data = ['count' => 0, 'reset' => $now + $windowSeconds];

    if (is_readable($file)) {
        $decoded = json_decode((string) file_get_contents($file), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    if (($data['reset'] ?? 0) <= $now) {
        $data = ['count' => 0, 'reset' => $now + $windowSeconds];
    }

    if (($data['count'] ?? 0) >= $maxAttempts) {
        return false;
    }

    $data['count'] = (int) ($data['count'] ?? 0) + 1;
    file_put_contents($file, json_encode($data), LOCK_EX);

    return true;
}

function rateLimitOrAbort(string $key, int $maxAttempts, int $windowSeconds, string $message): void
{
    if (rateLimitCheck($key, $maxAttempts, $windowSeconds)) {
        return;
    }

    http_response_code(429);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $message]);
        exit;
    }

    exit($message);
}

function validatePasswordStrength(string $password): ?string
{
    if (strlen($password) < 10) {
        return 'Password must be at least 10 characters.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number.';
    }

    return null;
}

function sanitizeOverviewHtml(string $html): string
{
    return strip_tags($html, '<strong><em><br><p><ul><ol><li>');
}

function sanitizeExternalUrl(string $url, string $fallback = 'privacy.php'): string
{
    $url = trim($url);
    if ($url === '') {
        return $fallback;
    }

    if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
        return $url;
    }

    if (preg_match('#^https?://#i', $url)) {
        $host = parse_url($url, PHP_URL_HOST);
        $siteHost = $_SERVER['HTTP_HOST'] ?? '';
        if ($host && $siteHost && strcasecmp($host, $siteHost) === 0) {
            return $url;
        }
    }

    return $fallback;
}

function requireCronSecret(): void
{
    $expected = cronSecretKey();
    $provided = (string) ($_GET['cron_secret'] ?? $_POST['cron_secret'] ?? $_SERVER['HTTP_X_CRON_SECRET'] ?? '');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
}

function isSensitiveUploadPath(string $path): bool
{
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');

    return str_starts_with($path, 'uploads/ids/')
        || str_starts_with($path, 'uploads/staff/');
}

function normalizeUploadRelativePath(string $path): ?string
{
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '' || str_contains($path, '..') || !isSensitiveUploadPath($path)) {
        return null;
    }

    $full = realpath(__DIR__ . '/../' . $path);
    $root = realpath(__DIR__ . '/../uploads');
    if ($full === false || $root === false || !str_starts_with($full, $root)) {
        return null;
    }

    return $path;
}

function publicTrackingRequest(array $row): array
{
    return [
        'tracking_code'    => $row['tracking_code'] ?? '',
        'citizen_name'     => $row['citizen_name'] ?? '',
        'document_type'    => $row['document_type'] ?? '',
        'status'           => $row['status'] ?? '',
        'submitted_at'     => $row['submitted_at'] ?? '',
        'updated_at'       => $row['updated_at'] ?? '',
        'appointment_date' => $row['appointment_date'] ?? null,
        'appointment_time' => $row['appointment_time'] ?? null,
    ];
}

function publicTrackingAppointment(array $row): array
{
    return [
        'appointment_code' => $row['appointment_code'] ?? '',
        'citizen_name'     => $row['citizen_name'] ?? '',
        'service_type'     => $row['service_type'] ?? '',
        'status'           => $row['status'] ?? '',
        'appointment_date' => $row['appointment_date'] ?? '',
        'appointment_time' => $row['appointment_time'] ?? '',
        'created_at'       => $row['created_at'] ?? '',
    ];
}

function protectedUploadUrl(?string $path): ?string
{
    if (!$path || !isSensitiveUploadPath($path)) {
        return $path;
    }

    if (!function_exists('buildAuthUrl')) {
        require_once __DIR__ . '/auth.php';
    }

    return buildAuthUrl('file.php', ['f' => ltrim(str_replace('\\', '/', $path), '/')]);
}
