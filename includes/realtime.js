(function () {
    'use strict';

    if (!window.AlcrosPoll) return;

    var purposeLabels = { walk_in: 'Walk-in', appointment: 'Appointment', document_claim: 'Claim' };

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function authInputHtml() {
        var token = AlcrosPoll.authToken();
        return token ? '<input type="hidden" name="alcros_auth" value="' + token.replace(/"/g, '&quot;') + '">' : '';
    }

    function renderQueueTableColumn(purpose, cfg, group) {
        var serving = group && group.serving ? group.serving : null;
        var waiting = group && group.waiting ? group.waiting : [];
        var waitCount = waiting.length;
        var hasServing = !!serving;
        var canNext = hasServing || waitCount > 0;
        var btnLabel = !canNext ? 'No one waiting' : (hasServing && waitCount > 0 ? 'Finish & call next' : (hasServing ? 'Finish (no one waiting)' : 'Call next'));

        var section = document.querySelector('section[data-purpose="' + purpose + '"]');
        if (!section) return;

        var slot = section.querySelector('.queue-serving-slot');
        if (slot) {
            if (serving) {
                var name = serving.citizen_name ? '<p class="text-sm text-slate-500 mt-2">' + escapeHtml(serving.citizen_name) + '</p>' : '';
                slot.innerHTML = '<p class="queue-serving-number text-5xl font-black text-slate-900 leading-none">' + escapeHtml(serving.ticket_number) + '</p>' + name;
            } else {
                slot.innerHTML = '<p class="queue-serving-number text-4xl font-black text-slate-200">—</p>';
            }
        }

        var btn = section.querySelector('.queue-next-btn');
        if (btn) {
            btn.textContent = btnLabel;
            btn.disabled = !canNext;
        }

        var badge = section.querySelector('.queue-wait-badge');
        if (badge) {
            if (waitCount === 0) {
                badge.textContent = 'No one in line';
            } else {
                badge.innerHTML = '<span class="font-bold text-slate-700">' + waitCount + '</span> waiting — next: <span class="queue-next-preview font-mono font-bold">' + escapeHtml(waiting[0].ticket_number) + '</span>';
            }
        }

        var list = section.querySelector('.queue-waiting-list');
        if (list) {
            if (waitCount > 1) {
                var rest = waiting.slice(1).map(function (t) { return t.ticket_number; }).join(', ');
                list.textContent = 'Then: ' + rest;
                list.classList.remove('hidden');
            } else {
                list.textContent = '';
                list.classList.add('hidden');
            }
        }

        var callAgainBtn = section.querySelector('.queue-call-again-btn');
        var callAgainSlot = section.querySelector('.queue-call-again-slot');
        var callAgainForm = callAgainBtn ? callAgainBtn.closest('form') : null;
        if (hasServing && !callAgainForm) {
            var callForm = document.createElement('form');
            callForm.method = 'POST';
            callForm.className = 'mb-4 queue-call-again-form';
            callForm.innerHTML = authInputHtml() +
                '<input type="hidden" name="purpose" value="' + purpose + '">' +
                '<input type="hidden" name="action" value="call_again">' +
                '<button type="submit" class="queue-call-again-btn w-full py-2.5 rounded-xl border-2 border-slate-200 text-slate-600 font-bold text-xs uppercase tracking-wide hover:bg-slate-50 flex items-center justify-center gap-2">' +
                '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon><path d="M15.54 8.46a5 5 0 0 1 0 7.07"></path></svg> Call again</button>';
            var nextForm = section.querySelector('form input[name="action"][value="next"]');
            if (nextForm && nextForm.closest('form')) {
                nextForm.closest('form').insertAdjacentElement('afterend', callForm);
            }
        } else if (!hasServing && callAgainForm) {
            callAgainForm.remove();
        }

        var skipForm = section.querySelector('form input[name="action"][value="skip"]');
        var skipParent = skipForm ? skipForm.closest('form') : null;
        if (hasServing && !skipParent) {
            var actions = document.createElement('form');
            actions.method = 'POST';
            actions.className = 'mt-4 pt-4 border-t border-slate-100 queue-skip-form';
            actions.innerHTML = authInputHtml() +
                '<input type="hidden" name="purpose" value="' + purpose + '">' +
                '<input type="hidden" name="action" value="skip">' +
                '<button type="submit" class="text-[11px] text-slate-400 hover:text-red-500 font-medium" onclick="return confirm(\'Mark current ticket as no-show?\')">No-show — skip current</button>';
            section.querySelector('.p-5').appendChild(actions);
        } else if (!hasServing && skipParent) {
            skipParent.remove();
        }
    }

    function renderQueueTables(grouped, tables) {
        if (!grouped || !tables) return;
        var totalWaiting = 0;
        Object.keys(tables).forEach(function (purpose) {
            var g = grouped[purpose] || { serving: null, waiting: [] };
            totalWaiting += (g.waiting || []).length;
            renderQueueTableColumn(purpose, tables[purpose], g);
        });
        AlcrosPoll.setText('stat-queue-waiting', totalWaiting);
    }

    function renderQueueTickets(tickets) {
        var container = document.getElementById('queue-tickets');
        if (!container) return;

        if (!tickets || !tickets.length) {
            container.innerHTML = '<p class="text-gray-400 text-sm italic">No active tickets today. Citizens can get tickets at the kiosk.</p>';
            return;
        }

        container.innerHTML = tickets.map(function (t) {
            var border = t.status === 'serving' ? 'border-green-400' : 'border-yellow-400';
            var label = purposeLabels[t.purpose] || t.purpose;
            var windowBadge = t.window_number ? '<span class="bg-blue-50 text-blue-600 text-[10px] font-bold px-3 py-1 rounded">WINDOW ' + t.window_number + '</span>' : '';
            var actions = t.status === 'waiting'
                ? '<form method="POST">' + authInputHtml() + '<input type="hidden" name="ticket_id" value="' + t.id + '"><button type="submit" name="action" value="serve" class="w-full bg-blue-600 text-white py-3 rounded-2xl font-bold flex items-center justify-center gap-2"><i data-lucide="play" class="w-4 h-4"></i> SERVE</button></form>'
                : '<form method="POST" class="flex gap-2">' + authInputHtml() + '<input type="hidden" name="ticket_id" value="' + t.id + '"><button type="submit" name="action" value="complete" class="flex-1 bg-green-600 text-white py-3 rounded-2xl font-bold text-xs">DONE</button><button type="submit" name="action" value="skip" class="bg-red-50 text-red-500 py-3 px-4 rounded-2xl"><i data-lucide="x-circle" class="w-5 h-5"></i></button></form>';

            return '<div class="w-72 bg-white rounded-[40px] border-2 ' + border + ' shadow-xl p-8">' +
                '<span class="bg-purple-100 text-purple-600 text-[10px] font-black px-2 py-0.5 rounded-full uppercase">' + label + '</span>' +
                '<div class="text-center my-6"><h2 class="text-5xl font-black text-slate-900 mb-1">' + t.ticket_number + '</h2>' + windowBadge + '</div>' +
                actions + '</div>';
        }).join('');

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function initLiveQueue() {
        AlcrosPoll.pollJson('api/queue_status.php', {}, 12000, function (data) {
            if (document.getElementById('queue-tables') && data.grouped && data.tables) {
                renderQueueTables(data.grouped, data.tables);
            } else {
                AlcrosPoll.setText('stat-queue-waiting', data.stats.waiting);
                renderQueueTickets(data.tickets);
            }
            AlcrosPoll.markLiveIndicator();
        });
    }

    function renderDisplayTables(tables) {
        if (!tables) return;
        Object.keys(tables).forEach(function (purpose) {
            var slot = document.querySelector('.display-table-slot[data-purpose="' + purpose + '"]');
            if (!slot) return;
            var info = tables[purpose];
            var numEl = slot.querySelector('.display-table-number');
            var waitEl = slot.querySelector('.display-table-wait');
            if (numEl) {
                numEl.textContent = info.serving || '—';
                numEl.className = 'display-table-number ' + (info.serving ? 'text-3xl font-black text-white' : 'text-lg font-bold text-gray-600');
            }
            if (waitEl) waitEl.textContent = (info.waiting ? info.waiting.length : 0) + ' waiting';
        });
    }

    function initQueueDisplay() {
        var voiceReady = false;

        AlcrosPoll.pollJson('api/queue_status.php', { mode: 'display' }, 8000, function (data) {
            var servingEl = document.getElementById('display-serving');
            var waitingEl = document.getElementById('display-waiting');
            if (!servingEl || !waitingEl) return;

            var serving = data.display && data.display.serving ? data.display.serving : null;

            if (serving) {
                var purpose = purposeLabels[serving.purpose] || serving.purpose;
                var tableNum = serving.window_number || 1;
                servingEl.innerHTML = '<p class="text-8xl font-black text-white mb-2">' + serving.ticket_number + '</p>' +
                    '<p class="text-blue-400 text-lg font-bold uppercase">Table ' + tableNum + '</p>' +
                    '<p class="text-gray-500 text-sm mt-2">' + purpose + '</p>';
            } else {
                servingEl.innerHTML = '<i data-lucide="users" class="w-16 h-16 text-gray-600 mb-4"></i><p class="text-xs font-bold text-gray-600 uppercase tracking-widest">No Active Tickets</p>';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }

            if (window.AlcrosVoice) {
                if (!voiceReady) {
                    if (serving) {
                        AlcrosVoice.syncBaseline(serving);
                    }
                    voiceReady = true;
                } else if (serving) {
                    AlcrosVoice.announceIfNew(serving);
                } else {
                    AlcrosVoice.clearBaseline();
                }
            }

            if (data.display && data.display.tables) {
                renderDisplayTables(data.display.tables);
            }

            if (data.display && data.display.waiting && data.display.waiting.length) {
                waitingEl.innerHTML = data.display.waiting.map(function (n) {
                    return '<p class="text-2xl font-black text-gray-400">' + n + '</p>';
                }).join('');
            } else {
                waitingEl.innerHTML = '<p class="text-[10px] italic text-gray-700 text-center pt-8">No one in line.</p>';
            }
            AlcrosPoll.markLiveIndicator();
        }, null, { pollInBackground: true });
    }

    function formatTimeAgo(datetime) {
        var ts = new Date(datetime.replace(' ', 'T'));
        if (isNaN(ts)) return datetime;
        return ts.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function initDashboard() {
        var isAdmin = document.body.dataset.admin === '1';
        AlcrosPoll.pollJson('api/dashboard_stats.php', {}, 45000, function (data) {
            var s = data.stats;
            AlcrosPoll.setText('stat-pending', s.pending_count);
            AlcrosPoll.setText('stat-queue', s.queue_count);
            AlcrosPoll.setText('stat-appts', s.today_appts);
            AlcrosPoll.setText('stat-pipeline', s.pipeline_count);
            AlcrosPoll.setText('stat-ready', s.ready_count);
            AlcrosPoll.setText('header-queue-count', s.queue_count);
            AlcrosPoll.setText('header-pending-count', s.pending_count);
            AlcrosPoll.setText('cmd-queue-count', s.queue_count);
            AlcrosPoll.setText('cmd-appts-count', s.today_appts);

            var recentEl = document.getElementById('recent-requests-list');
            if (recentEl && data.recent_requests) {
                var labels = data.document_labels || {};
                recentEl.innerHTML = data.recent_requests.length ? data.recent_requests.map(function (r) {
                    var date = (r.submitted_at || '').split(' ')[0];
                    return '<div class="px-5 py-3.5 flex items-center justify-between gap-4 hover:bg-gray-50/50"><div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-800 truncate">' + r.citizen_name + '</p><p class="text-[11px] text-gray-400 mt-0.5">' + (labels[r.document_type] || r.document_type) + ' · <span class="font-mono text-blue-600">' + r.tracking_code + '</span>' + (date ? ' · ' + formatDateDisplay(date) : '') + '</p></div><span class="px-2 py-0.5 rounded text-[9px] font-bold uppercase bg-gray-100 text-gray-600">' + r.status + '</span></div>';
                }).join('') : '';
            }

            var actEl = document.getElementById('activity-feed-list');
            if (actEl && data.activities) {
                actEl.innerHTML = data.activities.length ? data.activities.map(function (a) {
                    var details = a.details ? '<p class="text-[10px] text-gray-500 mt-0.5 line-clamp-2">' + a.details + '</p>' : '';
                    var who = isAdmin ? (a.staff_id || 'System') + ' · ' : '';
                    return '<div class="flex items-start gap-3 p-3 rounded-xl bg-gray-50/80 border border-gray-100"><div class="bg-white p-2 rounded-lg text-blue-600 border border-gray-100 shrink-0"><i data-lucide="activity" class="w-4 h-4"></i></div><div class="min-w-0"><p class="text-xs font-bold text-slate-800">' + a.action + '</p>' + details + '<p class="text-[10px] text-gray-400 mt-1">' + who + formatTimeAgo(a.created_at) + '</p></div></div>';
                }).join('') : '<div class="p-10 text-center text-gray-400 text-xs col-span-full">No activity recorded yet.</div>';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }

            var apptEl = document.getElementById('today-appts-list');
            if (apptEl && data.today_appts) {
                apptEl.innerHTML = data.today_appts.length ? data.today_appts.map(function (a) {
                    var d = new Date('1970-01-01T' + a.appointment_time);
                    var h = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
                    var ampm = d.toLocaleTimeString([], { hour: 'numeric', hour12: true }).split(' ')[1] || '';
                    var status = a.status ? '<span class="text-[9px] font-bold uppercase text-gray-400 shrink-0">' + a.status + '</span>' : '';
                    return '<div class="px-5 py-3 flex items-center gap-3"><div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex flex-col items-center justify-center shrink-0 leading-none"><span class="text-[9px] font-bold">' + h.replace(/ [AP]M/i, '') + '</span><span class="text-[8px] uppercase">' + ampm + '</span></div><div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-800 truncate">' + a.citizen_name + '</p><p class="text-[10px] text-gray-400 truncate">' + a.service_type + '</p></div>' + status + '</div>';
                }).join('') : '';
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }

            AlcrosPoll.markLiveIndicator();
        });
    }

        function initTrack() {
        var code = document.body.dataset.trackingCode;
        if (!code) return;

        function messageClass(status) {
            if (status === 'ready' || status === 'completed') return 'bg-green-50 text-green-800 border border-green-100';
            if (status === 'rejected' || status === 'cancelled' || status === 'no_show') return 'bg-red-50 text-red-800 border border-red-100';
            return 'bg-blue-50 text-blue-800 border border-blue-100';
        }

        function applyTrackUpdate(data) {
            if (!data.found) return;

            var badgeEl = document.getElementById('track-status-badge');
            if (badgeEl) badgeEl.innerHTML = data.status_html;

            var msgEl = document.getElementById('track-status-message');
            if (msgEl && data.status_message) {
                msgEl.textContent = data.status_message;
                var status = data.request ? data.request.status : (data.appointment ? data.appointment.status : '');
                msgEl.className = 'rounded-xl p-4 mb-5 text-sm leading-relaxed ' + messageClass(status);
            }

            var steps = document.querySelectorAll('[data-track-step]');
            steps.forEach(function (el, i) {
                var dot = el.querySelector('.track-step-dot');
                var label = el.querySelector('.track-step-label');
                var active = data.current_idx >= 0 && i <= data.current_idx;
                if (dot) dot.className = 'track-step-dot w-3 h-3 rounded-full mx-auto mb-1 ' + (active ? 'bg-blue-600' : 'bg-gray-200');
                if (label) label.classList.toggle('text-blue-600', active);
            });

            var updatedEl = document.getElementById('track-updated-at');
            if (updatedEl) {
                updatedEl.textContent = 'Status updates automatically · Last checked ' +
                    new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            }

            AlcrosPoll.markLiveIndicator();
        }

        var mode = document.body.dataset.realtime;
        var endpoint = mode === 'track-appointment' ? 'api/appointment_status.php' : 'api/request_status.php';
        AlcrosPoll.pollJson(endpoint, { code: code }, 30000, applyTrackUpdate);
    }

    function formatDateDisplay(datetime) {
        var p = (datetime || '').split(' ')[0].split('-');
        if (p.length !== 3) return datetime;
        return p[1] + '/' + p[2] + '/' + p[0];
    }

    function initManageRequests() {
        // Keep server-rendered Save/Advance/Delete forms. Replacing the table
        // here previously dropped auth fields and the status dropdown.
        return;
    }

    function initAppointments() {
        var viewDate = document.body.dataset.appointmentDate;
        if (!viewDate) return;

        AlcrosPoll.pollJson('api/appointments.php', { date: viewDate }, 15000, function (data) {
            var appts = data.appointments || [];
            var domIds = new Set();
            document.querySelectorAll('[data-appointment-row]').forEach(function (row) {
                domIds.add(String(row.getAttribute('data-appointment-row')));
            });

            var hasNew = appts.some(function (ap) {
                return !domIds.has(String(ap.id));
            });

            if (!hasNew) {
                AlcrosPoll.markLiveIndicator();
                return;
            }

            var active = document.activeElement;
            var editing = active && active.closest('[data-appointment-row]');
            if (!editing) {
                window.location.reload();
                return;
            }

            AlcrosPoll.markLiveIndicator();
        });
    }

    function initHeaderStats() {
        if (!document.getElementById('header-queue-count')) return;
        AlcrosPoll.pollJson('api/dashboard_stats.php', {}, 60000, function (data) {
            AlcrosPoll.setText('header-queue-count', data.stats.queue_count);
            AlcrosPoll.setText('header-pending-count', data.stats.pending_count);
            AlcrosPoll.markLiveIndicator();
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initHeaderStats();
        var mode = document.body.dataset.realtime;
        if (mode === 'queue') initLiveQueue();
        if (mode === 'queue-display') initQueueDisplay();
        if (mode === 'dashboard') initDashboard();
        if (mode === 'track' || mode === 'track-appointment') initTrack();
        if (mode === 'requests') initManageRequests();
        if (mode === 'appointments') initAppointments();
    });
})();
