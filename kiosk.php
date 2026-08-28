<?php
/**
 * ALCROS Kiosk — one tap to get a queue number.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

$ticket = null;
$error = null;
$tables = queuePurposeConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requirePublicPostCsrf();
    $purpose = (string) ($_POST['purpose'] ?? '');
    $tablePurposeMap = ['1' => 'walk_in', '2' => 'appointment', '3' => 'document_claim'];
    if (isset($tablePurposeMap[$purpose])) {
        $purpose = $tablePurposeMap[$purpose];
    }
    if (!isset($tables[$purpose])) {
        $error = 'Invalid selection.';
    } else {
        try {
            $pdo = getDB();
            $ticketNumber = generateTicketNumber($pdo, $purpose);
            $tableNum = queueTableForPurpose($purpose);
            $pdo->prepare(
                'INSERT INTO queue_tickets (ticket_number, purpose, status, window_number) VALUES (?, ?, ?, ?)'
            )->execute([$ticketNumber, $purpose, 'waiting', $tableNum]);
            $ticket = [
                'number' => $ticketNumber,
                'table'  => $tableNum,
                'label'  => $tables[$purpose]['label'],
            ];
        } catch (PDOException $e) {
            $error = 'Could not issue ticket. Please ask staff for help.';
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
    <title>ALCROS Queue Kiosk</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; background: #020617; }
    </style>
</head>
<body class="flex items-center justify-center min-h-screen p-6">

    <div class="w-full max-w-3xl text-center">
        <?php if ($ticket): ?>
        <div class="bg-white rounded-3xl p-12 shadow-2xl max-w-sm mx-auto">
            <p class="text-slate-400 text-xs font-bold uppercase tracking-widest mb-3">Your number</p>
            <p class="text-7xl font-black text-blue-600 mb-4"><?= htmlspecialchars($ticket['number']) ?></p>
            <p class="text-slate-800 font-bold">Go to Table <?= (int) $ticket['table'] ?></p>
            <p class="text-slate-500 text-sm mt-1"><?= htmlspecialchars($ticket['label']) ?></p>
            <p class="text-slate-400 text-xs mt-6">Wait for your number on the screen.</p>
            <a href="kiosk.php" class="inline-block mt-8 bg-slate-900 text-white px-8 py-3 rounded-xl text-sm font-bold">Done</a>
        </div>
        <?php else: ?>
        <h1 class="text-3xl font-black text-white mb-2">Get a queue number</h1>
        <p class="text-slate-400 text-sm mb-10">Tap the reason for your visit</p>

        <?php if ($error): ?>
        <p class="text-red-400 text-sm mb-6"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <?php foreach ($tables as $purpose => $cfg): ?>
            <form method="POST" class="contents kiosk-form" data-no-confirm>
                <?= publicCsrfField() ?>
                <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
                <button type="submit"
                    data-loading-text="Getting your number…"
                    class="rounded-2xl p-8 text-left transition active:scale-95 shadow-lg w-full h-full <?= $purpose === 'walk_in' ? 'bg-white hover:bg-orange-50' : ($purpose === 'appointment' ? 'bg-blue-600 hover:bg-blue-500' : 'bg-emerald-600 hover:bg-emerald-500') ?>">
                    <p class="text-[10px] font-black uppercase tracking-widest mb-4 <?= $purpose === 'walk_in' ? 'text-orange-500' : 'text-white/70' ?>">
                        Table <?= (int) $cfg['table'] ?>
                    </p>
                    <p class="text-2xl font-black <?= $purpose === 'walk_in' ? 'text-slate-900' : 'text-white' ?>">
                        <?= htmlspecialchars($cfg['label']) ?>
                    </p>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?= actionCoreScripts() ?>
    <?= lucideInitScript() ?>
</body>
</html>
