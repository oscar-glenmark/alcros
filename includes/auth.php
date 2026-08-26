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
        'name'     => (string) $staff['name'],
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
    $_SESSION['staff_name'] = (string) ($staff['name'] ?? 'User');
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
        $stmt = $pdo->prepare('SELECT name, role FROM staff WHERE staff_id = ? LIMIT 1');
        $stmt->execute([$staff['staff_id']]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $staff['name'] = (string) $row['name'];
        $staff['role'] = (string) ($row['role'] ?: 'Staff');
    } catch (Throwable $e) {
        // Use existing payload if database lookup fails temporarily.
    }

    return $staff;
}

function getAuthenticatedStaff(): ?array
{
    static $resolved = false;
    static $staff = null;

    if ($resolved) {
        return $staff;
    }
    $resolved = true;

    $token = authTokenFromRequest();
    if ($token) {
        $staff = validateStaffAuthToken($token);
        if ($staff) {
            $staff = hydrateStaffFromDatabase($staff);
            if ($staff) {
                staffSessionLogin($staff);
                return $staff;
            }
        }
    }

    $staff = getStaffFromSession();
    if (!$staff) {
        return null;
    }

    $staff = hydrateStaffFromDatabase($staff);
    if (!$staff) {
        staffSessionLogout();
        return null;
    }

    staffSessionLogin($staff);
    return $staff;
}

function staffAuthToken(): ?string
{
    $fromRequest = authTokenFromRequest();
    if ($fromRequest !== null && validateStaffAuthToken($fromRequest) !== null) {
        return $fromRequest;
    }

    $staff = getStaffFromSession();
    if (!$staff) {
        return null;
    }

    return createStaffAuthToken($staff);
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
    $token = staffAuthToken();
    if ($token) {
        $query['alcros_auth'] = $token;
    }

    $queryString = http_build_query($query);
    return $queryString !== '' ? $path . '?' . $queryString : $path;
}

function redirectWithAuth(string $path, array $query = []): void
{
    header('Location: ' . buildAuthUrl($path, $query));
    exit;
}

function outputAuthBootstrap(): void
{
    $redirect = basename($_SERVER['PHP_SELF']);
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><link rel="icon" type="image/png" href="images/favicon.png?v=2"><title>Loading...</title><script>
    (function () {
        var token = sessionStorage.getItem("alcros_auth");
        if (token) {
            var params = new URLSearchParams(window.location.search);
            params.set("alcros_auth", token);
            window.location.replace(window.location.pathname + "?" + params.toString() + window.location.hash);
            return;
        }
        window.location.replace("login.php?redirect=' . rawurlencode($redirect) . '");
    })();
    </script></head><body></body></html>';
    exit;
}

function requireStaffLogin(): void
{
    if (getAuthenticatedStaff()) {
        requireStaffPostCsrf();
        return;
    }

    if (!authTokenFromRequest()) {
        outputAuthBootstrap();
    }

    header('Location: login.php?redirect=' . urlencode(basename($_SERVER['PHP_SELF'])));
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

function isStaffMember(): bool
{
    return staffRole() === 'Staff';
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
        'system_settings.php',
    ];
}

function requireAdmin(): void
{
    requireStaffLogin();
    if (!isAdmin()) {
        redirectWithAuth('dashboard.php');
    }
}

function requirePageAccess(string $page): void
{
    requireStaffLogin();
    if (isAdmin()) {
        return;
    }
    if (!in_array($page, staffMenuPages(), true)) {
        redirectWithAuth('dashboard.php');
    }
}
