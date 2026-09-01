<?php
/**
 * Temporary test-data cleaner. Admin only.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

requireAdmin();

$messages = [];
$error = null;
$counts = [];

function tableCount(PDO $pdo, string $table): int
{
    try {
        return (int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function deleteUploadedIds(string $dir): int
{
    $removed = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    foreach (scandir($dir) ?: [] as $file) {
        if ($file === '.' || $file === '..' || $file === '.gitkeep') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        if (is_file($path) && @unlink($path)) {
            $removed++;
        }
    }
    return $removed;
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
    $error = $e->getMessage();
}

if ($pdo) {
    $counts = [
        'document_requests' => tableCount($pdo, 'document_requests'),
        'appointments'      => tableCount($pdo, 'appointments'),
        'queue_tickets'     => tableCount($pdo, 'queue_tickets'),
        'civil_records'     => tableCount($pdo, 'civil_records'),
        'activity_logs'     => tableCount($pdo, 'activity_logs'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    requireStaffPostCsrf();
    if (trim((string) ($_POST['confirm'] ?? '')) !== 'CLEAR') {
        $error = 'Type CLEAR in the box to confirm.';
    } else {
        try {
            $pdo->beginTransaction();
            foreach (['document_requests', 'appointments', 'queue_tickets', 'civil_records', 'activity_logs'] as $table) {
                $pdo->exec("DELETE FROM `$table`");
                $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
            }
            $pdo->commit();

            $filesRemoved = deleteUploadedIds(__DIR__ . '/uploads/ids');
            $lockFile = __DIR__ . '/storage/appointment_reminders.lock';
            if (is_file($lockFile)) {
                @unlink($lockFile);
            }

            $pdo->prepare('INSERT INTO activity_logs (staff_id, action, details) VALUES (?, ?, ?)')
                ->execute(['SYSTEM', 'Test Data Cleared', 'Temporary cleaner removed all sample citizen data.']);

            $messages[] = 'Sample data cleared.';
            $messages[] = 'Removed '
                . $counts['document_requests'] . ' request(s), '
                . $counts['appointments'] . ' appointment(s), '
                . $counts['queue_tickets'] . ' queue ticket(s), '
                . $counts['civil_records'] . ' civil record(s), '
                . $counts['activity_logs'] . ' activity log(s), and '
                . $filesRemoved . ' uploaded ID file(s).';
            $messages[] = 'Staff accounts and office settings were kept.';

            $counts = [
                'document_requests' => tableCount($pdo, 'document_requests'),
                'appointments'      => tableCount($pdo, 'appointments'),
                'queue_tickets'     => tableCount($pdo, 'queue_tickets'),
                'civil_records'     => tableCount($pdo, 'civil_records'),
                'activity_logs'     => tableCount($pdo, 'activity_logs'),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clear Test Data - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?= publicStylesheet('back-home') ?>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-md w-full bg-white rounded-2xl shadow p-8 border border-gray-100">
        <p class="text-[10px] font-bold uppercase tracking-widest text-amber-600 mb-2">Temporary tool</p>
        <h1 class="text-xl font-black text-slate-900 mb-2">Clear test data</h1>
        <p class="text-sm text-gray-500 mb-5">Deletes all sample citizen records in one run. Staff logins and office settings stay.</p>

        <?php if ($pdo): ?>
        <ul class="mb-6 text-xs text-gray-600 space-y-1 bg-gray-50 rounded-xl p-4">
            <li>Document requests: <strong><?= (int) $counts['document_requests'] ?></strong></li>
            <li>Appointments: <strong><?= (int) $counts['appointments'] ?></strong></li>
            <li>Queue tickets: <strong><?= (int) $counts['queue_tickets'] ?></strong></li>
            <li>Civil records: <strong><?= (int) $counts['civil_records'] ?></strong></li>
            <li>Activity logs: <strong><?= (int) $counts['activity_logs'] ?></strong></li>
        </ul>

        <form method="POST" data-no-confirm>
            <?= authFormField() ?>
            <label class="block text-[11px] font-bold text-gray-500 mb-1">Type CLEAR to confirm</label>
            <input type="text" name="confirm" autocomplete="off" class="w-full mb-4 border border-gray-200 rounded-xl px-3 py-2 text-sm" placeholder="CLEAR">
            <button type="submit" class="w-full bg-rose-600 hover:bg-rose-700 text-white rounded-xl py-3 text-sm font-bold">Clear all sample data</button>
        </form>
        <?php endif; ?>

        <a href="index.php" class="back-home back-home--center block mt-4">Back to Home</a>
        <p class="mt-4 text-[11px] text-gray-400">Development tool — remove before production deploy.</p>
    </div>
    <?php
    $clearDataFlash = null;
    if ($error) {
        $clearDataFlash = ['error', $error];
    } elseif (!empty($messages)) {
        $clearDataFlash = ['success', implode(' ', $messages)];
    }
    ?>
    <?= actionResultScript($clearDataFlash) ?>
    <?= scriptTag('core/action-result.js') ?>
</body>
</html>
