<?php
/**
 * ALCROS MySQL connection
 */
require_once __DIR__ . '/environment.php';

if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
}
if (!defined('DB_NAME')) {
    define('DB_NAME', 'alcros_db');
}
if (!defined('DB_USER')) {
    define('DB_USER', 'root');
}
if (!defined('DB_PASS')) {
    define('DB_PASS', '');
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

if (is_file(__DIR__ . '/database.local.php')) {
    require __DIR__ . '/database.local.php';
}

function dbUnavailablePage(string $title, string $help, string $msg): never
{
    http_response_code(503);
    $installLink = str_contains($help, 'install.php')
        ? ''
        : '<p class="text-xs text-gray-500 mt-4">First time setup? <a href="install.php" class="text-blue-600 font-bold">Run install.php</a> once while MySQL is running.</p>';
    if (!function_exists('faviconLinkTag')) {
        require_once __DIR__ . '/../includes/helpers.php';
    }
    $iconTags = faviconLinkTag();
    exit('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">' . $iconTags . '<title>' . htmlspecialchars($title) . '</title><script src="assets/vendor/tailwindcss.js"></script><link rel="stylesheet" href="assets/vendor/inter/inter.css"><link rel="stylesheet" href="assets/css/public/back-home.css"></head><body class="bg-gray-50 min-h-screen flex items-center justify-center p-6"><div class="max-w-md w-full bg-white rounded-2xl shadow p-8 border border-gray-100 text-center"><div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl font-black">!</div><h1 class="text-xl font-black text-slate-900 mb-2">' . htmlspecialchars($title) . '</h1><p class="text-sm text-gray-600 mb-4">' . $help . '</p><p class="text-xs text-gray-400 font-mono bg-gray-50 rounded-lg p-3 break-all text-left">' . htmlspecialchars($msg) . '</p>' . $installLink . '<a href="index.php" class="back-home back-home--center inline-flex mt-6">Back to Home</a></div></body></html>');
}

/** Can we reach MySQL at all? */
function mysqlServerUp(): bool
{
    try {
        new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** Is alcros_db created with the staff table? (already installed) */
function databaseIsInstalled(): bool
{
    if (!mysqlServerUp()) {
        return false;
    }
    try {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->query('SELECT 1 FROM staff LIMIT 1');
        return true;
    } catch (PDOException) {
        return false;
    }
}

/** User-friendly message when a page catches a database error (MySQL stopped vs not installed). */
function dbConnectionHelpMessage(): string
{
    if (!mysqlServerUp()) {
        return alcrosIsLocalOfficeInstall()
            ? 'MySQL is not running. Start it in the XAMPP Control Panel, then refresh this page.'
            : 'Cannot reach the MySQL server. Check DB_HOST and credentials in config/database.local.php and your SmarterASP MySQL database status.';
    }
    if (!databaseIsInstalled()) {
        return alcrosIsLocalOfficeInstall()
            ? 'Database not installed yet. Open install.php once while MySQL is running.'
            : 'Database not installed yet. Import database/alcros.sql in phpMyAdmin or run install.php once, then remove/block public access to install.php.';
    }

    return alcrosIsLocalOfficeInstall()
        ? 'Database error. Check that MySQL is running in XAMPP.'
        : 'Database error. Verify config/database.local.php matches your SmarterASP MySQL database.';
}

function createDBConnection(): PDO
{
    if (!mysqlServerUp()) {
        $help = alcrosIsLocalOfficeInstall()
            ? 'Start <strong>MySQL</strong> in the XAMPP Control Panel, then refresh this page. You do <em>not</em> need to run install.php again if you already installed before.'
            : 'Check <strong>config/database.local.php</strong> (MySQL host, database name, user, password) and that your SmarterASP MySQL database is online.';
        dbUnavailablePage(
            alcrosIsLocalOfficeInstall() ? 'MySQL Is Not Running' : 'MySQL Connection Failed',
            $help,
            'Connection refused or access denied.'
        );
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    try {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
            $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 5;
        }

        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Unknown database')) {
            dbUnavailablePage(
                'Database Not Installed Yet',
                alcrosIsLocalOfficeInstall()
                    ? 'Open <a href="install.php" class="text-blue-600 font-bold">install.php</a> <strong>once</strong> while MySQL is running. After that, you only need install.php again if the database was deleted.'
                    : 'Import <code class="bg-gray-100 px-1 rounded">database/alcros.sql</code> in phpMyAdmin or run <a href="install.php" class="text-blue-600 font-bold">install.php</a> once.',
                $msg
            );
        }
        dbUnavailablePage(
            'Database Error',
            alcrosIsLocalOfficeInstall()
                ? 'Check config/database.php and that MySQL is running.'
                : 'Check config/database.local.php and SmarterASP MySQL settings.',
            $msg
        );
    }
}

function getDB(bool $forceReconnect = false): PDO
{
    static $pdo = null;
    if ($forceReconnect) {
        $pdo = null;
    }
    if ($pdo === null) {
        $pdo = createDBConnection();
    }
    return $pdo;
}
