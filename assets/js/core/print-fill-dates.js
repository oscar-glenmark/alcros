(function (global) {
    'use strict';

    var CALENDAR_DATE_FIELDS = [
        'attendant_cert_date',
        'informant_date',
        'prepared_by_date',
        'received_by_date',
        'registration_date',
        'registrar_date',
        'burial_permit_date',
        'transfer_permit_date'
    ];

    function isPrintFillCalendarDateField(fieldName) {
        return CALENDAR_DATE_FIELDS.indexOf(String(fieldName || '')) !== -1;
    }

    function certDateToIso(cert) {
        var text = String(cert || '').trim();
        if (text === '') {
            return '';
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(text)) {
            return text;
        }
        var parsed = Date.parse(text);
        if (isNaN(parsed)) {
            return '';
        }
        var date = new Date(parsed);
        var y = date.getFullYear();
        var m = String(date.getMonth() + 1).padStart(2, '0');
        var d = String(date.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    function isoToCertDate(iso) {
        var value = String(iso || '').trim();
        if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) {
            return value;
        }
        if (global.AlcrosDateDisplay && typeof global.AlcrosDateDisplay.formatLongDate === 'function') {
            return global.AlcrosDateDisplay.formatLongDate(value);
        }
        var parts = value.split('-');
        var date = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
        if (isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
    }

    function storageValue(rawValue, fieldName) {
        if (!isPrintFillCalendarDateField(fieldName)) {
            return rawValue;
        }
        var trimmed = String(rawValue || '').trim();
        if (trimmed === '') {
            return '';
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
            return isoToCertDate(trimmed);
        }
        return trimmed;
    }

    global.AlcrosPrintFillDates = {
        isPrintFillCalendarDateField: isPrintFillCalendarDateField,
        certDateToIso: certDateToIso,
        isoToCertDate: isoToCertDate,
        storageValue: storageValue
    };
})(window);
