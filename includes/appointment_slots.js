(function () {
    'use strict';

    var dateInput = document.getElementById('appointmentDateInput');
    var timeInput = document.getElementById('appointmentTimeInput');
    var statusEl = document.getElementById('slotAvailabilityStatus');
    var submitBtn = document.getElementById('bookSubmitBtn') || document.querySelector('[data-appointment-submit]');

    if (!dateInput || !timeInput) {
        return;
    }

    var bookedTimes = [];
    var dateFull = false;
    var officeWeekday = true;

    function normalizeTime(time) {
        if (!time) return '';
        var parts = time.split(':');
        return String(parts[0] || '00').padStart(2, '0') + ':' + String(parts[1] || '00').padStart(2, '0');
    }

    function isBooked(time) {
        var normalized = normalizeTime(time);
        return bookedTimes.some(function (slot) {
            return normalizeTime(slot) === normalized;
        });
    }

    function gmailVerified() {
        var field = document.getElementById('emailVerified');
        return !field || field.value === '1';
    }

    function updateSubmitState() {
        if (!submitBtn) return;

        var conflict = Boolean(timeInput.value) && (isBooked(timeInput.value) || dateFull || !officeWeekday);
        if (submitBtn.id === 'bookSubmitBtn') {
            submitBtn.disabled = conflict || !gmailVerified();
            return;
        }

        submitBtn.disabled = conflict;
    }

    function updateStatus() {
        if (!statusEl) {
            updateSubmitState();
            return;
        }

        var date = dateInput.value;
        var time = timeInput.value;

        if (!date) {
            statusEl.classList.add('hidden');
            updateSubmitState();
            return;
        }

        statusEl.classList.remove('hidden');

        if (!officeWeekday) {
            statusEl.textContent = 'Appointments are available Monday to Friday only.';
            statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
        } else if (dateFull) {
            statusEl.textContent = 'This date is fully booked. Please choose another day.';
            statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
        } else if (time && isBooked(time)) {
            statusEl.textContent = 'This time slot is already booked. Please choose another time.';
            statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
        } else if (time) {
            statusEl.textContent = 'This time slot is available.';
            statusEl.className = 'text-xs mt-2 font-semibold text-green-600';
        } else if (bookedTimes.length) {
            statusEl.textContent = bookedTimes.length + ' time slot(s) already booked on this date.';
            statusEl.className = 'text-xs mt-2 text-gray-500';
        } else {
            statusEl.textContent = 'All office-hour slots are open on this date.';
            statusEl.className = 'text-xs mt-2 text-gray-500';
        }

        updateSubmitState();
    }

    function loadBooked() {
        var date = dateInput.value;
        if (!date) {
            bookedTimes = [];
            dateFull = false;
            officeWeekday = true;
            updateStatus();
            return;
        }

        fetch('api/appointment_availability.php?date=' + encodeURIComponent(date), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.ok) return;
                bookedTimes = data.booked_times || [];
                dateFull = Boolean(data.date_full);
                officeWeekday = data.office_weekday !== false;
                updateStatus();
            })
            .catch(function () {});
    }

    dateInput.addEventListener('change', loadBooked);
    timeInput.addEventListener('change', updateStatus);
    timeInput.addEventListener('input', updateStatus);

    if (dateInput.value) {
        loadBooked();
    } else {
        updateStatus();
    }
})();
