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

$purposeButtonClass = [
    'walk_in'         => 'kiosk-choice-btn--walk-in',
    'appointment'     => 'kiosk-choice-btn--appointment',
    'document_claim'  => 'kiosk-choice-btn--claim',
];

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
            $ticket = createQueueTicket($pdo, $purpose);
        } catch (Throwable $e) {
            $error = 'Could not issue ticket. Please ask staff for help.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?= faviconLinkTag() ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ALCROS Queue Kiosk</title>
    <?= alcrosUiHead() ?>
    <?= publicStylesheet('kiosk') ?>
</head>
<body class="kiosk-body">
    <div class="kiosk-shell">
        <header class="kiosk-header">
            <div class="kiosk-header__brand">
                <?= alcrosFaviconImg(44, 'shadow-md shadow-blue-900/30') ?>
                <div>
                    <h2 class="kiosk-header__title">ALCROS<span>Queue</span></h2>
                    <p class="kiosk-header__sub">Self-service kiosk · Tap your visit type</p>
                </div>
            </div>
            <button type="button"
                    id="display-fullscreen-btn"
                    class="display-fullscreen-btn"
                    aria-label="Enter full screen"
                    title="Full screen">
                <i data-lucide="maximize" class="w-5 h-5" aria-hidden="true"></i>
            </button>
        </header>

        <main class="kiosk-main">
            <?php if ($ticket): ?>
            <div class="kiosk-ticket">
                <p class="kiosk-ticket__eyebrow">Your number</p>
                <p class="kiosk-ticket__number"><?= htmlspecialchars($ticket['number']) ?></p>
                <p class="kiosk-ticket__table">Go to Table <?= (int) $ticket['table'] ?></p>
                <p class="kiosk-ticket__purpose"><?= htmlspecialchars($ticket['label']) ?></p>
                <p class="kiosk-ticket__hint">Wait for your number on the display screen.</p>
                <a href="kiosk.php" class="kiosk-ticket__done">Done</a>
            </div>
            <?php else: ?>
            <div class="kiosk-intro">
                <h1 class="kiosk-intro__title">Get a queue number</h1>
                <p class="kiosk-intro__lead">Tap the reason for your visit</p>
            </div>

            <?php if ($error): ?>
            <p class="kiosk-error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <div class="kiosk-choices">
                <?php foreach ($tables as $purpose => $cfg): ?>
                <form method="POST" class="kiosk-form" data-no-confirm>
                    <?= publicCsrfField() ?>
                    <input type="hidden" name="purpose" value="<?= htmlspecialchars($purpose) ?>">
                    <button type="submit"
                        data-loading-text="Getting your number…"
                        class="kiosk-choice-btn <?= htmlspecialchars($purposeButtonClass[$purpose] ?? '') ?>">
                        <p class="kiosk-choice-btn__table">Table <?= (int) $cfg['table'] ?></p>
                        <p class="kiosk-choice-btn__label"><?= htmlspecialchars($cfg['label']) ?></p>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </main>
    </div>

    <?= actionCoreScripts() ?>
    <?= scriptTag('public/kiosk.js') ?>
    <?= lucideInitScript() ?>
</body>
</html>
