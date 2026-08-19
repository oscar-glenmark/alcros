<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireAdmin();

$activePage = 'report.php';
$pdo = getDB();

$reportData = [
    'requests_today'    => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE DATE(submitted_at) = CURDATE()")->fetchColumn(),
    'appointments_today'=> (int) $pdo->query("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()")->fetchColumn(),
    'queue_served'      => (int) $pdo->query("SELECT COUNT(*) FROM queue_tickets WHERE status = 'completed' AND DATE(created_at) = CURDATE()")->fetchColumn(),
    'pending_requests'  => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'pending'")->fetchColumn(),
    'ready_for_pickup'  => (int) $pdo->query("SELECT COUNT(*) FROM document_requests WHERE status = 'ready'")->fetchColumn(),
    'total_records'     => (int) $pdo->query('SELECT COUNT(*) FROM civil_records')->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Operational Reports - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>body { font-family: 'Inter', sans-serif; background-color: #f8fafc; } .sidebar-item:hover { background-color: #f1f5f9; } .active-nav { background-color: #2563eb; color: white !important; }</style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="p-10">
            <h1 class="text-2xl font-black mb-2">Operational Reports</h1>
            <p class="text-gray-500 text-sm mb-8">Daily operational summary — <?= date('F j, Y') ?></p>
            <div class="grid grid-cols-3 gap-6">
                <?php
                $cards = [
                    ['label' => 'Requests Today', 'value' => $reportData['requests_today'], 'icon' => 'file-text', 'color' => 'blue'],
                    ['label' => 'Appointments Today', 'value' => $reportData['appointments_today'], 'icon' => 'calendar', 'color' => 'purple'],
                    ['label' => 'Queue Served Today', 'value' => $reportData['queue_served'], 'icon' => 'users', 'color' => 'green'],
                    ['label' => 'Pending Requests', 'value' => $reportData['pending_requests'], 'icon' => 'clock', 'color' => 'yellow'],
                    ['label' => 'Ready for Pickup', 'value' => $reportData['ready_for_pickup'], 'icon' => 'package', 'color' => 'emerald'],
                    ['label' => 'Total Civil Records', 'value' => $reportData['total_records'], 'icon' => 'database', 'color' => 'slate'],
                ];
                foreach ($cards as $c): ?>
                <div class="bg-white p-6 rounded-xl border flex items-start gap-4">
                    <div class="p-3 bg-<?= $c['color'] ?>-50 rounded-xl"><i data-lucide="<?= $c['icon'] ?>" class="w-5 h-5 text-<?= $c['color'] ?>-600"></i></div>
                    <div><p class="text-[10px] font-bold text-gray-400 uppercase"><?= $c['label'] ?></p><p class="text-3xl font-black"><?= $c['value'] ?></p></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
