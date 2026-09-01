(function () {
    'use strict';

    var root = document.getElementById('track-floating-root');
    if (!root) return;

    var backdrop = document.getElementById('track-floating-backdrop');
    var panel = document.getElementById('track-floating-panel');
    var form = document.getElementById('track-floating-form');
    var input = document.getElementById('track-floating-input');
    var submitBtn = document.getElementById('track-floating-submit');
    var closeBtn = document.getElementById('track-floating-close');
    var loadingEl = document.getElementById('track-floating-loading');
    var errorEl = document.getElementById('track-floating-error');
    var resultEl = document.getElementById('track-floating-result');
    var pollTimer = null;
    var activeCode = '';

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function isAppointmentCode(code) {
        return /^APT-/i.test(code);
    }

    function formatDateDisplay(value) {
        if (!value) return '—';
        var raw = String(value).substring(0, 10);
        var parts = raw.split('-');
        if (parts.length !== 3) return raw;
        return parts[1] + '/' + parts[2] + '/' + parts[0];
    }

    function formatDateTimeDisplay(value) {
        if (!value) return '—';
        var d = new Date(String(value).replace(' ', 'T'));
        if (isNaN(d.getTime())) return value;
        return d.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' }) +
            ' · ' + d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function messageClass(status) {
        if (status === 'verified' || status === 'ready' || status === 'completed') {
            return 'bg-emerald-50 text-emerald-800 border border-emerald-100';
        }
        if (status === 'rejected' || status === 'cancelled' || status === 'no_show') {
            return 'bg-red-50 text-red-800 border border-red-100';
        }
        return 'bg-blue-50 text-blue-800 border border-blue-100';
    }

    function visitStatusLabel(data, entity) {
        if (data.appointment_confirmed || entity.appointment_confirmed) {
            return 'Confirmed';
        }
        if (data.appointment_status === 'scheduled') {
            return 'Awaiting confirmation';
        }
        if (entity.status === 'verified' || entity.status === 'ready') {
            return 'Confirmed';
        }
        return '';
    }

    function showLoading(show) {
        loadingEl.classList.toggle('hidden', !show);
        if (show) {
            errorEl.classList.add('hidden');
            resultEl.classList.add('hidden');
        }
        submitBtn.disabled = show;
    }

    function showError(msg) {
        errorEl.textContent = msg;
        errorEl.classList.remove('hidden');
        resultEl.classList.add('hidden');
        loadingEl.classList.add('hidden');
    }

    function renderProgress(stepLabels, currentIdx, hidden) {
        if (hidden || !stepLabels || !stepLabels.length) return '';
        var html = '<div class="mb-4"><p class="text-[10px] font-bold text-slate-400 uppercase mb-3">Progress</p><div class="flex justify-between gap-1 text-[9px] font-bold uppercase text-slate-400">';
        stepLabels.forEach(function (label, i) {
            var active = currentIdx >= 0 && i <= currentIdx;
            html += '<div class="text-center flex-1 min-w-0" data-track-step>' +
                '<div class="track-step-dot w-3 h-3 rounded-full mx-auto mb-1 ' + (active ? 'bg-blue-600' : 'bg-slate-200') + '"></div>' +
                '<span class="track-step-label block leading-tight ' + (active ? 'text-blue-600' : '') + '">' + escapeHtml(label) + '</span></div>';
        });
        return html + '</div></div>';
    }

    function renderResult(data, isAppointment) {
        var entity = isAppointment ? data.appointment : data.request;
        if (!entity) return;

        var code = isAppointment ? entity.appointment_code : entity.tracking_code;
        var status = entity.status;
        var hideProgress = isAppointment
            ? (status === 'cancelled' || status === 'no_show')
            : (status === 'rejected');

        var details = isAppointment
            ? '<p><span class="font-bold text-slate-500">Name:</span> ' + escapeHtml(entity.citizen_name) + '</p>' +
              '<p><span class="font-bold text-slate-500">Service:</span> ' + escapeHtml(data.service || entity.service_type) + '</p>' +
              '<p><span class="font-bold text-slate-500">Visit date:</span> ' + escapeHtml(formatDateTimeDisplay(entity.appointment_date + ' ' + entity.appointment_time)) + '</p>' +
              '<p><span class="font-bold text-slate-500">Booked:</span> ' + escapeHtml(formatDateDisplay(entity.created_at)) + '</p>'
            : (function () {
                var visitLabel = visitStatusLabel(data, entity);
                return '<p><span class="font-bold text-slate-500">Name:</span> ' + escapeHtml(entity.citizen_name) + '</p>' +
                    '<p><span class="font-bold text-slate-500">Document:</span> ' + escapeHtml(data.document || entity.document_type) + '</p>' +
                    '<p><span class="font-bold text-slate-500">Submitted:</span> ' + escapeHtml(formatDateDisplay(entity.submitted_at)) + '</p>' +
                    (entity.appointment_date ? '<p><span class="font-bold text-slate-500">Pickup visit:</span> ' + escapeHtml(formatDateTimeDisplay(entity.appointment_date + ' ' + (entity.appointment_time || ''))) + '</p>' : '') +
                    (visitLabel ? '<p><span class="font-bold text-slate-500">Visit status:</span> ' + escapeHtml(visitLabel) + '</p>' : '');
            }());

        var codeLabel = isAppointment ? 'Appointment Code' : 'Tracking Code';
        var footerNote = hideProgress
            ? '<p class="text-red-600 text-sm font-semibold mb-4">Please contact the registry office for assistance.</p>'
            : '';

        resultEl.innerHTML =
            '<div class="rounded-xl border border-slate-100 bg-slate-50/50 p-4">' +
            '<div class="flex justify-between items-start gap-3 mb-4">' +
            '<div><p class="text-[10px] font-bold text-slate-400 uppercase">' + codeLabel + '</p>' +
            '<p class="text-xl font-black text-blue-600 tracking-widest break-all">' + escapeHtml(code) + '</p></div>' +
            '<div id="track-status-badge">' + (data.status_html || '') + '</div></div>' +
            '<div id="track-status-message" class="rounded-xl p-4 mb-4 text-sm leading-relaxed ' + messageClass(status) + '">' +
            escapeHtml(data.status_message || '') + '</div>' +
            '<div class="space-y-2 text-sm mb-4 text-slate-700">' + details + '</div>' +
            footerNote +
            renderProgress(data.step_labels, data.current_idx, hideProgress) +
            '<p id="track-updated-at" class="text-[10px] text-slate-400 text-center">Status updates automatically · Last checked ' +
            new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) + '</p>' +
            '</div>' +
            '<p class="mt-4 text-[11px] text-slate-400 text-center">Office hours: ' + escapeHtml(root.dataset.officeHours || '') +
            (root.dataset.officePhone ? ' · Call ' + escapeHtml(root.dataset.officePhone) : '') + '</p>';

        resultEl.classList.remove('hidden');
        errorEl.classList.add('hidden');
    }

    function applyPollUpdate(data, isAppointment) {
        if (!data.found) return;
        renderResult(data, isAppointment);
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPolling(code, isAppointment) {
        stopPolling();
        var endpoint = isAppointment ? 'api/appointment_status.php' : 'api/request_status.php';

        pollTimer = setInterval(function () {
            if (root.classList.contains('hidden')) return;
            fetch(endpoint + '?code=' + encodeURIComponent(code), { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.found) applyPollUpdate(data, isAppointment);
                })
                .catch(function () {});
        }, 30000);
    }

    function lookup(code) {
        code = String(code || '').trim().toUpperCase();
        if (code === '') {
            showError('Please enter your tracking code.');
            input.focus();
            return;
        }

        activeCode = code;
        stopPolling();
        showLoading(true);

        var isAppointment = isAppointmentCode(code);
        var endpoint = isAppointment ? 'api/appointment_status.php' : 'api/request_status.php';

        fetch(endpoint + '?code=' + encodeURIComponent(code), { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                showLoading(false);
                if (!data || data.ok === false) {
                    showError((data && data.error) || 'Unable to look up that code. Please try again.');
                    return;
                }
                if (!data.found) {
                    showError(isAppointment
                        ? 'No appointment found with that code. Please check and try again.'
                        : 'No request found with that code. Use ALR- for document requests or APT- for appointments.');
                    return;
                }
                renderResult(data, isAppointment);
                startPolling(code, isAppointment);

                if (window.history && window.history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('track', code);
                    url.searchParams.delete('code');
                    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
                }
            })
            .catch(function () {
                showLoading(false);
                showError('Connection error. Please check your internet and try again.');
            });
    }

    function openPanel(code) {
        root.classList.remove('hidden');
        root.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        if (code) {
            input.value = code;
            lookup(code);
        } else {
            input.value = '';
            errorEl.classList.add('hidden');
            resultEl.classList.add('hidden');
            loadingEl.classList.add('hidden');
            setTimeout(function () { input.focus(); }, 100);
        }
    }

    function closePanel() {
        root.classList.add('hidden');
        root.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        stopPolling();
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('track');
            url.searchParams.delete('code');
            var qs = url.searchParams.toString();
            window.history.replaceState({}, '', url.pathname + (qs ? '?' + qs : '') + url.hash);
        }
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        lookup(input.value);
    });

    closeBtn.addEventListener('click', closePanel);
    backdrop.addEventListener('click', closePanel);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !root.classList.contains('hidden')) closePanel();
    });

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-open-track]');
        if (!trigger) return;
        e.preventDefault();
        var code = trigger.getAttribute('data-track-code') || '';
        openPanel(code);
    });

    window.AlcrosTrack = { open: openPanel, close: closePanel, lookup: lookup };

    var initial = root.dataset.initialCode || '';
    if (initial) {
        openPanel(initial);
    }
})();
