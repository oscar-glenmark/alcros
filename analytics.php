<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireAdmin();

$activePage = 'analytics.php';
$pdo = getDB();

$totalRequests = (int) $pdo->query('SELECT COUNT(*) FROM document_requests')->fetchColumn();
$byType = $pdo->query("SELECT document_type, COUNT(*) as cnt FROM document_requests GROUP BY document_type")->fetchAll();
$byStatus = $pdo->query("SELECT status, COUNT(*) as cnt FROM document_requests GROUP BY status")->fetchAll();
$monthly = $pdo->query("SELECT DATE_FORMAT(submitted_at, '%Y-%m') as month, COUNT(*) as cnt FROM document_requests GROUP BY month ORDER BY month DESC LIMIT 6")->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - ALCROS</title>
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
            <h1 class="text-2xl font-black mb-2">Analytics</h1>
            <p class="text-gray-500 text-sm mb-8">Document request statistics from MySQL.</p>
            <div class="grid grid-cols-4 gap-4 mb-8">
                <div class="bg-white p-6 rounded-xl border"><p class="text-[10px] font-bold text-gray-400 uppercase">Total Requests</p><p class="text-3xl font-black"><?= $totalRequests ?></p></div>
                <?php foreach ($byStatus as $row): ?>
                <div class="bg-white p-6 rounded-xl border"><p class="text-[10px] font-bold text-gray-400 uppercase"><?= htmlspecialchars(ucfirst($row['status'])) ?></p><p class="text-3xl font-black"><?= (int) $row['cnt'] ?></p></div>
                <?php endforeach; ?>
            </div>
            <div class="grid grid-cols-2 gap-6">
                <div class="bg-white rounded-xl border p-6">
                    <h3 class="font-bold text-sm mb-4">By Document Type</h3>
                    <?php foreach ($byType as $row): ?>
                    <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
                        <span><?= htmlspecialchars(documentTypeLabel($row['document_type'])) ?></span>
                        <span class="font-bold"><?= (int) $row['cnt'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="bg-white rounded-xl border p-6">
                    <h3 class="font-bold text-sm mb-4">Monthly Submissions</h3>
                    <?php foreach ($monthly as $row): ?>
                    <div class="flex justify-between py-2 border-b border-gray-50 text-sm">
                        <span><?= htmlspecialchars($row['month']) ?></span>
                        <span class="font-bold"><?= (int) $row['cnt'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </main>
    <script>lucide.createIcons();</script>
</body>
</html>
