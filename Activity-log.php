<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
requireAdmin();

$activePage = 'Activity-log.php';
$pdo = getDB();
$logs = $pdo->query('SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 100')->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity Log - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { font-family: 'Inter', sans-serif; background-color: #f8fafc; } .sidebar-item:hover { background-color: #f1f5f9; } .active-nav { background-color: #2563eb; color: white !important; }</style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="p-4 sm:p-6 lg:p-10">
            <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
            </a>
            <h1 class="text-2xl font-black mb-2">Activity Log</h1>
            <p class="text-gray-500 text-sm mb-8">Staff actions recorded in the database.</p>
            <div class="bg-white rounded-2xl border divide-y divide-gray-50">
                <?php if (empty($logs)): ?>
                <p class="p-12 text-center text-gray-400">No activity logged yet.</p>
                <?php else: ?>
                <?php foreach ($logs as $log): ?>
                <div class="p-4 flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4">
                    <div class="bg-blue-100 p-2 rounded text-blue-600 shrink-0"><i data-lucide="activity" class="w-4 h-4"></i></div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-bold"><?= htmlspecialchars($log['action']) ?></p>
                        <p class="text-[10px] text-gray-400"><?= htmlspecialchars($log['details'] ?? '') ?></p>
                    </div>
                    <div class="text-left sm:text-right text-[10px] text-gray-400 shrink-0">
                        <p class="font-bold"><?= htmlspecialchars($log['staff_id'] ?? 'System') ?></p>
                        <p><?= formatDateDisplay($log['created_at']) ?> · <?= formatTimeAgo($log['created_at']) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?= lucideInitScript() ?>
</body>
</html>
