<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireStaffLogin();
requirePageAccess('manage_request.php');

$activePage = 'manage_request.php';
$pdo = getDB();
migrateLegacyProcessingStatus($pdo);
ensureCitizenNotifyColumns($pdo);

function manageRequestsRedirectFilters(): array
{
    return [
        'status' => $_POST['redirect_status'] ?? $_GET['status'] ?? 'all',
        'q'      => $_POST['redirect_q'] ?? $_GET['q'] ?? '',
    ];
}

function updateDocumentRequestStatus(PDO $pdo, int $id, string $status): bool
{
    $valid = requestStatusUpdateOptions();
    if ($id <= 0 || !in_array($status, $valid, true)) {
        return false;
    }

    $oldStmt = $pdo->prepare('SELECT status, tracking_code FROM document_requests WHERE id = ?');
    $oldStmt->execute([$id]);
    $row = $oldStmt->fetch();
    if (!$row) {
        return false;
    }

    $oldStatus = (string) $row['status'];
    if ($oldStatus === $status) {
        return true;
    }

    $stmt = $pdo->prepare('UPDATE document_requests SET status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $id]);

    $checkStmt = $pdo->prepare('SELECT status FROM document_requests WHERE id = ?');
    $checkStmt->execute([$id]);
    $savedStatus = (string) $checkStmt->fetchColumn();
    if ($savedStatus !== $status) {
        return false;
    }

    try {
        notifyRequestStatusChange($pdo, $id, $status);
    } catch (Throwable $e) {
        // Status is already saved; email failure should not block staff.
    }
    logActivity(staffId(), 'Request Updated', 'Changed ' . $row['tracking_code'] . ' to ' . $status);

    return true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $filters = manageRequestsRedirectFilters();
    $id = (int) ($_POST['request_id'] ?? 0);

    $isDelete = isset($_POST['delete_request']);
    $isUpdate = isset($_POST['update_status']) || (!$isDelete && isset($_POST['status']));

    if ($isUpdate) {
        $status = (string) ($_POST['status'] ?? '');
        if (updateDocumentRequestStatus($pdo, $id, $status)) {
            manageRequestsFlashSet('success', 'Request status saved as ' . requestStatusLabel($status) . '.');
        } else {
            manageRequestsFlashSet('error', 'Could not update request status. Please try again.');
        }
    } elseif ($isDelete) {
        if (deleteCompletedDocumentRequest($pdo, $id)) {
            manageRequestsFlashSet('success', 'Completed request deleted successfully.');
        } else {
            manageRequestsFlashSet('error', 'Only completed requests can be deleted.');
        }
    }

    redirectWithAuth('manage_request.php', array_filter($filters, static fn ($value) => $value !== '' && $value !== 'all'));
}

$filterStatus = $_GET['status'] ?? 'all';
$search       = trim($_GET['q'] ?? '');
$flash        = manageRequestsFlashGet();

$sql = 'SELECT * FROM document_requests WHERE 1=1';
$params = [];
if ($filterStatus !== 'all' && $filterStatus !== '') {
    $sql .= ' AND status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $sql .= ' AND (citizen_name LIKE ? OR tracking_code LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}
$sql .= ' ORDER BY submitted_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$statusFilters = ['all' => 'All', 'pending' => 'Pending', 'verified' => 'Verified', 'ready' => 'Ready', 'completed' => 'Completed', 'rejected' => 'Rejected'];
$statusOptions = requestStatusUpdateOptions();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Requests - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .sidebar-item:hover { background-color: #f1f5f9; }
        .active-nav { background-color: #2563eb; color: white !important; }
        .status-btn { font-size: 10px; font-weight: 700; padding: 6px 12px; border-radius: 6px; text-transform: uppercase; transition: all 0.2s; }
        .status-btn-inactive { color: #94a3b8; }
        .status-btn-active { background-color: #f1f5f9; color: #1e293b; }
    </style>
</head>
<body class="flex min-h-screen">

    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>

    <main class="flex-1 ml-64 flex flex-col min-h-screen">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>

        <div class="p-10 max-w-7xl mx-auto w-full">
            <div class="mb-8">
                <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                    <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
                </a>
                <div class="flex justify-between items-start">
                    <div>
                        <h1 class="text-3xl font-black text-slate-900 tracking-tight">Manage Requests</h1>
                        <p class="text-gray-500 text-sm font-medium mt-1">Review, verify, and track citizen certificate requests.</p>
                    </div>
                </div>
            </div>

            <?php if ($flash): ?>
            <div class="mb-6 p-4 <?= $flash[0] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?> border text-sm rounded-xl flex items-center gap-2">
                <i data-lucide="<?= $flash[0] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 shrink-0"></i>
                <span><?= htmlspecialchars($flash[1]) ?></span>
            </div>
            <?php endif; ?>

            <form method="GET" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 flex items-center justify-between mb-6">
                <?= authFormField() ?>
                <div class="relative flex-1 max-w-2xl">
                    <i data-lucide="search" class="absolute left-3 top-2.5 w-4 h-4 text-gray-400"></i>
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search citizen name or tracking code..." class="w-full pl-10 pr-4 py-2 text-sm bg-gray-50 border-none rounded-lg focus:ring-0 text-slate-600 placeholder-gray-400">
                </div>
                <div class="flex items-center bg-gray-50 p-1 rounded-lg border border-gray-100 ml-4">
                    <?php foreach ($statusFilters as $key => $label): ?>
                    <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', array_filter(['status' => $key !== 'all' ? $key : null, 'q' => $search !== '' ? $search : null]))) ?>" class="status-btn <?= $filterStatus === $key ? 'status-btn-active' : 'status-btn-inactive px-4' ?>"><?= $label ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($filterStatus !== 'all'): ?>
                <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                <?php endif; ?>
                <button type="submit" class="ml-2 bg-blue-600 text-white px-4 py-2 rounded-lg text-xs font-bold" data-loading-text="Searching…">Search</button>
            </form>

            <?php if (empty($requests)): ?>
            <div class="bg-white rounded-2xl border border-dashed border-gray-200 min-h-[400px] flex flex-col items-center justify-center text-center p-12">
                <div class="bg-gray-50 p-6 rounded-full mb-6">
                    <i data-lucide="file-text" class="w-12 h-12 text-gray-200"></i>
                </div>
                <h2 class="text-xl font-black text-slate-900 mb-2">No requests found</h2>
                <p class="text-gray-400 text-sm max-w-xs font-medium">Try adjusting your filters or search terms.</p>
            </div>
            <?php else: ?>
            <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                <table class="w-full text-left text-sm">
                    <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400">
                        <tr>
                            <th class="px-4 py-3">Tracking</th>
                            <th class="px-4 py-3">Citizen</th>
                            <th class="px-4 py-3">Document</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Submitted</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <?php foreach ($requests as $req): ?>
                        <tr data-request-row="<?= (int) $req['id'] ?>">
                            <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($req['tracking_code']) ?></td>
                            <td class="px-4 py-3 font-semibold"><?= htmlspecialchars($req['citizen_name']) ?></td>
                            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars(documentTypeLabel($req['document_type'])) ?></td>
                            <td class="px-4 py-3"><?= requestStatusBadge($req['status']) ?></td>
                            <td class="px-4 py-3 text-gray-400 text-xs"><?= formatDateDisplay($req['submitted_at']) ?></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1 items-center">
                                    <button type="button"
                                            class="view-request-btn text-gray-400 hover:text-blue-600 p-1"
                                            title="View request details"
                                            data-request="<?= htmlspecialchars(json_encode(documentRequestViewData($req), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                    <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="flex gap-1 items-center">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($filterStatus) ?>">
                                        <input type="hidden" name="redirect_q" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="status" required class="text-[10px] border rounded px-2 py-1">
                                            <?php if (!in_array($req['status'], $statusOptions, true)): ?>
                                            <option value="" selected disabled>Select status</option>
                                            <?php endif; ?>
                                            <?php foreach ($statusOptions as $s): ?>
                                            <option value="<?= $s ?>" <?= $req['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars(requestStatusLabel($s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="bg-blue-600 text-white px-2 py-1 rounded text-[10px] font-bold" data-loading-text="Saving…">Save</button>
                                    </form>
                                    <?php if ($req['status'] === 'completed'): ?>
                                    <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('manage_request.php')) ?>" class="inline">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($filterStatus) ?>">
                                        <input type="hidden" name="redirect_q" value="<?= htmlspecialchars($search) ?>">
                                        <input type="hidden" name="request_id" value="<?= (int) $req['id'] ?>">
                                        <input type="hidden" name="delete_request" value="1">
                                        <button type="submit" title="Delete completed request" class="text-gray-400 hover:text-red-500 p-1" data-loading-text="Deleting…" onclick="return confirm(<?= json_encode('Delete this completed request (' . $req['tracking_code'] . ')? This cannot be undone.') ?>)">
                                            <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="requestViewModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-black/40" role="dialog" aria-modal="true" aria-labelledby="requestViewTitle">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-blue-600 mb-1">Request Details</p>
                    <h2 id="requestViewTitle" class="text-lg font-black text-slate-900">Citizen Submission</h2>
                    <p id="requestViewCode" class="text-xs font-mono font-bold text-blue-600 mt-1"></p>
                </div>
                <button type="button" id="requestViewClose" class="text-gray-400 hover:text-slate-700 p-1 rounded-lg hover:bg-gray-50" aria-label="Close">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="px-6 py-5 overflow-y-auto space-y-5 text-sm">
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Personal Information</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><dt class="text-[11px] text-gray-400">Full Name</dt><dd id="view-citizen-name" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Date of Birth</dt><dd id="view-dob" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Sex</dt><dd id="view-sex" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Phone</dt><dd id="view-phone" class="font-semibold text-slate-800"></dd></div>
                        <div class="sm:col-span-2"><dt class="text-[11px] text-gray-400">Email</dt><dd id="view-email" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Email Verified</dt><dd id="view-email-verified" class="font-semibold text-slate-800"></dd></div>
                    </dl>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Request Information</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><dt class="text-[11px] text-gray-400">Document Type</dt><dd id="view-document-type" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Purpose</dt><dd id="view-purpose" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Preferred Visit</dt><dd id="view-appointment" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Status</dt><dd id="view-status" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Privacy Agreed</dt><dd id="view-privacy" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Submitted</dt><dd id="view-submitted" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Last Updated</dt><dd id="view-updated" class="font-semibold text-slate-800"></dd></div>
                    </dl>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Uploaded IDs</p>
                    <div id="view-id-files" class="flex flex-wrap gap-2"></div>
                </div>
                <div id="view-notes-wrap" class="hidden">
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Staff Notes</p>
                    <p id="view-notes" class="text-sm text-slate-700 bg-gray-50 rounded-xl p-3 border border-gray-100"></p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                <button type="button" id="requestViewCloseFooter" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold">Close</button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        (function () {
            var modal = document.getElementById('requestViewModal');
            if (!modal) return;

            function setText(id, value) {
                var el = document.getElementById(id);
                if (el) el.textContent = value || '—';
            }

            function idLink(label, path) {
                if (!path) return '';
                var isPdf = /\.pdf$/i.test(path);
                return '<a href="' + path.replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-xs font-bold text-blue-600 bg-blue-50 border border-blue-100 px-3 py-2 rounded-lg hover:bg-blue-100">' +
                    '<i data-lucide="' + (isPdf ? 'file-text' : 'image') + '" class="w-3.5 h-3.5"></i>' + label + '</a>';
            }

            function openRequestModal(data) {
                setText('requestViewCode', data.tracking_code || '');
                setText('view-citizen-name', data.citizen_name);
                setText('view-dob', data.date_of_birth);
                setText('view-sex', data.sex);
                setText('view-phone', data.phone);
                setText('view-email', data.email);
                setText('view-email-verified', data.email_verified);
                setText('view-document-type', data.document_type);
                setText('view-purpose', data.purpose);
                setText('view-appointment', data.appointment);
                setText('view-status', data.status);
                setText('view-privacy', data.privacy_agreed);
                setText('view-submitted', data.submitted_at);
                setText('view-updated', data.updated_at);

                var idFiles = document.getElementById('view-id-files');
                if (idFiles) {
                    var html = idLink('Front ID', data.id_front_path) + idLink('Back ID', data.id_back_path);
                    idFiles.innerHTML = html || '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
                }

                var notesWrap = document.getElementById('view-notes-wrap');
                var notesEl = document.getElementById('view-notes');
                if (notesWrap && notesEl) {
                    if (data.notes) {
                        notesEl.textContent = data.notes;
                        notesWrap.classList.remove('hidden');
                    } else {
                        notesWrap.classList.add('hidden');
                    }
                }

                modal.classList.remove('hidden');
                modal.classList.add('flex');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }

            function closeRequestModal() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.view-request-btn');
                if (btn) {
                    var raw = btn.getAttribute('data-request');
                    if (!raw) return;
                    try {
                        openRequestModal(JSON.parse(raw));
                    } catch (err) {}
                    return;
                }
                if (e.target === modal) closeRequestModal();
            });

            document.getElementById('requestViewClose')?.addEventListener('click', closeRequestModal);
            document.getElementById('requestViewCloseFooter')?.addEventListener('click', closeRequestModal);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('flex')) closeRequestModal();
            });

            document.addEventListener('alcros:requests-refreshed', function () {
                if (typeof lucide !== 'undefined') lucide.createIcons();
            });
        })();
    </script>
</body>
</html>
