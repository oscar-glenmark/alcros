<?php
/**
 * Hosting environment helpers (XAMPP, SmarterASP.NET Premium, other PHP hosts).
 */

if (is_file(__DIR__ . '/../config/hosting.local.php')) {
    require_once __DIR__ . '/../config/hosting.local.php';
}

if (!defined('ALCROS_TRUST_PROXY_HTTPS')) {
    define('ALCROS_TRUST_PROXY_HTTPS', false);
}

/**
 * True when running on a developer machine (XAMPP / localhost).
 */
function alcrosIsLocalOfficeInstall(): bool
{
    if (defined('ALCROS_LOCAL_OFFICE')) {
        return (bool) ALCROS_LOCAL_OFFICE;
    }

    if (PHP_OS_FAMILY === 'Windows' && is_dir('C:\\xampp')) {
        return true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_starts_with($host, '127.0.0.1')) {
        return true;
    }

    return false;
}

function alcrosIsHostedEnvironment(): bool
{
    return !alcrosIsLocalOfficeInstall();
}

/**
 * Whether install.php may be opened in a browser on a live host.
 * After setup, production should return false (see alcrosInstallWebBlocked()).
 */
function alcrosAllowWebInstall(): bool
{
    if (defined('ALCROS_ALLOW_WEB_INSTALL')) {
        return (bool) ALCROS_ALLOW_WEB_INSTALL;
    }

    return is_file(__DIR__ . '/../storage/allow_web_install.txt');
}

/** Block install.php on production once the database is installed. */
function alcrosInstallWebBlocked(): bool
{
    return alcrosIsHostedEnvironment()
        && function_exists('databaseIsInstalled')
        && databaseIsInstalled()
        && !alcrosAllowWebInstall();
}

/**
 * HTTPS detection including reverse-proxy headers (SmarterASP / load balancers).
 */
function alcrosRequestIsHttps(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }

    if (!ALCROS_TRUST_PROXY_HTTPS) {
        return false;
    }

    $forwarded = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwarded === 'https') {
        return true;
    }

    $frontEnd = strtolower(trim((string) ($_SERVER['HTTP_FRONT_END_HTTPS'] ?? '')));
    if ($frontEnd === 'on') {
        return true;
    }

    return false;
}

function alcrosRequestScheme(): string
{
    return alcrosRequestIsHttps() ? 'https' : 'http';
}

/** @return list<string> */
function alcrosDatabaseHelpLines(): array
{
    if (alcrosIsLocalOfficeInstall()) {
        return [
            'Start MySQL in the XAMPP Control Panel, then refresh.',
            'First-time setup: open install.php once while MySQL is running.',
            'Check config/database.php or config/database.local.php on the server.',
        ];
    }

    return [
        'Confirm MySQL is running in your SmarterASP hosting control panel.',
        'Copy config/database.local.php.example to config/database.local.php with your MySQL host, database name, user, and password.',
        'Import database/alcros.sql via phpMyAdmin, or run install.php once before going live.',
    ];
}

function alcrosDatabaseHelpHtml(): string
{
    $lines = alcrosDatabaseHelpLines();
    $parts = array_map(
        static fn (string $line): string => '<li>' . htmlspecialchars($line) . '</li>',
        $lines
    );

    return '<ul class="list-disc pl-5 text-left text-sm space-y-1">' . implode('', $parts) . '</ul>';
}

function alcrosIisDenyWebConfigXml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <security>
      <authorization>
        <remove users="*" roles="" verbs="" />
        <add accessType="Deny" users="*" />
      </authorization>
    </security>
  </system.webServer>
</configuration>
XML;
}

/**
 * Block direct HTTP access to a directory on both Apache (.htaccess) and IIS (web.config).
 */
function alcrosWriteWebAccessDeny(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $htaccess = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }

    $webConfig = $dir . DIRECTORY_SEPARATOR . 'web.config';
    if (!is_file($webConfig)) {
        file_put_contents($webConfig, alcrosIisDenyWebConfigXml());
    }
}

function alcrosAppointmentRemindersCronUrl(): string
{
    if (!function_exists('appBaseUrl') || !function_exists('cronSecretKey')) {
        return '';
    }

    $base = appBaseUrl();
    if ($base === '') {
        return '';
    }

    return $base . '/api/appointment_reminders.php?cron_secret=' . urlencode(cronSecretKey());
}
