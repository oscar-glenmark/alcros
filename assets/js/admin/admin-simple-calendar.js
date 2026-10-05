(function () {
    'use strict';

    var root = document.getElementById('dashAdminCalendar');
    var gridEl = document.getElementById('dashAdminCalGrid');
    var monthLabelEl = document.getElementById('dashAdminCalMonthLabel');
    var prevBtn = document.getElementById('dashAdminCalPrev');
    var nextBtn = document.getElementById('dashAdminCalNext');

    if (!root || !gridEl || !monthLabelEl) {
        return;
    }

    var todayIso = root.getAttribute('data-today') || '';
    if (!/^\d{4}-\d{2}-\d{2}$/.test(todayIso)) {
        var now = new Date();
        todayIso = now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }

    var month = todayIso.slice(0, 7);
    var selectedDate = todayIso;

    function pad(n) {
        return n < 10 ? '0' + n : String(n);
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

        monthLabelEl.textContent = firstDay.toLocaleDateString([], { month: 'long', year: 'numeric' });

        var html = '';
        var cell = 0;

        for (var i = 0; i < startOffset; i++) {
            html += '<span class="dash-cal-cell dash-cal-cell--blank" aria-hidden="true"></span>';
            cell++;
        }

        for (var day = 1; day <= daysInMonth; day++) {
            var iso = p.year + '-' + pad(p.month + 1) + '-' + pad(day);
            var classes = ['dash-cal-cell', 'dash-cal-day'];
            if (iso === selectedDate) {
                classes.push('is-selected');
            }
            if (iso === todayIso) {
                classes.push('is-today');
            }

            html += '<button type="button" class="' + classes.join(' ') + '" data-date="' + iso + '">' +
                '<span class="dash-cal-day-num">' + day + '</span>' +
                '</button>';
            cell++;
        }

        while (cell % 7 !== 0) {
            html += '<span class="dash-cal-cell dash-cal-cell--blank" aria-hidden="true"></span>';
            cell++;
        }

        gridEl.innerHTML = html;
    }

    gridEl.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-date]');
        if (!btn) {
            return;
        }
        selectedDate = btn.getAttribute('data-date');
        renderCalendar();
    });

    if (prevBtn) {
        prevBtn.addEventListener('click', function () {
            month = shiftMonth(month, -1);
            renderCalendar();
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', function () {
            month = shiftMonth(month, 1);
            renderCalendar();
        });
    }

    renderCalendar();
})();
