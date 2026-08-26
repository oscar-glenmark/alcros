(function () {
    'use strict';

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
})();
