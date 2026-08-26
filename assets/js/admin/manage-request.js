(function () {
    'use strict';

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
