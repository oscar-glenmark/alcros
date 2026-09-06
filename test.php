<?php
/**
 * Development tool — generate and remove sample document requests (ALR-T* codes).
 * Admin only. Remove or restrict before production.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/scripts.php';

requireAdmin();

$messages = [];
$error = null;
$created = [];
$pdo = null;
$testCount = 0;
$recentTests = [];

try {
    $pdo = getDB();
    migrateLegacyProcessingStatus($pdo);
    ensureCitizenNotifyColumns($pdo);
    $testCount = countTestDocumentRequests($pdo);

    $recentStmt = $pdo->prepare(
        "SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at
         FROM document_requests
         WHERE tracking_code LIKE 'ALR-T%' OR notes LIKE ?
         ORDER BY submitted_at DESC
         LIMIT 12"
    );
    $recentStmt->execute(['%' . testRequestMarker() . '%']);
    $recentTests = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo) {
    requireStaffPostCsrf();
    $action = (string) ($_POST['action'] ?? '');

    try {
        if ($action === 'seed') {
            $count = (int) ($_POST['count'] ?? 5);
            $created = seedTestDocumentRequests($pdo, $count, staffId());
            $messages[] = 'Created ' . count($created) . ' test request(s). Open Manage Requests to review them.';
            $testCount = countTestDocumentRequests($pdo);
            $recentStmt = $pdo->prepare(
                "SELECT tracking_code, first_name, middle_name, last_name, document_type, status, submitted_at
                 FROM document_requests
                 WHERE tracking_code LIKE 'ALR-T%' OR notes LIKE ?
                 ORDER BY submitted_at DESC
                 LIMIT 12"
            );
            $recentStmt->execute(['%' . testRequestMarker() . '%']);
            $recentTests = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
        } elseif ($action === 'delete') {
            if (trim((string) ($_POST['confirm'] ?? '')) !== 'DELETE') {
                $error = 'Type DELETE in the box to confirm removal.';
            } else {
                $removed = deleteTestDocumentRequests($pdo, staffId());
                $messages[] = $removed > 0
                    ? "Removed $removed test request(s) and any linked pickup appointments."
                    : 'No test requests were found to delete.';
                $testCount = countTestDocumentRequests($pdo);
                $recentTests = [];
            }
        } else {
            $error = 'Unknown action.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$pageTitle = 'Test Request Generator';
$pageSubtitle = 'Create and remove sample document requests for development and QA testing.';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Request Generator - ALCROS</title>
    <?= vendorScriptTag('tailwindcss.js') ?>
    <?= vendorStylesheetTag('inter/inter.css') ?>
    <?= adminLayoutHeadStyles() ?>
    <?= vendorScriptTag('lucide.min.js') ?>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-4 sm:p-6 lg:p-8 max-w-3xl w-full admin-page-wrap space-y-6">
            <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <strong>Development only.</strong> Test requests use tracking codes starting with <code class="font-mono text-xs bg-white/70 px-1 rounded">ALR-T</code> and are tagged in notes. They do not send citizen emails or SMS.
            </div>

            <?php if ($pdo): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="bg-white border border-slate-200 rounded-2xl p-5">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Test requests in database</p>
                    <p class="text-3xl font-black text-slate-900 mt-1"><?= number_format($testCount) ?></p>
                </div>
                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['status' => 'all_requests'])) ?>"
                   class="bg-white border border-slate-200 rounded-2xl p-5 hover:border-blue-300 hover:bg-blue-50/40 transition-colors flex flex-col justify-center">
                    <p class="text-sm font-bold text-slate-800">Open Manage Requests</p>
                    <p class="text-xs text-slate-500 mt-1">Review and process seeded data</p>
                </a>
            </div>

            <div class="bg-white border border-slate-200 rounded-2xl p-5 sm:p-6">
                <h2 class="text-base font-black text-slate-900">Generate test requests</h2>
                <p class="text-xs text-slate-500 mt-1 mb-4">Creates sample citizens with mixed statuses (pending, ready, completed, rejected).</p>
                <form method="POST" class="flex flex-wrap items-end gap-3" data-no-confirm>
                    <?= authFormField() ?>
                    <input type="hidden" name="action" value="seed">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-1">How many</label>
                        <select name="count" class="border border-slate-200 rounded-xl px-3 py-2.5 text-sm bg-white min-w-[8rem]">
                            <?php foreach ([1, 3, 5, 8, 10, 15, 20] as $n): ?>
                            <option value="<?= $n ?>" <?= $n === 5 ? 'selected' : '' ?>><?= $n ?> request<?= $n === 1 ? '' : 's' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold" data-loading-text="Generating…">
                        Generate now
                    </button>
                </form>

                <?php if ($created): ?>
                <div class="mt-4 rounded-xl border border-emerald-100 bg-emerald-50/60 p-4">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-800 mb-2">Just created</p>
                    <ul class="space-y-1 text-sm text-emerald-900">
                        <?php foreach ($created as $row): ?>
                        <li class="font-mono text-xs">
                            <?= htmlspecialchars($row['tracking_code']) ?>
                            · <?= htmlspecialchars($row['citizen']) ?>
                            · <?= htmlspecialchars(requestStatusLabel($row['status'])) ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($recentTests): ?>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h2 class="text-sm font-black text-slate-900">Recent test requests</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase text-slate-400">
                            <tr>
                                <th class="px-4 py-2 text-left">Tracking</th>
                                <th class="px-4 py-2 text-left">Citizen</th>
                                <th class="px-4 py-2 text-left">Document</th>
                                <th class="px-4 py-2 text-left">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($recentTests as $row): ?>
                            <tr>
                                <td class="px-4 py-2 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($row['tracking_code']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars(personNameFromRow($row)) ?></td>
                                <td class="px-4 py-2 text-slate-600"><?= htmlspecialchars(documentTypeLabel($row['document_type'])) ?></td>
                                <td class="px-4 py-2"><?= requestStatusBadge($row['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <div class="bg-white border border-red-200 rounded-2xl p-5 sm:p-6">
                <h2 class="text-base font-black text-red-900">Delete all test requests</h2>
                <p class="text-xs text-red-800/80 mt-1 mb-4">Removes only ALR-T sample requests (and linked pickup appointments). Real citizen requests are not affected.</p>
                <form method="POST" data-no-confirm>
                    <?= authFormField() ?>
                    <input type="hidden" name="action" value="delete">
                    <label class="block text-[11px] font-bold text-red-900/70 mb-1">Type DELETE to confirm</label>
                    <input type="text" name="confirm" autocomplete="off" class="w-full max-w-xs mb-3 border border-red-200 rounded-xl px-3 py-2 text-sm" placeholder="DELETE">
                    <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold" data-loading-text="Deleting…" <?= $testCount === 0 ? 'disabled' : '' ?>>
                        Delete test requests
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <?php
    $flash = null;
    if ($error) {
        $flash = ['error', $error];
    } elseif ($messages) {
        $flash = ['success', implode(' ', $messages)];
    }
    ?>
    <?= actionResultScript($flash) ?>
    <?= adminCoreScripts() ?>
    <?= lucideInitScript() ?>
</body>
</html>
