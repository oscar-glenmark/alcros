<?php
/**
 * ALCROS MySQL connection
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'alcros_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

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
        return 'MySQL is not running. Start it in the XAMPP Control Panel, then refresh this page.';
    }
    if (!databaseIsInstalled()) {
        return 'Database not installed yet. Open install.php once while MySQL is running.';
    }
    return 'Database error. Check that MySQL is running in XAMPP.';
}

function createDBConnection(): PDO
{
    if (!mysqlServerUp()) {
        dbUnavailablePage(
            'MySQL Is Not Running',
            'Start <strong>MySQL</strong> in the XAMPP Control Panel, then refresh this page. You do <em>not</em> need to run install.php again if you already installed before.',
            'Connection refused (MySQL service stopped).'
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
                'Open <a href="install.php" class="text-blue-600 font-bold">install.php</a> <strong>once</strong> while MySQL is running. After that, you only need install.php again if the database was deleted.',
                $msg
            );
        }
        dbUnavailablePage('Database Error', 'Check config/database.php and that MySQL is running.', $msg);
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

function isRecoverableDbError(Throwable $e): bool
{
    if (!$e instanceof PDOException) {
        return false;
    }

    $code = (string) $e->getCode();
    $message = strtolower($e->getMessage());

    if (in_array($code, ['2006', '2013', '2002'], true)) {
        return true;
    }

    return str_contains($message, 'server has gone away')
        || str_contains($message, 'lost connection')
        || str_contains($message, 'error while sending')
        || str_contains($message, 'connection refused');
}

/** @template T */
function withDBRetry(callable $callback, int $attempts = 2): mixed
{
    $attempts = max(1, $attempts);
    $last = null;

    for ($i = 0; $i < $attempts; $i++) {
        try {
            return $callback(getDB($i > 0));
        } catch (Throwable $e) {
            $last = $e;
            if ($i >= $attempts - 1 || !isRecoverableDbError($e)) {
                throw $e;
            }
        }
    }

    throw $last ?? new RuntimeException('Database operation failed.');
}
