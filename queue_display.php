<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/api_helpers.php';

$pdo = getDB();
$display = fetchPublicQueueDisplay($pdo);
$serving = $display['serving'];
$tableSlots = $display['tables'] ?? [];
$tables = queuePurposeConfig();
$purposeLabels = queuePurposeLabels();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALCROS LIVE - Public Announcement Display</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap');
        body { font-family: 'Inter', sans-serif; background-color: #020617; color: white; overflow: hidden; }
        .marquee { white-space: nowrap; overflow: hidden; display: inline-block; animation: marquee 30s linear infinite; }
        @keyframes marquee { 0% { transform: translateX(100%); } 100% { transform: translateX(-100%); } }
    </style>
</head>
<body class="h-screen w-screen flex flex-col relative p-4" data-realtime="queue-display">
    <header class="flex items-center justify-between px-6 py-4 rounded-2xl border border-gray-800 mb-4">
        <div class="flex items-center gap-4">
            <div class="bg-blue-600 p-2 rounded-xl"><i data-lucide="monitor" class="w-6 h-6 text-white"></i></div>
            <div>
                <h2 class="text-xl font-black tracking-tighter leading-none">ALCROS<span class="text-blue-500 italic">LIVE</span></h2>
                <p class="text-[9px] font-bold text-gray-500 uppercase tracking-widest mt-1">Public Queue Display · Table 1 Walk-in · Table 2 Appointment · Table 3 Claim</p>
            </div>
        </div>
        <div class="flex items-center gap-4">
            <div class="text-right hidden sm:block">
                <p id="voice-status" class="text-[9px] font-bold uppercase tracking-wider text-gray-500">Voice off</p>
                <button type="button" id="voice-toggle-btn" class="mt-1 px-3 py-1 rounded-lg text-[9px] font-bold uppercase tracking-wider text-white bg-gray-700 hover:opacity-90 transition-opacity">
                    Enable voice
                </button>
                <button type="button" id="voice-test-btn" class="mt-1 ml-1 px-3 py-1 rounded-lg text-[9px] font-bold uppercase tracking-wider text-blue-300 border border-blue-500/40 hover:bg-blue-500/10 transition-colors">
                    Test
                </button>
            </div>
            <div class="text-right">
                <p class="text-[8px] font-bold text-gray-500 uppercase">Local Time</p>
                <span id="clock" class="text-2xl font-black"><?= date('h:i A') ?></span>
            </div>
        </div>
    </header>

    <div id="display-tables" class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
        <?php foreach ($tables as $purpose => $cfg):
            $slot = $tableSlots[$purpose] ?? ['serving' => null, 'waiting' => []];
        ?>
        <div class="display-table-slot rounded-2xl border border-gray-800 bg-[#0f172a]/60 p-4 text-center" data-purpose="<?= htmlspecialchars($purpose) ?>">
            <p class="text-[9px] font-black uppercase tracking-widest text-gray-500 mb-1">Table <?= (int) $cfg['table'] ?></p>
            <p class="text-xs font-bold text-gray-400 mb-2"><?= htmlspecialchars($cfg['label']) ?></p>
            <?php if (!empty($slot['serving'])): ?>
            <p class="display-table-number text-3xl font-black text-white"><?= htmlspecialchars($slot['serving']) ?></p>
            <?php else: ?>
            <p class="display-table-number text-lg font-bold text-gray-600">—</p>
            <?php endif; ?>
            <p class="display-table-wait text-[9px] text-gray-600 mt-1"><?= count($slot['waiting'] ?? []) ?> waiting</p>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-grow gap-4 overflow-hidden min-h-0">
        <div class="flex-[2] flex flex-col border border-gray-800 rounded-3xl relative overflow-hidden bg-gradient-to-br from-[#0f172a] to-[#020617]">
            <div class="absolute top-8 left-0 right-0 text-center"><p class="text-sm font-black tracking-[0.3em] text-gray-500 uppercase">Now Calling</p></div>
            <div id="display-serving" class="flex-grow flex flex-col items-center justify-center px-4">
                <?php if ($serving): ?>
                <p class="text-8xl font-black text-white mb-2"><?= htmlspecialchars($serving['ticket_number']) ?></p>
                <p class="text-blue-400 text-lg font-bold uppercase">Table <?= (int) ($serving['window_number'] ?? queueTableForPurpose($serving['purpose'])) ?></p>
                <p class="text-gray-500 text-sm mt-2"><?= htmlspecialchars($purposeLabels[$serving['purpose']] ?? '') ?></p>
                <?php else: ?>
                <i data-lucide="users" class="w-16 h-16 text-gray-600 mb-4"></i>
                <p class="text-xs font-bold text-gray-600 uppercase tracking-widest">No Active Tickets</p>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex-1 flex flex-col border border-gray-800 rounded-3xl p-6 bg-[#0f172a]/30 min-w-0">
            <p class="text-xs font-black tracking-widest text-gray-500 uppercase mb-4">Up Next</p>
            <div id="display-waiting" class="flex-grow space-y-2 overflow-y-auto">
                <?php if (empty($display['waiting'])): ?>
                <p class="text-[10px] italic text-gray-700 text-center pt-8">No one in line.</p>
                <?php else: ?>
                <?php foreach ($display['waiting'] as $ticketNumber): ?>
                <p class="text-xl font-black text-gray-400"><?= htmlspecialchars($ticketNumber) ?></p>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <footer class="mt-4 bg-blue-600 h-10 flex items-center overflow-hidden rounded-xl">
        <div class="marquee w-full text-[10px] font-black uppercase tracking-wider px-4">
            Table 1 Walk-in · Table 2 Appointment · Table 3 Claim — Secure your queue number at the kiosk · Keep tracking codes for appointments and claims · Mabuhay!
        </div>
    </footer>

    <div id="voice-enable-overlay" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
        <div class="text-center max-w-md px-8">
            <div class="bg-blue-600 w-16 h-16 rounded-2xl flex items-center justify-center mx-auto mb-6">
                <i data-lucide="volume-2" class="w-8 h-8 text-white"></i>
            </div>
            <h3 class="text-2xl font-black mb-2">Enable Voice Announcements</h3>
            <p class="text-gray-400 text-sm mb-4">Browsers require one click before the display can announce queue numbers aloud.</p>
            <p class="text-gray-500 text-xs mb-8 leading-relaxed">For the lobby screen, open this page in its <strong class="text-gray-400">own browser window</strong> (not a background tab) and leave that window on the display monitor so announcements play automatically.</p>
            <button type="button" id="voice-enable-btn" class="bg-blue-600 hover:bg-blue-500 text-white px-8 py-4 rounded-2xl text-sm font-black uppercase tracking-wider transition-colors">
                Enable voice
            </button>
        </div>
    </div>

    <?= scriptTag('public/queue-display.js') ?>
    <?= scriptTag('core/poll.js') ?>
    <?= scriptTag('public/queue-voice.js') ?>
    <?= scriptTag('core/realtime.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
