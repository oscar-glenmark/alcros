<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
requireStaffLogin();
requirePageAccess('appointment.php');

$activePage = 'appointment.php';
$pdo = getDB();
ensureCitizenNotifyColumns($pdo);

$viewDate = $_GET['date'] ?? $_POST['redirect_date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) {
    $viewDate = date('Y-m-d');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = (int) ($_POST['appointment_id'] ?? 0);
    $isDelete = isset($_POST['delete_appointment']);
    $isUpdate = isset($_POST['update_status']) || (!$isDelete && isset($_POST['status']));

    if ($isDelete) {
        if ($id > 0) {
            $codeStmt = $pdo->prepare('SELECT appointment_code, id_front_path, id_back_path FROM appointments WHERE id = ?');
            $codeStmt->execute([$id]);
            $row = $codeStmt->fetch();
            if ($row) {
                deleteIdUploadFiles($row['id_front_path'] ?? null, $row['id_back_path'] ?? null);
                $pdo->prepare('DELETE FROM appointments WHERE id = ?')->execute([$id]);
                logActivity(staffId(), 'Appointment Deleted', 'Deleted appointment ' . $row['appointment_code']);
                appointmentFlashSet('success', 'Appointment ' . $row['appointment_code'] . ' deleted.');
            }
        }
    } elseif ($isUpdate) {
        $status = (string) ($_POST['status'] ?? '');
        $valid = appointmentStatusUpdateOptions();
        if ($id > 0 && in_array($status, $valid, true)) {
            $pdo->prepare('UPDATE appointments SET status = ? WHERE id = ?')->execute([$status, $id]);
            $codeStmt = $pdo->prepare('SELECT appointment_code FROM appointments WHERE id = ?');
            $codeStmt->execute([$id]);
            $code = $codeStmt->fetchColumn();
            try {
                notifyAppointmentStatusChange($pdo, $id, $status);
            } catch (Throwable $e) {
            }
            logActivity(staffId(), 'Appointment Updated', 'Changed ' . ($code ?: "#$id") . " to $status");
            appointmentFlashSet('success', 'Appointment status saved as ' . appointmentStatusLabel($status) . '.');
        } else {
            appointmentFlashSet('error', 'Could not update appointment status. Please try again.');
        }
    }

    redirectWithAuth('appointment.php', ['date' => $viewDate]);
}

$flash = appointmentFlashGet();

$stmt = $pdo->prepare('SELECT * FROM appointments WHERE appointment_date = ? ORDER BY appointment_time ASC');
$stmt->execute([$viewDate]);
$appointments = $stmt->fetchAll();

$requestVisits = [];
$standaloneAppointments = [];
foreach ($appointments as $ap) {
    $isRequest = (($ap['source'] ?? '') === 'document_request') || !empty($ap['tracking_code']);
    if ($isRequest) {
        $requestVisits[] = $ap;
    } else {
        $standaloneAppointments[] = $ap;
    }
}

$prevDate = date('Y-m-d', strtotime($viewDate . ' -1 day'));
$nextDate = date('Y-m-d', strtotime($viewDate . ' +1 day'));

function renderAppointmentRows(array $rows, string $viewDate): void
{
    foreach ($rows as $ap): ?>
                        <tr data-appointment-row="<?= (int) $ap['id'] ?>">
                            <td class="px-4 py-3 font-mono text-xs font-bold text-blue-600"><?= htmlspecialchars($ap['appointment_code']) ?></td>
                            <td class="px-4 py-3">
                                <p class="font-semibold"><?= htmlspecialchars($ap['citizen_name']) ?></p>
                                <?php if (!empty($ap['tracking_code'])): ?>
                                <a href="<?= htmlspecialchars(buildAuthUrl('manage_request.php', ['q' => $ap['tracking_code']])) ?>" class="text-[10px] font-mono font-bold text-blue-600 hover:underline"><?= htmlspecialchars($ap['tracking_code']) ?></a>
                                <?php endif; ?>
                                <?php if (!empty($ap['id_front_path']) || !empty($ap['id_back_path'])): ?>
                                <p class="mt-1 flex flex-wrap gap-1.5">
                                    <?php if (!empty($ap['id_front_path'])): ?>
                                    <a href="<?= htmlspecialchars($ap['id_front_path']) ?>" target="_blank" rel="noopener noreferrer" class="text-[10px] font-bold text-blue-600 hover:underline">Front ID</a>
                                    <?php endif; ?>
                                    <?php if (!empty($ap['id_back_path'])): ?>
                                    <a href="<?= htmlspecialchars($ap['id_back_path']) ?>" target="_blank" rel="noopener noreferrer" class="text-[10px] font-bold text-blue-600 hover:underline">Back ID</a>
                                    <?php endif; ?>
                                </p>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-gray-500"><?= htmlspecialchars(appointmentServiceLabel($ap['service_type'])) ?></td>
                            <td class="px-4 py-3"><?= date('g:i A', strtotime($ap['appointment_time'])) ?></td>
                            <td class="px-4 py-3"><span class="text-[10px] font-bold uppercase"><?= htmlspecialchars(appointmentStatusLabel($ap['status'])) ?></span></td>
                            <td class="px-4 py-3">
                                <div class="flex gap-1 items-center">
                                    <button type="button"
                                            class="view-appointment-btn text-gray-400 hover:text-blue-600 p-1"
                                            title="View appointment details"
                                            data-appointment="<?= htmlspecialchars(json_encode(appointmentViewData($ap), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>">
                                        <i data-lucide="eye" class="w-4 h-4"></i>
                                    </button>
                                    <form method="POST" action="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate])) ?>" class="flex gap-1 items-center">
                                        <?= authFormField() ?>
                                        <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($viewDate) ?>">
                                        <input type="hidden" name="appointment_id" value="<?= (int) $ap['id'] ?>">
                                        <input type="hidden" name="update_status" value="1">
                                        <select name="status" required class="text-[10px] border rounded px-2 py-1">
                                            <?php if (!in_array($ap['status'], appointmentStatusUpdateOptions(), true)): ?>
                                            <option value="" selected disabled>Select status</option>
                                            <?php endif; ?>
                                            <?php foreach (appointmentStatusUpdateOptions() as $s): ?>
                                            <option value="<?= $s ?>" <?= $ap['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars(appointmentStatusLabel($s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="bg-blue-600 text-white px-2 py-1 rounded text-[10px] font-bold">Save</button>
                                    </form>
                                    <button type="button"
                                            class="delete-appointment-btn text-gray-300 hover:text-red-500 p-1 transition-colors"
                                            title="Delete appointment"
                                            data-appointment-id="<?= (int) $ap['id'] ?>"
                                            data-code="<?= htmlspecialchars($ap['appointment_code']) ?>">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
    <?php endforeach;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/png" href="images/favicon.png?v=2">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Management - ALCROS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; color: #1e293b; }
        .sidebar-item:hover { background-color: #f1f5f9; }
        .active-nav { background-color: #2563eb; color: white !important; }
        .date-navigator { background-color: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 6px 12px; }
        .row-pending-delete { opacity: 0.45; pointer-events: none; }
    </style>
</head>
<body class="flex min-h-screen">
    <?php require __DIR__ . '/includes/admin_sidebar.php'; ?>
    <main class="admin-main flex flex-col bg-[#fdfdfd]">
        <?php require __DIR__ . '/includes/admin_header.php'; ?>
        <div class="px-10 pt-10 pb-6">
            <a href="<?= htmlspecialchars(buildAuthUrl('dashboard.php')) ?>" class="text-blue-600 text-[11px] font-bold flex items-center mb-2 hover:underline">
                <i data-lucide="chevron-left" class="w-3 h-3 mr-1"></i> Back to Dashboard
            </a>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Appointment Management</h1>
            <p class="text-gray-500 text-sm font-medium">Special service bookings and document-request visits for the selected date.</p>
        </div>
        <div class="px-10 mb-6 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <div class="flex items-center date-navigator space-x-4">
                    <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $prevDate])) ?>" class="text-gray-400 hover:text-slate-900"><i data-lucide="chevron-left" class="w-4 h-4"></i></a>
                    <div class="flex items-center space-x-2 text-sm font-bold text-slate-800">
                        <i data-lucide="calendar" class="w-4 h-4 text-blue-600"></i>
                        <span><?= formatDateDisplay($viewDate) ?></span>
                    </div>
                    <a href="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $nextDate])) ?>" class="text-gray-400 hover:text-slate-900"><i data-lucide="chevron-right" class="w-4 h-4"></i></a>
                </div>
            </div>
        </div>
        <div class="px-10 flex-1 pb-10">
            <?php if ($flash): ?>
            <div class="mb-6 p-4 <?= $flash[0] === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-rose-50 border-rose-200 text-rose-800' ?> border text-sm rounded-xl flex items-center gap-2">
                <i data-lucide="<?= $flash[0] === 'success' ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 shrink-0"></i>
                <span><?= htmlspecialchars($flash[1]) ?></span>
            </div>
            <?php endif; ?>
            <?php if (empty($appointments)): ?>
            <div class="bg-white rounded-2xl border border-gray-100 shadow-sm min-h-[400px] flex flex-col items-center justify-center">
                <div class="bg-gray-50 p-4 rounded-xl mb-6"><i data-lucide="calendar-off" class="w-10 h-10 text-gray-200"></i></div>
                <h2 class="text-lg font-black text-slate-900 mb-1">No appointments found</h2>
                <p class="text-gray-400 text-xs font-medium">There are no appointments scheduled for this date.</p>
            </div>
            <?php else: ?>
            <div class="space-y-8">
                <section>
                    <div class="flex items-end justify-between gap-3 mb-3">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Document request visits</h2>
                            <p class="text-[11px] text-gray-400">Citizens who filed a certificate request and chose this date for pickup.</p>
                        </div>
                        <span class="text-[11px] font-bold text-gray-400"><?= count($requestVisits) ?> visit<?= count($requestVisits) === 1 ? '' : 's' ?></span>
                    </div>
                    <?php if (empty($requestVisits)): ?>
                    <div class="bg-white rounded-2xl border border-dashed border-gray-200 px-4 py-10 text-center text-sm text-gray-400">No document-request visits on this date.</div>
                    <?php else: ?>
                    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400">
                                <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Citizen / Tracking</th><th class="px-4 py-3">Document</th><th class="px-4 py-3">Time</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Action</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php renderAppointmentRows($requestVisits, $viewDate); ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>

                <section>
                    <div class="flex items-end justify-between gap-3 mb-3">
                        <div>
                            <h2 class="text-sm font-black text-slate-900">Special service appointments</h2>
                            <p class="text-[11px] text-gray-400">Citizens who booked a special service visit only — not a certificate request.</p>
                        </div>
                        <span class="text-[11px] font-bold text-gray-400"><?= count($standaloneAppointments) ?> appointment<?= count($standaloneAppointments) === 1 ? '' : 's' ?></span>
                    </div>
                    <?php if (empty($standaloneAppointments)): ?>
                    <div class="bg-white rounded-2xl border border-dashed border-gray-200 px-4 py-10 text-center text-sm text-gray-400">No standalone appointments on this date.</div>
                    <?php else: ?>
                    <div class="bg-white rounded-2xl border border-gray-100 overflow-hidden">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 text-[10px] font-bold uppercase text-gray-400">
                                <tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Citizen</th><th class="px-4 py-3">Service</th><th class="px-4 py-3">Time</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Action</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <?php renderAppointmentRows($standaloneAppointments, $viewDate); ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <form id="deleteAppointmentForm" method="POST" action="<?= htmlspecialchars(buildAuthUrl('appointment.php', ['date' => $viewDate])) ?>" class="hidden">
        <?= authFormField() ?>
        <input type="hidden" name="redirect_date" value="<?= htmlspecialchars($viewDate) ?>">
        <input type="hidden" name="delete_appointment" value="1">
        <input type="hidden" name="appointment_id" id="deleteAppointmentId" value="">
    </form>

    <div id="deleteUndoToast" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-50 bg-slate-900 text-white px-5 py-3 rounded-xl shadow-lg flex items-center gap-4 text-sm">
        <span id="deleteUndoMessage">Appointment deleted.</span>
        <button type="button" id="deleteUndoBtn" class="text-amber-300 hover:text-amber-200 font-bold text-xs uppercase tracking-wide">Undo (<span id="deleteUndoCountdown">5</span>s)</button>
    </div>

    <div id="appointmentViewModal" class="fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-black/40" role="dialog" aria-modal="true" aria-labelledby="appointmentViewTitle">
        <div class="bg-white rounded-2xl border border-gray-100 shadow-xl w-full max-w-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-wider text-blue-600 mb-1">Appointment Details</p>
                    <h2 id="appointmentViewTitle" class="text-lg font-black text-slate-900">Citizen Visit</h2>
                    <p id="appointmentViewCode" class="text-xs font-mono font-bold text-blue-600 mt-1"></p>
                </div>
                <button type="button" id="appointmentViewClose" class="text-gray-400 hover:text-slate-700 p-1 rounded-lg hover:bg-gray-50" aria-label="Close">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="px-6 py-5 overflow-y-auto space-y-5 text-sm">
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Citizen Information</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><dt class="text-[11px] text-gray-400">Full Name</dt><dd id="appt-view-name" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Phone</dt><dd id="appt-view-phone" class="font-semibold text-slate-800"></dd></div>
                        <div class="sm:col-span-2"><dt class="text-[11px] text-gray-400">Email</dt><dd id="appt-view-email" class="font-semibold text-slate-800"></dd></div>
                    </dl>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Visit Information</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div><dt class="text-[11px] text-gray-400">Service</dt><dd id="appt-view-service" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Schedule</dt><dd id="appt-view-schedule" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Status</dt><dd id="appt-view-status" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Type</dt><dd id="appt-view-source" class="font-semibold text-slate-800"></dd></div>
                        <div id="appt-view-tracking-wrap" class="hidden"><dt class="text-[11px] text-gray-400">Tracking Code</dt><dd id="appt-view-tracking" class="font-mono font-bold text-blue-600"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Gmail Notifications</dt><dd id="appt-view-notify" class="font-semibold text-slate-800"></dd></div>
                        <div><dt class="text-[11px] text-gray-400">Booked</dt><dd id="appt-view-created" class="font-semibold text-slate-800"></dd></div>
                    </dl>
                </div>
                <div>
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Uploaded IDs</p>
                    <div id="appt-view-id-files" class="flex flex-wrap gap-2"></div>
                </div>
                <div id="appt-view-notes-wrap" class="hidden">
                    <p class="text-[10px] font-bold uppercase text-gray-400 mb-2">Staff Notes</p>
                    <p id="appt-view-notes" class="text-sm text-slate-700 bg-gray-50 rounded-xl p-3 border border-gray-100"></p>
                </div>
            </div>
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end">
                <button type="button" id="appointmentViewCloseFooter" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2.5 rounded-xl text-sm font-bold">Close</button>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        (function () {
            var modal = document.getElementById('appointmentViewModal');
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

            function openAppointmentModal(data) {
                setText('appointmentViewCode', data.appointment_code || '');
                setText('appt-view-name', data.citizen_name);
                setText('appt-view-phone', data.phone);
                setText('appt-view-email', data.email);
                setText('appt-view-service', data.service_type);
                setText('appt-view-schedule', data.schedule);
                setText('appt-view-status', data.status);
                setText('appt-view-source', data.source);
                setText('appt-view-notify', data.notify_email);
                setText('appt-view-created', data.created_at);

                var trackingWrap = document.getElementById('appt-view-tracking-wrap');
                if (trackingWrap) {
                    if (data.tracking_code) {
                        setText('appt-view-tracking', data.tracking_code);
                        trackingWrap.classList.remove('hidden');
                    } else {
                        trackingWrap.classList.add('hidden');
                    }
                }

                var idFiles = document.getElementById('appt-view-id-files');
                if (idFiles) {
                    var html = idLink('Front ID', data.id_front_path) + idLink('Back ID', data.id_back_path);
                    idFiles.innerHTML = html || '<span class="text-xs text-gray-400 italic">No ID files uploaded.</span>';
                }

                var notesWrap = document.getElementById('appt-view-notes-wrap');
                var notesEl = document.getElementById('appt-view-notes');
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

            function closeAppointmentModal() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }

            document.addEventListener('click', function (e) {
                var viewBtn = e.target.closest('.view-appointment-btn');
                if (viewBtn) {
                    var raw = viewBtn.getAttribute('data-appointment');
                    if (!raw) return;
                    try {
                        openAppointmentModal(JSON.parse(raw));
                    } catch (err) {}
                    return;
                }
                if (e.target === modal) closeAppointmentModal();
            });

            document.getElementById('appointmentViewClose')?.addEventListener('click', closeAppointmentModal);
            document.getElementById('appointmentViewCloseFooter')?.addEventListener('click', closeAppointmentModal);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('flex')) closeAppointmentModal();
            });
        })();

        (function () {
            const UNDO_SECONDS = 5;
            const toast = document.getElementById('deleteUndoToast');
            const undoBtn = document.getElementById('deleteUndoBtn');
            const countdownEl = document.getElementById('deleteUndoCountdown');
            const messageEl = document.getElementById('deleteUndoMessage');
            const deleteForm = document.getElementById('deleteAppointmentForm');
            const deleteInput = document.getElementById('deleteAppointmentId');

            let pendingDelete = null;

            function cancelPendingDelete() {
                if (!pendingDelete) return;
                clearInterval(pendingDelete.timer);
                pendingDelete.row.classList.remove('row-pending-delete');
                toast.classList.add('hidden');
                pendingDelete = null;
            }

            function commitDelete() {
                if (!pendingDelete) return;
                deleteInput.value = pendingDelete.appointmentId;
                deleteForm.submit();
            }

            function startDeleteCountdown(btn) {
                if (pendingDelete) cancelPendingDelete();

                const appointmentId = btn.dataset.appointmentId;
                const code = btn.dataset.code;
                const row = document.querySelector('[data-appointment-row="' + appointmentId + '"]');

                row.classList.add('row-pending-delete');
                messageEl.textContent = 'Deleting ' + code + '…';
                countdownEl.textContent = String(UNDO_SECONDS);
                toast.classList.remove('hidden');

                let remaining = UNDO_SECONDS;
                const timer = setInterval(function () {
                    remaining -= 1;
                    countdownEl.textContent = String(remaining);
                    if (remaining <= 0) {
                        clearInterval(timer);
                        commitDelete();
                    }
                }, 1000);

                pendingDelete = { appointmentId, row, timer };
            }

            undoBtn.addEventListener('click', cancelPendingDelete);

            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.delete-appointment-btn');
                if (btn) startDeleteCountdown(btn);
            });
        })();
    </script>
</body>
</html>
