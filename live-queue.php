<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';
require_once __DIR__ . '/includes/api_helpers.php';
requireStaffLogin();
requirePageAccess('live-queue.php');

$activePage = 'live-queue.php';
$pdo = getDB();
$tables = queuePurposeConfig();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = (string) ($_POST['action'] ?? '');
    $purpose = (string) ($_POST['purpose'] ?? '');

    if ($action === 'next' && isset($tables[$purpose])) {
        $tableNum = queueTableForPurpose($purpose);
        $calledTicket = null;

        $serving = $pdo->prepare(
            "SELECT id, ticket_number FROM queue_tickets WHERE purpose = ? AND status = 'serving' AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $serving->execute([$purpose]);
        if ($row = $serving->fetch()) {
            $pdo->prepare("UPDATE queue_tickets SET status = 'completed' WHERE id = ?")->execute([$row['id']]);
        }

        $next = $pdo->prepare(
            "SELECT id, ticket_number FROM queue_tickets
             WHERE purpose = ? AND status = 'waiting' AND DATE(created_at) = CURDATE()
             ORDER BY created_at ASC LIMIT 1"
        );
        $next->execute([$purpose]);
        if ($row = $next->fetch()) {
            $pdo->prepare(
                "UPDATE queue_tickets SET status = 'serving', called_at = NOW(), window_number = ? WHERE id = ?"
            )->execute([$tableNum, $row['id']]);
            $calledTicket = $row['ticket_number'];
            logActivity(staffId(), 'Queue', "Table $tableNum: called {$row['ticket_number']}");
        }

        if ($calledTicket) {
            queueFlashSet('success', "Now calling $calledTicket at Table $tableNum.");
        } else {
            queueFlashSet('success', 'Current ticket finished. No one else is waiting in this line.');
        }
    } elseif ($action === 'call_again' && isset($tables[$purpose])) {
        $tableNum = queueTableForPurpose($purpose);
        $serving = $pdo->prepare(
            "SELECT id, ticket_number FROM queue_tickets WHERE purpose = ? AND status = 'serving' AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $serving->execute([$purpose]);
        if ($row = $serving->fetch()) {
            $pdo->prepare(
                "UPDATE queue_tickets SET called_at = NOW(), window_number = ? WHERE id = ?"
            )->execute([$tableNum, $row['id']]);
            logActivity(staffId(), 'Queue', "Table $tableNum: called again {$row['ticket_number']}");
            queueFlashSet('success', "Calling {$row['ticket_number']} again at Table $tableNum.");
        } else {
            queueFlashSet('error', 'No ticket is currently being served at this table.');
        }
    } elseif ($action === 'skip' && isset($tables[$purpose])) {
        $serving = $pdo->prepare(
            "SELECT id, ticket_number FROM queue_tickets WHERE purpose = ? AND status = 'serving' AND DATE(created_at) = CURDATE() LIMIT 1"
        );
        $serving->execute([$purpose]);
        if ($row = $serving->fetch()) {
            $pdo->prepare("UPDATE queue_tickets SET status = 'skipped' WHERE id = ?")->execute([$row['id']]);
            logActivity(staffId(), 'Queue', "Skipped {$row['ticket_number']}");
            queueFlashSet('success', "Skipped {$row['ticket_number']}. Tap Call next for the next citizen.");
        } else {
            queueFlashSet('error', 'No ticket is currently being served at this table.');
        }
    } else {
        queueFlashSet('error', 'Could not update the queue. Please try again.');
    }

    redirectWithAuth('live-queue.php');
}

$flash = queueFlashGet();
$grouped = fetchQueueTicketsGrouped($pdo);
$totalWaiting = array_sum(array_map(fn ($g) => count($g['waiting']), $grouped));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Live Queue - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <?= adminLayoutHeadStyles() ?>
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="flex min-h-screen" data-realtime="queue">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="p-4 sm:p-6 lg:p-8 max-w-6xl mx-auto w-full admin-page-wrap">
            <div class="admin-page-head mb-6">
                <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4">
                    <div>
                        <h1>Live Queue</h1>
                        <p>One button per table — tap when you are ready for the next citizen.</p>
                    </div>
                    <a href="queue_display.php" target="_blank" class="text-xs font-bold text-blue-600 hover:underline shrink-0">Open display screen</a>
                </div>
            </div>

            <?php if ($flash): ?>
            <div class="mb-6 p-4 <?= $flash[0] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?> border text-sm rounded-xl flex items-center gap-2">
                <i data-lucide="<?= $flash[0] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 shrink-0"></i>
                <span><?= htmlspecialchars($flash[1]) ?></span>
            </div>
            <?php endif; ?>

            <p class="mb-6 text-sm font-semibold text-slate-600">
                <span id="stat-queue-waiting"><?= $totalWaiting ?></span> citizen<?= $totalWaiting === 1 ? '' : 's' ?> waiting total
            </p>

            <div id="queue-tables" class="grid grid-cols-1 md:grid-cols-3 gap-5">
                <?php foreach ($tables as $purpose => $cfg):
                    $serving   = $grouped[$purpose]['serving'];
                    $waiting   = $grouped[$purpose]['waiting'];
                    $waitCount = count($waiting);
                    $hasServing = (bool) $serving;
                    $canNext   = $hasServing || $waitCount > 0;

                    if ($hasServing && $waitCount > 0) {
                        $btnLabel = 'Finish & call next';
                    } elseif ($hasServing) {
                        $btnLabel = 'Finish (no one waiting)';
                    } elseif ($waitCount > 0) {
                        $btnLabel = 'Call next';
                    } else {
                        $btnLabel = 'No one waiting';
                    }
                ?>
                <section class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden" data-purpose="<?= htmlspecialchars($purpose) ?>">
                    <div class="px-5 py-3 <?= $cfg['bg'] ?> border-b border-slate-100">
                        <p class="text-[10px] font-black uppercase tracking-wider text-slate-500">Table <?= (int) $cfg['table'] ?></p>
                        <h2 class="text-lg font-black text-slate-900"><?= htmlspecialchars($cfg['label']) ?></h2>
                    </div>

                    <div class="p-5 text-center">
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-2">Now serving</p>
                        <div class="queue-serving-slot min-h-[72px] flex flex-col items-center justify-center mb-4" data-purpose="<?= htmlspecialchars($purpose) ?>">
                            <?php if ($serving): ?>
                            <p class="queue-serving-number text-5xl font-black text-slate-900 leading-none"><?= htmlspecialchars($serving['ticket_number']) ?></p>
                            <?php $servingName = personNameFromRow($serving); if ($servingName !== ''): ?>
                            <p class="text-sm text-slate-500 mt-2"><?= htmlspecialchars($servingName) ?></p>
                            <?php endif; ?>
                            <?php else: ?>
                            <p class="queue-serving-number text-4xl font-black text-slate-200">—</p>
                            <?php endif; ?>
                        </div>

                        <form method="POST" class="mb-4" data-no-confirm>
                            <?= authFormField() ?>
                            <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
                            <input type="hidden" name="action" value="next">
                            <button type="submit"
                                class="queue-next-btn w-full py-4 rounded-xl text-white font-black text-sm uppercase tracking-wide <?= $cfg['btn'] ?> disabled:opacity-40 disabled:cursor-not-allowed"
                                data-loading-text="Updating queue…"
                                <?= $canNext ? '' : 'disabled' ?>>
                                <?= htmlspecialchars($btnLabel) ?>
                            </button>
                        </form>

                        <?php if ($hasServing): ?>
                        <form method="POST" class="mb-4" data-no-confirm>
                            <?= authFormField() ?>
                            <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
                            <input type="hidden" name="action" value="call_again">
                            <button type="submit"
                                class="queue-call-again-btn w-full py-2.5 rounded-xl border-2 border-slate-200 text-slate-600 font-bold text-xs uppercase tracking-wide hover:bg-slate-50 flex items-center justify-center gap-2"
                                data-loading-text="Calling again…">
                                <i data-lucide="volume-2" class="w-4 h-4"></i> Call again
                            </button>
                        </form>
                        <?php else: ?>
                        <div class="queue-call-again-slot hidden mb-4"></div>
                        <?php endif; ?>

                        <div class="text-left">
                            <p class="queue-wait-badge text-xs text-slate-500 mb-2" data-purpose="<?= htmlspecialchars($purpose) ?>">
                                <?php if ($waitCount === 0): ?>
                                No one in line
                                <?php else: ?>
                                <span class="font-bold text-slate-700"><?= $waitCount ?></span> waiting —
                                next: <span class="queue-next-preview font-mono font-bold"><?= htmlspecialchars($waiting[0]['ticket_number']) ?></span>
                                <?php endif; ?>
                            </p>
                            <?php if ($waitCount > 1): ?>
                            <p class="queue-waiting-list text-[11px] text-slate-400 font-mono leading-relaxed" data-purpose="<?= htmlspecialchars($purpose) ?>">
                                Then: <?= htmlspecialchars(implode(', ', array_column(array_slice($waiting, 1), 'ticket_number'))) ?>
                            </p>
                            <?php else: ?>
                            <p class="queue-waiting-list text-[11px] text-slate-400 hidden" data-purpose="<?= htmlspecialchars($purpose) ?>"></p>
                            <?php endif; ?>
                        </div>

                        <?php if ($hasServing): ?>
                        <form method="POST" class="mt-4 pt-4 border-t border-slate-100" data-no-confirm>
                            <?= authFormField() ?>
                            <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
                            <input type="hidden" name="action" value="skip">
                            <button type="submit" class="text-[11px] text-slate-400 hover:text-red-500 font-medium">No-show — skip current</button>
                        </form>
                        <?php else: ?>
                        <div class="queue-skip-slot hidden"></div>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>

            <p class="mt-8 text-center text-xs text-slate-400">
                Citizens get tickets at the <a href="kiosk.php" target="_blank" class="text-blue-500 underline">kiosk</a>.
                Numbers starting with W, A, or C go to Tables 1, 2, and 3.
            </p>
        </div>
    </main>
    <?= scriptTag('core/poll.js') ?>
    <?= scriptTag('core/realtime.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
