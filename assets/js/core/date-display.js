(function (global) {
    'use strict';

    function formatLongDate(iso) {
        if (!iso) {
            return '';
        }
        var parts = String(iso).trim().split('-');
        if (parts.length !== 3) {
            return iso;
        }
        var y = parseInt(parts[0], 10);
        var m = parseInt(parts[1], 10) - 1;
        var d = parseInt(parts[2], 10);
        var date = new Date(y, m, d);
        if (isNaN(date.getTime())) {
            return iso;
        }
        return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function wireDatePickerLabel(wrap) {
        if (!wrap) {
            return;
        }
        var input = wrap.querySelector('input[type="date"]');
        var label = wrap.querySelector('[data-date-picker-label]');
        if (!input || !label) {
            return;
        }
        var placeholder = label.getAttribute('data-placeholder') || 'Choose date';

        function sync() {
            label.textContent = input.value ? formatLongDate(input.value) : placeholder;
        }

        sync();
        input.addEventListener('change', sync);
        input.addEventListener('input', sync);
    }

    function wireAllDatePickers(root) {
        (root || document).querySelectorAll('.alcros-date-picker').forEach(wireDatePickerLabel);
    }

    global.AlcrosDateDisplay = {
        formatLongDate: formatLongDate,
        wireDatePickerLabel: wireDatePickerLabel,
        wireAllDatePickers: wireAllDatePickers
    };

    document.addEventListener('DOMContentLoaded', function () {
        wireAllDatePickers(document);
    });
})(window);
