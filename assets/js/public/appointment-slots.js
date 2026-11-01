(function () {
    'use strict';

    var dateInput = document.getElementById('appointmentDateInput');
    var timeInput = document.getElementById('appointmentTimeInput');
    var statusEl = document.getElementById('slotAvailabilityStatus');
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
    var loadRequestId = 0;
    var loadAbortController = null;
    var holidayConfig = null;

    function readHolidayConfig() {
        if (holidayConfig) {
            return holidayConfig;
        }
        var el = document.getElementById('alcrosOfficeHolidayConfig');
        if (!el || !el.textContent) {
            holidayConfig = { recurring: [], dates: [], today: '' };
            return holidayConfig;
        }
        try {
            holidayConfig = JSON.parse(el.textContent);
        } catch (e) {
            holidayConfig = { recurring: [], dates: [], today: '' };
        }
        if (!holidayConfig.recurring) {
            holidayConfig.recurring = [];
        }
        if (!holidayConfig.dates) {
            holidayConfig.dates = [];
        }
        return holidayConfig;
    }

    function officeDateBlockReason(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) {
            return 'invalid';
        }
        var cfg = readHolidayConfig();
        var today = cfg.today || new Date().toISOString().slice(0, 10);
        if (iso < today) {
            return 'past';
        }
        var parts = iso.split('-');
        var date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        if (isNaN(date.getTime())) {
            return 'invalid';
        }
        var dow = date.getDay();
        if (dow === 0 || dow === 6) {
            return 'weekend';
        }
        if (cfg.dates.indexOf(iso) !== -1) {
            return 'holiday';
        }
        var suffix = iso.slice(4);
        if (cfg.recurring.indexOf(suffix) !== -1) {
            return 'holiday';
        }
        return null;
    }

    function showBlockedDate(reason) {
        availability = null;
        selectedTime = '';
        timeInput.value = '';
        renderTimeOptions(null);
        syncAvailabilityState();
        if (!statusEl) {
            return;
        }
        statusEl.classList.remove('hidden');
        statusEl.textContent = blockMessage(reason);
        statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
    }

    function syncAvailabilityState() {
        if (!scheduleForm) return;
        scheduleForm._alcrosSlotAvailability = availability;
        scheduleForm._alcrosSlotBookable = !!(availability && availability.bookable);
    }

    function slotUnavailableLabel(slot) {
        if (slot && slot.status === 'past') {
            return slot.label + ' — Not available';
        }
        if (slot && slot.status === 'booked') {
            return slot.label + ' — Booked';
        }
        return slot.label + ' — Not available';
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
                option.textContent = slot.available ? slot.label : slotUnavailableLabel(slot);
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

    function updateStatus() {
        syncAvailabilityState();

        if (!statusEl) {
            return;
        }

        if (!dateInput.value) {
            statusEl.classList.add('hidden');
            return;
        }

        statusEl.classList.remove('hidden');

        if (!availability) {
            statusEl.textContent = 'Loading available time slots…';
            statusEl.className = 'text-xs mt-2 text-gray-500';
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
    }

    function showSlotLoadError() {
        availability = null;
        renderTimeOptions(null);
        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = 'Could not load time slots. Check your connection and try again.';
            statusEl.className = 'text-xs mt-2 font-semibold text-amber-700';
        }
        updateStatus();
    }

    function loadAvailability() {
        var date = dateInput.value;
        if (!date) {
            availability = null;
            renderTimeOptions(null);
            updateStatus();
            return;
        }

        var blockReason = officeDateBlockReason(date);
        if (blockReason) {
            dateInput.value = '';
            showBlockedDate(blockReason);
            return;
        }

        availability = null;
        if (statusEl) {
            statusEl.classList.remove('hidden');
            statusEl.textContent = 'Loading available time slots…';
            statusEl.className = 'text-xs mt-2 text-gray-500';
        }
        renderTimeOptions(null);
        syncAvailabilityState();

        if (loadAbortController && typeof loadAbortController.abort === 'function') {
            loadAbortController.abort();
        }
        loadAbortController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var requestId = ++loadRequestId;
        var fetchOptions = { credentials: 'same-origin', cache: 'no-store' };
        if (loadAbortController) {
            fetchOptions.signal = loadAbortController.signal;
        }

        fetch('api/appointment_availability.php?date=' + encodeURIComponent(date) + '&type=' + encodeURIComponent(bookingType), fetchOptions)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('http_' + response.status);
                }
                return response.json();
            })
            .then(function (data) {
                if (requestId !== loadRequestId) {
                    return;
                }
                if (!data || !data.ok) {
                    if (statusEl && data && data.error) {
                        availability = null;
                        renderTimeOptions(null);
                        statusEl.classList.remove('hidden');
                        statusEl.textContent = data.error;
                        statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
                        syncAvailabilityState();
                        return;
                    }
                    showSlotLoadError();
                    return;
                }
                availability = data;
                renderTimeOptions(data);
                updateStatus();
            })
            .catch(function (err) {
                if (requestId !== loadRequestId) {
                    return;
                }
                if (err && err.name === 'AbortError') {
                    return;
                }
                showSlotLoadError();
            });
    }

    dateInput.addEventListener('change', function () {
        selectedTime = '';
        timeInput.value = '';
        var reason = officeDateBlockReason(dateInput.value);
        if (reason) {
            dateInput.value = '';
            showBlockedDate(reason);
            return;
        }
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
