(function () {
    'use strict';

    var dateInput = document.getElementById('appointmentDateInput');
    var timeInput = document.getElementById('appointmentTimeInput');
    var statusEl = document.getElementById('slotAvailabilityStatus');
    var submitBtn = document.getElementById('bookSubmitBtn') || document.querySelector('[data-appointment-submit]');
    var scheduleForm = document.getElementById('requestScheduleForm') || document.getElementById('bookAppointmentForm');

    if (!dateInput || !timeInput) {
        return;
    }

    var bookingType = 'standalone';
    if (scheduleForm && scheduleForm.dataset.slotType) {
        bookingType = scheduleForm.dataset.slotType;
    } else if (document.body.dataset.slotType) {
        bookingType = document.body.dataset.slotType;
    }

    var selectedTime = timeInput.value || '';
    var availability = null;

    function gmailVerified() {
        var field = document.getElementById('emailVerified');
        return !field || field.value === '1';
    }

    function notifyFormChange() {
        if (scheduleForm) {
            scheduleForm.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    function blockMessage(reason) {
        if (reason === 'weekend') {
            return 'Visits are available Monday to Friday only. Please choose a weekday.';
        }
        if (reason === 'holiday') {
            return 'This date is a non-working holiday. Please choose another day.';
        }
        if (reason === 'full') {
            return 'This date is fully booked. Please choose another day.';
        }
        if (reason === 'past') {
            return 'Please choose a future date.';
        }
        return 'Please choose another date.';
    }

    function renderTimeOptions(data) {
        var previous = timeInput.value || selectedTime;
        timeInput.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = true;
        placeholder.textContent = 'Select a time slot';
        timeInput.appendChild(placeholder);

        if (!data || !data.bookable) {
            timeInput.disabled = true;
            return;
        }

        timeInput.disabled = false;
        var groups = [
            ['Morning (8:00 AM – 12:00 NN)', data.morning_slots || []],
            ['Afternoon (1:00 PM – 5:00 PM)', data.afternoon_slots || []]
        ];

        var hasAvailable = false;
        groups.forEach(function (group) {
            var label = group[0];
            var slots = group[1];
            if (!slots.length) return;

            var optgroup = document.createElement('optgroup');
            optgroup.label = label;
            slots.forEach(function (slot) {
                var option = document.createElement('option');
                option.value = slot.value;
                option.textContent = slot.available ? slot.label : slot.label + ' — Booked';
                option.disabled = !slot.available;
                if (slot.available) {
                    hasAvailable = true;
                }
                if (slot.value === previous && slot.available) {
                    option.selected = true;
                    placeholder.selected = false;
                }
                optgroup.appendChild(option);
            });
            timeInput.appendChild(optgroup);
        });

        if (!hasAvailable) {
            placeholder.textContent = 'No open slots on this date';
            timeInput.disabled = true;
        }
    }

    function updateSubmitState() {
        if (!submitBtn) return;

        var blocked = !dateInput.value
            || !timeInput.value
            || timeInput.disabled
            || !(availability && availability.bookable);

        if (submitBtn.id === 'bookSubmitBtn') {
            submitBtn.disabled = blocked || !gmailVerified();
            return;
        }

        submitBtn.disabled = blocked;
        notifyFormChange();
    }

    function updateStatus() {
        if (!statusEl) {
            updateSubmitState();
            return;
        }

        if (!dateInput.value) {
            statusEl.classList.add('hidden');
            updateSubmitState();
            return;
        }

        statusEl.classList.remove('hidden');

        if (!availability) {
            statusEl.textContent = 'Loading available time slots…';
            statusEl.className = 'text-xs mt-2 text-gray-500';
            updateSubmitState();
            return;
        }

        if (!availability.bookable) {
            statusEl.textContent = blockMessage(availability.blocked_reason);
            statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
        } else if (timeInput.value) {
            statusEl.textContent = bookingType === 'certificate'
                ? 'Pickup time selected. One visit per time slot.'
                : 'Appointment time selected. One visit per time slot.';
            statusEl.className = 'text-xs mt-2 font-semibold text-green-600';
        } else {
            var interval = availability.interval_minutes || (bookingType === 'certificate' ? 10 : 20);
            statusEl.textContent = bookingType === 'certificate'
                ? 'Choose a pickup slot every ' + interval + ' minutes (8:00 AM–12:00 NN, 1:00–5:00 PM).'
                : 'Choose an appointment slot every ' + interval + ' minutes (8:00 AM–12:00 NN, 1:00–5:00 PM).';
            statusEl.className = 'text-xs mt-2 text-gray-500';
        }

        updateSubmitState();
    }

    function showSlotLoadError() {
        availability = null;
        renderTimeOptions(null);
        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = 'Could not load time slots. Check your connection and try again.';
            statusEl.className = 'text-xs mt-2 font-semibold text-amber-700';
        }
        updateSubmitState();
    }

    function loadAvailability() {
        var date = dateInput.value;
        if (!date) {
            availability = null;
            renderTimeOptions(null);
            updateStatus();
            return;
        }

        availability = null;
        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = 'Loading available time slots…';
            statusEl.className = 'text-xs mt-2 text-gray-500';
        }
        renderTimeOptions(null);
        updateSubmitState();

        fetch('api/appointment_availability.php?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(bookingType), {
            credentials: 'same-origin',
            cache: 'no-store'
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('http_' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (!data || !data.ok) {
                    showSlotLoadError();
                    return;
                }
                availability = data;
                renderTimeOptions(data);
                updateStatus();
            })
            .catch(function () {
                showSlotLoadError();
            });
    }

    dateInput.addEventListener('change', function () {
        selectedTime = '';
        timeInput.value = '';
        loadAvailability();
    });

    timeInput.addEventListener('change', updateStatus);

    timeInput.disabled = true;
    if (dateInput.value) {
        loadAvailability();
    } else {
        renderTimeOptions(null);
        updateStatus();
    }
})();
