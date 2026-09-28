<?php
/**
 * ALCROS MySQL connection.
 * Local XAMPP: defaults below.
 * Production: config/database.local.php (gitignored) or ALCROS_DB_* env vars.
 */
$localConfig = __DIR__ . '/database.local.php';
if (is_file($localConfig)) {
    require $localConfig;
}

if (!defined('DB_HOST')) {
    define('DB_HOST', (string) (getenv('ALCROS_DB_HOST') ?: 'localhost'));
}
if (!defined('DB_NAME')) {
    define('DB_NAME', (string) (getenv('ALCROS_DB_NAME') ?: 'alcros_db'));
}
if (!defined('DB_USER')) {
    define('DB_USER', (string) (getenv('ALCROS_DB_USER') ?: 'root'));
}
if (!defined('DB_PASS')) {
    define('DB_PASS', (string) (getenv('ALCROS_DB_PASS') ?: ''));
}
if (!defined('DB_CHARSET')) {
    define('DB_CHARSET', 'utf8mb4');
}

function alcrosIsLocalXamppDefaults(): bool
{
    return DB_HOST === 'localhost'
        && DB_NAME === 'alcros_db'
        && DB_USER === 'root'
        && DB_PASS === '';
}

function dbUnavailablePage(string $title, string $help, string $msg): never
{
    http_response_code(503);
    $installLink = str_contains($help, 'install.php')
        ? ''
        : '<p class="text-xs text-gray-500 mt-4">First time setup? <a href="install.php" class="text-blue-600 font-bold">Run install.php</a> once while MySQL is running.</p>';
    exit('<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="icon" type="image/png" href="images/favicon.png?v=2"><title>' . htmlspecialchars($title) . '</title><script src="assets/vendor/tailwindcss.js"></script><link rel="stylesheet" href="assets/vendor/inter/inter.css"><link rel="stylesheet" href="assets/css/public/back-home.css"></head><body class="bg-gray-50 min-h-screen flex items-center justify-center p-6"><div class="max-w-md w-full bg-white rounded-2xl shadow p-8 border border-gray-100 text-center"><div class="w-14 h-14 bg-amber-100 text-amber-600 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl font-black">!</div><h1 class="text-xl font-black text-slate-900 mb-2">' . htmlspecialchars($title) . '</h1><p class="text-sm text-gray-600 mb-4">' . $help . '</p><p class="text-xs text-gray-400 font-mono bg-gray-50 rounded-lg p-3 break-all text-left">' . htmlspecialchars($msg) . '</p>' . $installLink . '<a href="index.php" class="back-home back-home--center inline-flex mt-6">Back to Home</a></div></body></html>');
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

/** Is the configured database installed (staff table present)? */
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

/** User-friendly message when a page catches a database error. */
function dbConnectionHelpMessage(): string
{
    if (!mysqlServerUp()) {
        if (function_exists('alcrosIsLocalXamppDefaults') && alcrosIsLocalXamppDefaults()) {
            return 'MySQL is not running. Start it in the XAMPP Control Panel, then refresh this page.';
        }

        if (!is_file(__DIR__ . '/database.local.php') && alcrosIsLocalXamppDefaults()) {
            return 'Cannot connect to MySQL. On InfinityFree, create config/database.local.php from database.local.php.example (host must not be localhost).';
        }

        return 'Cannot connect to MySQL. Check config/database.local.php or ALCROS_DB_* environment variables.';
    }
    if (!databaseIsInstalled()) {
        return 'Database not installed yet. Run install.php once or import database/alcros.sql and alcros_print.sql in phpMyAdmin.';
    }

    return 'Database error. Verify MySQL credentials and that the user can access database ' . DB_NAME . '.';
}

function createDBConnection(): PDO
{
    if (!mysqlServerUp()) {
        if (function_exists('alcrosIsLocalXamppDefaults') && alcrosIsLocalXamppDefaults()) {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $onFreeHost = str_contains($host, '42web.io')
                || str_contains($host, 'infinityfree')
                || str_contains($host, 'epizy.com')
                || str_contains($host, 'rf.gd');
            if ($onFreeHost && !is_file(__DIR__ . '/database.local.php')) {
                $help = 'This server still uses XAMPP database defaults (<code>localhost</code>). '
                    . 'Add <strong>config/database.local.php</strong> with your InfinityFree MySQL host and credentials, '
                    . 'or set GitHub secrets and redeploy. If you edited <code>database.php</code> before, a git deploy may have overwritten it.';
            } else {
                $help = $onFreeHost
                    ? 'Check <strong>config/database.local.php</strong> (MySQL hostname from hPanel, not <code>localhost</code>).'
                    : 'Start <strong>MySQL</strong> in the XAMPP Control Panel, then refresh this page. You do <em>not</em> need to run install.php again if you already installed before.';
            }
        } else {
            $help = 'Check <strong>config/database.local.php</strong> or your server MySQL settings.';
        }
        dbUnavailablePage(
            'MySQL Connection Failed',
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
                'Database Not Found',
                'Run <a href="install.php" class="text-blue-600 font-bold">install.php</a> once, or create database <code>' . htmlspecialchars(DB_NAME) . '</code> and import the SQL files.',
                $msg
            );
        }
        dbUnavailablePage('Database Error', 'Check MySQL host, user, password, and database name in config/database.php.', $msg);
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
