(function () {
    'use strict';

    var cfg = (window.AlcrosPage && AlcrosPage.readConfig('dashboard-schedule-config')) || {};

    var month = cfg.scheduleMonth;
    var selectedDate = cfg.scheduleDate;
    var appointmentDates = cfg.appointmentDates || {};
    var appointmentPage = cfg.appointmentPage || 'appointment.php';

    var gridEl = document.getElementById('dashCalGrid');
    var monthLabelEl = document.getElementById('dashCalMonthLabel');
    var prevBtn = document.getElementById('dashCalPrev');
    var nextBtn = document.getElementById('dashCalNext');
    var listEl = document.getElementById('today-appts-list');
    var emptyEl = document.getElementById('dash-schedule-empty');
    var countEl = document.getElementById('dash-schedule-count');
    var dateLabelEl = document.getElementById('dash-schedule-date-label');
    var openLinkEl = document.getElementById('dash-schedule-open-link');

    if (!cfg.scheduleMonth || !gridEl || !monthLabelEl) return;

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatDateDisplay(iso) {
        if (!iso) return '—';
        var parts = iso.split('-');
        if (parts.length !== 3) return iso;
        return parts[1] + '/' + parts[2] + '/' + parts[0];
    }

    function monthParts(ym) {
        var bits = ym.split('-');
        return { year: parseInt(bits[0], 10), month: parseInt(bits[1], 10) - 1 };
    }

    function shiftMonth(ym, delta) {
        var p = monthParts(ym);
        var d = new Date(p.year, p.month + delta, 1);
        return d.getFullYear() + '-' + pad(d.getMonth() + 1);
    }

    function renderCalendar() {
        var p = monthParts(month);
        var firstDay = new Date(p.year, p.month, 1);
        var daysInMonth = new Date(p.year, p.month + 1, 0).getDate();
        var startOffset = firstDay.getDay();
        var today = new Date();
        var todayIso = today.getFullYear() + '-' + pad(today.getMonth() + 1) + '-' + pad(today.getDate());

        monthLabelEl.textContent = firstDay.toLocaleDateString([], { month: 'long', year: 'numeric' });

        var html = '';
        var cell = 0;

        for (var i = 0; i < startOffset; i++) {
            html += '<span class="dash-cal-cell dash-cal-cell--blank" aria-hidden="true"></span>';
            cell++;
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var iso = p.year + '-' + pad(p.month + 1) + '-' + pad(day);
            var hasAppt = !!appointmentDates[iso];
            var count = appointmentDates[iso] || 0;
            var classes = ['dash-cal-cell', 'dash-cal-day'];
            if (iso === selectedDate) classes.push('is-selected');
            if (iso === todayIso) classes.push('is-today');
            if (hasAppt) classes.push('has-appointments');

            html += '<button type="button" class="' + classes.join(' ') + '" data-date="' + iso + '" aria-label="' +
                escapeHtml(formatDateDisplay(iso)) + (hasAppt ? ', ' + count + ' appointment(s)' : '') + '">' +
                '<span class="dash-cal-day-num">' + day + '</span>' +
                (hasAppt ? '<span class="dash-cal-dot" aria-hidden="true"></span>' : '') +
                '</button>';
            cell++;
        }

        while (cell % 7 !== 0) {
            html += '<span class="dash-cal-cell dash-cal-cell--blank" aria-hidden="true"></span>';
            cell++;
        }

        gridEl.innerHTML = html;
    }

    function renderAppointments(rows) {
        rows = rows || [];
        if (countEl) countEl.textContent = String(rows.length);
        if (dateLabelEl) dateLabelEl.textContent = formatDateDisplay(selectedDate);
        if (openLinkEl) {
            var base = appointmentPage.split('?')[0];
            var qs = appointmentPage.indexOf('?') >= 0 ? appointmentPage.substring(appointmentPage.indexOf('?') + 1) : '';
            var params = new URLSearchParams(qs);
            params.set('date', selectedDate);
            openLinkEl.href = base + '?' + params.toString();
        }

        if (!listEl || !emptyEl) return;

        if (!rows.length) {
            listEl.innerHTML = '';
            listEl.classList.add('hidden');
            emptyEl.classList.remove('hidden');
            return;
        }

        emptyEl.classList.add('hidden');
        listEl.classList.remove('hidden');
        listEl.innerHTML = rows.map(function (a) {
            var d = new Date('1970-01-01T' + a.appointment_time);
            var h = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            var ampm = d.toLocaleTimeString([], { hour: 'numeric', hour12: true }).split(' ')[1] || '';
            var status = a.status ? '<span class="text-[9px] font-bold uppercase text-gray-400 shrink-0">' + escapeHtml(a.status) + '</span>' : '';
            return '<div class="px-5 py-3 flex items-center gap-3">' +
                '<div class="w-10 h-10 rounded-lg bg-blue-50 text-blue-600 flex flex-col items-center justify-center shrink-0 leading-none">' +
                '<span class="text-[9px] font-bold">' + escapeHtml(h.replace(/ [AP]M/i, '')) + '</span>' +
                '<span class="text-[8px] uppercase">' + escapeHtml(ampm) + '</span></div>' +
                '<div class="min-w-0 flex-1"><p class="text-sm font-semibold text-slate-800 truncate">' + escapeHtml(a.citizen_name) + '</p>' +
                '<p class="text-[10px] text-gray-400 truncate">' + escapeHtml(a.service_type) + '</p></div>' + status + '</div>';
        }).join('');
    }

    function loadSchedule() {
        var url = window.AlcrosPoll && AlcrosPoll.buildUrl
            ? AlcrosPoll.buildUrl('api/dashboard_stats.php', { month: month, date: selectedDate })
            : 'api/dashboard_stats.php?month=' + encodeURIComponent(month) + '&date=' + encodeURIComponent(selectedDate);
        fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.schedule) return;
                appointmentDates = data.schedule.dates || {};
                selectedDate = data.schedule.schedule_date || selectedDate;
                month = data.schedule.month || month;
                renderCalendar();
                renderAppointments(data.schedule.appointments || []);
            })
            .catch(function () {});
    }

    gridEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-date]');
        if (!btn) return;
        selectedDate = btn.getAttribute('data-date');
        loadSchedule();
    });

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            month = shiftMonth(month, -1);
            loadSchedule();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            month = shiftMonth(month, 1);
            loadSchedule();
        });
    }

    window.AlcrosDashboardSchedule = {
        applyPoll: function (schedule) {
            if (!schedule) return;
            appointmentDates = schedule.dates || appointmentDates;
            selectedDate = schedule.schedule_date || selectedDate;
            month = schedule.month || month;
            renderCalendar();
            renderAppointments(schedule.appointments || []);
        },
        getState: function () {
            return { month: month, date: selectedDate };
        }
    };

    renderCalendar();
})();
