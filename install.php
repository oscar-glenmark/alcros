<?php
/**
 * One-time database setup for ALCROS.
 * Creates empty tables with default administrator — no other sample records.
 */
require_once __DIR__ . '/config/database.php';

$sqlFile = __DIR__ . '/database/alcros.sql';
$messages = [];
$error = null;
$alreadyInstalled = databaseIsInstalled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!mysqlServerUp()) {
            throw new RuntimeException('MySQL is not running. Start it in the XAMPP Control Panel first.');
        }
        if (!is_file($sqlFile)) {
            throw new RuntimeException('SQL file not found: database/alcros.sql');
        }

        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec(file_get_contents($sqlFile));

        $flagDir = __DIR__ . '/storage';
        if (!is_dir($flagDir)) {
            mkdir($flagDir, 0755, true);
        }
        file_put_contents($flagDir . '/installed.txt', date('c'));

        $messages[] = $alreadyInstalled
            ? 'Database refreshed successfully.'
            : 'Database installed successfully.';
        $messages[] = 'Default login: Staff ID <strong>ALORAN-001</strong>, Password <strong>aloran2024</strong>';
        $alreadyInstalled = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALCROS Database Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="includes/back_home.css">
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-md w-full bg-white rounded-2xl shadow p-8 border border-gray-100">
        <h1 class="text-xl font-black text-slate-900 mb-2">ALCROS Database Setup</h1>

        <?php if ($error): ?>
        <div class="mb-4 p-3 bg-red-50 border border-red-100 text-red-600 text-sm rounded-lg"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php foreach ($messages as $msg): ?>
        <div class="mb-4 p-3 bg-green-50 border border-green-100 text-green-700 text-sm rounded-lg"><?= $msg ?></div>
        <?php endforeach; ?>

        <?php if ($alreadyInstalled && $_SERVER['REQUEST_METHOD'] !== 'POST'): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-100 rounded-xl">
            <p class="text-sm font-bold text-green-800 mb-1">Already installed</p>
            <p class="text-xs text-green-700">Database <code class="bg-white px-1 rounded">alcros_db</code> is ready. You do <strong>not</strong> need to install again when restarting XAMPP — just start MySQL.</p>
        </div>
        <a href="index.php" class="back-home back-home--primary block mb-3">Go to ALCROS Home</a>
        <a href="login.php" class="block w-full text-center border border-gray-200 text-gray-600 rounded-xl py-3 text-sm font-bold">Staff Login</a>
        <details class="mt-4">
            <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600">Re-run setup anyway</summary>
            <form method="POST" class="mt-3">
                <p class="text-[11px] text-gray-500 mb-3">Safe to run again — uses IF NOT EXISTS. Use only if tables are missing.</p>
                <button type="submit" class="w-full bg-gray-800 hover:bg-gray-900 text-white rounded-xl py-2.5 text-xs font-bold">Refresh database</button>
            </form>
        </details>
        <?php else: ?>

        <p class="text-sm text-gray-500 mb-6">Creates <code class="bg-gray-100 px-1 rounded">alcros_db</code> with empty tables and the default administrator. No other sample records.</p>

        <?php if (empty($messages)): ?>
        <?php if (!mysqlServerUp()): ?>
        <div class="mb-4 p-3 bg-amber-50 border border-amber-100 text-amber-800 text-sm rounded-lg">
            Start <strong>MySQL</strong> in XAMPP Control Panel first.
        </div>
        <?php endif; ?>
        <form method="POST">
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold" <?= mysqlServerUp() ? '' : 'disabled' ?>>
                Install Database
            </button>
        </form>
        <?php else: ?>
        <a href="login.php" class="block w-full text-center bg-blue-600 hover:bg-blue-700 text-white rounded-xl py-3 text-sm font-bold mt-2">Staff Login</a>
        <a href="index.php" class="back-home back-home--outline block mt-2">Go to ALCROS Home</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
