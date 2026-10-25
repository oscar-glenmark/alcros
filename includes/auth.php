<?php
require_once __DIR__ . '/security.php';
bootstrapSecurity();

function staffTokenSecret(): string
{
    return authSecretKey();
}

function authTokenFromRequest(): ?string
{
    $token = $_GET['alcros_auth'] ?? $_POST['alcros_auth'] ?? null;
    return is_string($token) && $token !== '' ? $token : null;
}

function createStaffAuthToken(array $staff): string
{
    $payload = [
        'staff_id' => (string) $staff['staff_id'],
        'name'     => personNameFromRow($staff),
        'role'     => (string) ($staff['role'] ?: 'Staff'),
        'exp'      => time() + 86400 * 7,
    ];
    $b64 = rtrim(strtr(base64_encode(json_encode($payload, JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    $sig = hash_hmac('sha256', $b64, staffTokenSecret());
    return $b64 . '.' . $sig;
}

function validateStaffAuthToken(string $token): ?array
{
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        return null;
    }

    [$b64, $sig] = $parts;
    if (!hash_equals(hash_hmac('sha256', $b64, staffTokenSecret()), $sig)) {
        return null;
    }

    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }

    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['staff_id']) || (int) ($data['exp'] ?? 0) < time()) {
        return null;
    }

    return $data;
}

function staffSessionLogin(array $staff, bool $regenerate = false): void
{
    $_SESSION['staff_id']   = (string) $staff['staff_id'];
    $_SESSION['staff_name'] = personNameFromRow($staff);
    $_SESSION['staff_role'] = (string) ($staff['role'] ?? 'Staff');
    if ($regenerate) {
        session_regenerate_id(true);
    }
}

function staffSessionLogout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

const STAFF_REMEMBER_COOKIE = 'alcros_remember';
const STAFF_REMEMBER_DAYS = 30;

function staffRememberCookieParams(): array
{
    if (!function_exists('alcrosRequestIsHttps')) {
        require_once __DIR__ . '/hosting.php';
    }

    return [
        'expires'  => time() + (STAFF_REMEMBER_DAYS * 86400),
        'path'     => '/',
        'domain'   => '',
        'secure'   => alcrosRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function ensureStaffRememberColumns(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $pdo->query('SELECT remember_token_hash FROM staff LIMIT 1');
    } catch (Throwable) {
        try {
            $pdo->exec(
                'ALTER TABLE staff
                    ADD COLUMN remember_token_hash CHAR(64) NULL DEFAULT NULL AFTER profile_photo_path,
                    ADD COLUMN remember_token_expires_at DATETIME NULL DEFAULT NULL AFTER remember_token_hash'
            );
        } catch (Throwable $ignored) {
        }
        try {
            $pdo->exec('ALTER TABLE staff ADD UNIQUE KEY uq_staff_remember_hash (remember_token_hash)');
        } catch (Throwable $ignored) {
        }
    }
}

function staffRememberMeClearCookie(): void
{
    if (headers_sent()) {
        return;
    }

    setcookie(STAFF_REMEMBER_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'secure'   => staffRememberCookieParams()['secure'],
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE[STAFF_REMEMBER_COOKIE]);
}

function staffRememberMeRawFromCookie(): string
{
    $raw = $_COOKIE[STAFF_REMEMBER_COOKIE] ?? '';

    return is_string($raw) && preg_match('/^[A-Za-z0-9_-]{43}$/', $raw) ? $raw : '';
}

function staffRememberMeIssue(PDO $pdo, array $staff): void
{
    ensureStaffRememberColumns($pdo);
    staffRememberMePurgeExpired($pdo);

    $staffId = (string) ($staff['staff_id'] ?? '');
    if ($staffId === '') {
        return;
    }

    $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $hash = hash('sha256', $raw);
    $expiresAt = date('Y-m-d H:i:s', time() + (STAFF_REMEMBER_DAYS * 86400));

    $pdo->prepare(
        'UPDATE staff SET remember_token_hash = ?, remember_token_expires_at = ? WHERE staff_id = ?'
    )->execute([$hash, $expiresAt, $staffId]);

    $params = staffRememberCookieParams();
    setcookie(STAFF_REMEMBER_COOKIE, $raw, $params);
    $_COOKIE[STAFF_REMEMBER_COOKIE] = $raw;
}

function staffRememberMePurgeExpired(PDO $pdo): void
{
    try {
        ensureStaffRememberColumns($pdo);
        $pdo->exec(
            'UPDATE staff SET remember_token_hash = NULL, remember_token_expires_at = NULL
             WHERE remember_token_expires_at IS NOT NULL AND remember_token_expires_at < NOW()'
        );
    } catch (Throwable $e) {
        // Non-fatal.
    }
}

function staffRememberMeRevokeRaw(PDO $pdo, string $raw): void
{
    if ($raw === '') {
        return;
    }

    try {
        ensureStaffRememberColumns($pdo);
        $hash = hash('sha256', $raw);
        $pdo->prepare(
            'UPDATE staff SET remember_token_hash = NULL, remember_token_expires_at = NULL
             WHERE remember_token_hash = ?'
        )->execute([$hash]);
    } catch (Throwable) {
        // Non-fatal when remember-me columns are not migrated yet.
    }
    staffRememberMeClearCookie();
}

function staffRememberMeRevokeCurrent(PDO $pdo): void
{
    staffRememberMeRevokeRaw($pdo, staffRememberMeRawFromCookie());
}

function staffRememberMeRevokeAllForStaff(PDO $pdo, string $staffId): void
{
    $staffId = trim($staffId);
    if ($staffId === '') {
        return;
    }

    ensureStaffRememberColumns($pdo);

    $raw = staffRememberMeRawFromCookie();
    if ($raw !== '') {
        $hash = hash('sha256', $raw);
        $stmt = $pdo->prepare(
            'SELECT staff_id FROM staff WHERE remember_token_hash = ? AND staff_id = ? LIMIT 1'
        );
        $stmt->execute([$hash, $staffId]);
        if ($stmt->fetchColumn()) {
            staffRememberMeClearCookie();
        }
    }

    $pdo->prepare(
        'UPDATE staff SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE staff_id = ?'
    )->execute([$staffId]);
}

function staffRememberMeTryRestore(): bool
{
    if (getStaffFromSession() !== null) {
        return false;
    }

    $raw = staffRememberMeRawFromCookie();
    if ($raw === '') {
        return false;
    }

    try {
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $pdo = getDB();
        ensureStaffRememberColumns($pdo);
        staffRememberMePurgeExpired($pdo);

        $hash = hash('sha256', $raw);
        $stmt = $pdo->prepare(
            'SELECT staff_id, first_name, middle_name, last_name, role
             FROM staff
             WHERE remember_token_hash = ? AND remember_token_expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            staffRememberMeRevokeRaw($pdo, $raw);

            return false;
        }

        staffSessionLogin([
            'staff_id'    => (string) $row['staff_id'],
            'first_name'  => (string) ($row['first_name'] ?? ''),
            'middle_name' => $row['middle_name'] ?? null,
            'last_name'   => (string) ($row['last_name'] ?? ''),
            'role'        => (string) ($row['role'] ?? 'Staff'),
        ], true);
        $_SESSION['staff_hydrated_at'] = time();
        $_SESSION['staff_remember_me'] = 1;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function getStaffFromSession(): ?array
{
    if (empty($_SESSION['staff_id'])) {
        return null;
    }

    return [
        'staff_id' => (string) $_SESSION['staff_id'],
        'name'     => (string) ($_SESSION['staff_name'] ?? 'User'),
        'role'     => (string) ($_SESSION['staff_role'] ?? 'Staff'),
    ];
}

function hydrateStaffFromDatabase(array $staff): ?array
{
    try {
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $pdo = getDB();
        $stmt = $pdo->prepare('SELECT first_name, middle_name, last_name, role FROM staff WHERE staff_id = ? LIMIT 1');
        $stmt->execute([$staff['staff_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $staff['name'] = personNameFromRow($row);
        $staff['role'] = (string) ($row['role'] ?: 'Staff');
    } catch (Throwable $e) {
        return null;
    }

    return $staff;
}

function staffAuthTokenForSession(): ?string
{
    $sessionStaff = getStaffFromSession();
    if (!$sessionStaff) {
        return null;
    }

    $hydrated = hydrateStaffFromDatabase($sessionStaff);
    if (!$hydrated) {
        return null;
    }

    return createStaffAuthToken($hydrated);
}

function getAuthenticatedStaff(bool $lightweight = false): ?array
{
    static $resolved = false;
    static $staff = null;
    static $lightweightResolved = false;
    static $lightweightStaff = null;

    $sessionStaff = getStaffFromSession();
    $token = authTokenFromRequest();
    $fromToken = ($token !== null && $token !== '') ? validateStaffAuthToken($token) : null;
    $tokenTabStaff = null;

    if ($fromToken !== null && $sessionStaff !== null
        && (string) $fromToken['staff_id'] !== (string) $sessionStaff['staff_id']) {
        // Two accounts in one browser: this request's tab token (alcros_auth) identifies the user
        // for this page/API call without overwriting the shared PHP session cookie.
        $tokenTabStaff = hydrateStaffFromDatabase([
            'staff_id' => (string) $fromToken['staff_id'],
            'name'     => (string) ($fromToken['name'] ?? 'User'),
            'role'     => (string) ($fromToken['role'] ?? 'Staff'),
        ]);
        if ($tokenTabStaff === null) {
            $fromToken = null;
        }
    } elseif ($fromToken !== null && $sessionStaff !== null) {
        $fromToken = null;
    }

    if ($lightweight) {
        if ($lightweightResolved) {
            return $lightweightStaff;
        }
        $lightweightResolved = true;

        if ($tokenTabStaff !== null) {
            $lightweightStaff = $tokenTabStaff;

            return $lightweightStaff;
        }

        if ($sessionStaff) {
            $lightweightStaff = $sessionStaff;

            return $lightweightStaff;
        }

        if ($fromToken) {
            $lightweightStaff = [
                'staff_id' => (string) $fromToken['staff_id'],
                'name'     => (string) ($fromToken['name'] ?? 'User'),
                'role'     => (string) ($fromToken['role'] ?? 'Staff'),
            ];

            return $lightweightStaff;
        }

        $lightweightStaff = null;

        return null;
    }

    if ($resolved) {
        return $staff;
    }
    $resolved = true;

    if ($tokenTabStaff !== null) {
        $staff = $tokenTabStaff;

        return $staff;
    }

    if ($fromToken) {
        $staff = hydrateStaffFromDatabase($fromToken);
        if ($staff) {
            staffSessionLogin($staff);
            $_SESSION['staff_hydrated_at'] = time();

            return $staff;
        }
    }

    $sessionStaff = getStaffFromSession();
    if (!$sessionStaff) {
        staffRememberMeTryRestore();
        $sessionStaff = getStaffFromSession();
    }
    if (!$sessionStaff) {
        $staff = null;

        return null;
    }

    $staff = $sessionStaff;
    $hydratedAt = (int) ($_SESSION['staff_hydrated_at'] ?? 0);
    if ($hydratedAt > 0 && (time() - $hydratedAt) < 300) {
        return $staff;
    }

    $staff = hydrateStaffFromDatabase($staff);
    if (!$staff) {
        staffSessionLogout();

        return null;
    }

    $prevName = (string) ($_SESSION['staff_name'] ?? '');
    $prevRole = (string) ($_SESSION['staff_role'] ?? '');
    if ($prevName !== $staff['name'] || $prevRole !== $staff['role']) {
        staffSessionLogin($staff);
    }
    $_SESSION['staff_hydrated_at'] = time();

    return $staff;
}

function staffAuthToken(): ?string
{
    $staff = getAuthenticatedStaff(true);
    if ($staff === null) {
        $fromRequest = authTokenFromRequest();
        if ($fromRequest !== null && validateStaffAuthToken($fromRequest) !== null) {
            return $fromRequest;
        }

        return null;
    }

    $hydrated = hydrateStaffFromDatabase($staff);
    if ($hydrated === null) {
        return null;
    }

    return createStaffAuthToken($hydrated);
}

function authFormField(): string
{
    $token = staffAuthToken();
    $html = '';
    if ($token) {
        $html .= '<input type="hidden" name="alcros_auth" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }

    return $html . csrfField();
}

function buildAuthUrl(string $path, array $query = []): string
{
    if (!function_exists('alcrosPortalUrl')) {
        require_once __DIR__ . '/helpers.php';
    }

    $token = staffAuthToken();
    if ($token) {
        $query['alcros_auth'] = $token;
    }

    return alcrosPortalUrl($path, $query);
}

function redirectWithAuth(string $path, array $query = []): void
{
    header('Location: ' . buildAuthUrl($path, $query));
    exit;
}

function outputAuthBootstrap(): void
{
    require_once __DIR__ . '/scripts.php';
    if (!function_exists('faviconLinkTag')) {
        require_once __DIR__ . '/helpers.php';
    }
    $redirect = basename($_SERVER['PHP_SELF']);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">' . faviconLinkTag() . '<title>Loading...</title>';
    echo pageConfigJson(['redirect' => $redirect], 'auth-bootstrap-config');
    echo scriptTag('core/auth-bootstrap.js');
    echo '</head><body></body></html>';
    exit;
}

function releaseSessionLock(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/** Authenticated file/stream delivery — no DB hydrate per image, plain 401 on failure. */
function requireStaffLoginForMedia(): void
{
    if (getAuthenticatedStaff(true)) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            releaseSessionLock();
        }

        return;
    }

    http_response_code(401);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    echo 'Unauthorized';
    exit;
}

function sendStaffPortalCacheHeaders(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
}

function requireStaffLogin(): void
{
    if (getAuthenticatedStaff()) {
        sendStaffPortalCacheHeaders();
        requireStaffPostCsrf();
        if (isJsonApiRequest() || (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET')) {
            releaseSessionLock();
        }
        return;
    }

    if (!authTokenFromRequest()) {
        if (isJsonApiRequest()) {
            jsonClientError(401, 'auth_expired', 'Session expired. Please sign in again.');
        }
        outputAuthBootstrap();
    }

    if (isJsonApiRequest()) {
        jsonClientError(401, 'auth_expired', 'Session expired. Please sign in again.');
    }

    if (!function_exists('alcrosPortalUrl')) {
        require_once __DIR__ . '/helpers.php';
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'));
    $redirectTarget = ltrim($script, '/');
    if (str_starts_with($redirectTarget, 'api/')) {
        $redirectTarget = 'dashboard.php';
    }
    header('Location: ' . alcrosPortalUrl('login.php', ['redirect' => $redirectTarget]));
    exit;
}

function staffName(): string
{
    return getAuthenticatedStaff()['name'] ?? 'User';
}

function staffRole(): string
{
    return (string) (getAuthenticatedStaff()['role'] ?? 'Staff');
}

function staffId(): string
{
    return (string) (getAuthenticatedStaff()['staff_id'] ?? '');
}

function staffPhotoPath(?string $staffId = null): ?string
{
    $staffId = $staffId ?? staffId();
    if ($staffId === '') {
        return null;
    }

    static $cache = [];
    if (array_key_exists($staffId, $cache)) {
        return $cache[$staffId];
    }

    try {
        if (!function_exists('getDB')) {
            require_once __DIR__ . '/../config/database.php';
        }
        if (!function_exists('ensureStaffProfileColumns')) {
            require_once __DIR__ . '/helpers.php';
        }
        $pdo = getDB();
        ensureStaffProfileColumns($pdo);
        $stmt = $pdo->prepare('SELECT profile_photo_path FROM staff WHERE staff_id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $path = $stmt->fetchColumn();
        $cache[$staffId] = ($path && trim((string) $path) !== '') ? (string) $path : null;
    } catch (Throwable $e) {
        $cache[$staffId] = null;
    }

    return $cache[$staffId];
}

function isAdmin(): bool
{
    return staffRole() === 'Administrator';
}

/** Staff add/edit/import civil registry entries; Administrators are view-only on Records. */
function staffCanManageCivilRecords(): bool
{
    return !isAdmin();
}

function assertStaffCanManageCivilRecords(): void
{
    if (staffCanManageCivilRecords()) {
        return;
    }
    if (isJsonApiRequest()) {
        jsonClientError(403, 'forbidden', 'Administrators can view records only. Staff accounts add and edit civil registry entries.');
    }
    if (function_exists('recordsFlashSet')) {
        recordsFlashSet('error', 'Administrators can view records only.');
    }
    redirectWithAuth('records.php');
}

/** Sidebar / header label for notifications.php (Administrator = system alerts only). */
function staffPortalInboxLabel(): string
{
    return isAdmin() ? 'Alerts' : 'Notifications';
}

/** Day-to-day counter pages — Staff only (Administrators use Reports for oversight). */
function staffOperationalPages(): array
{
    return [
        'manage_request.php',
        'appointment.php',
        'live-queue.php',
    ];
}

function isStaffOperationalPage(string $page): bool
{
    return in_array($page, staffOperationalPages(), true);
}

/** @return array{0: string, 1: array<string, string>} */
function staffOperationalReportTarget(string $page): array
{
    return match ($page) {
        'manage_request.php' => ['report.php', ['section' => 'requests']],
        'appointment.php'    => ['report.php', ['section' => 'appointments']],
        'live-queue.php'     => ['report.php', ['section' => 'queue']],
        default              => ['report.php', []],
    };
}

/**
 * @param array<string, mixed> $query
 * @return array{0: string, 1: array<string, mixed>}
 */
function resolveStaffOperationalNav(string $page, array $query = []): array
{
    if (isAdmin() && isStaffOperationalPage($page)) {
        return staffOperationalReportTarget($page);
    }

    return [$page, $query];
}

/** @param array<string, mixed> $query */
function buildStaffOperationalUrl(string $page, array $query = []): string
{
    [$page, $query] = resolveStaffOperationalNav($page, $query);

    return buildAuthUrl($page, $query);
}

function denyAdministratorStaffOperations(string $page = ''): never
{
    if (isJsonApiRequest()) {
        jsonClientError(403, 'forbidden', 'This page is for Staff accounts. Administrators can use Reports and Settings.');
    }
    if ($page !== '' && isStaffOperationalPage($page)) {
        [$reportPage, $query] = staffOperationalReportTarget($page);
        redirectWithAuth($reportPage, $query);
    }
    redirectWithAuth('dashboard.php');
}

function staffMenuPages(): array
{
    return [
        'dashboard.php',
        'notifications.php',
        'manage_request.php',
        'appointment.php',
        'records.php',
        'live-queue.php',
        'documents.php',
        'print_certificate.php',
        'system_settings.php',
    ];
}

function requireAdmin(): void
{
    requireStaffLogin();
    if (!isAdmin()) {
        if (isJsonApiRequest()) {
            jsonClientError(403, 'forbidden', 'Administrator access required.');
        }
        redirectWithAuth('dashboard.php');
    }
}

function requirePageAccess(string $page): void
{
    requireStaffLogin();
    if (isAdmin()) {
        if (isStaffOperationalPage($page)) {
            denyAdministratorStaffOperations($page);
        }
        if ($page === 'documents.php') {
            redirectWithAuth('dashboard.php');
        }

        return;
    }
    if (!in_array($page, staffMenuPages(), true)) {
        if (isJsonApiRequest()) {
            jsonClientError(403, 'forbidden', 'You do not have access to this feature.');
        }
        redirectWithAuth('dashboard.php');
    }
}
