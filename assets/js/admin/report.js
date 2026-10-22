(function () {
    'use strict';

    function bindDropdown(menuId, btnId, panelId) {
        var menu = document.getElementById(menuId);
        var btn = document.getElementById(btnId);
        var panel = document.getElementById(panelId);
        if (!menu || !btn || !panel) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            panel.classList.toggle('hidden');
        });

        document.addEventListener('click', function (e) {
            if (!menu.contains(e.target)) {
                panel.classList.add('hidden');
            }
        });
    }

    var exportChecks = document.querySelectorAll('.report-export-check');
    var exportSubmit = document.getElementById('reportExportSubmit');
    var exportSelectAll = document.getElementById('reportExportSelectAll');
    var exportClearAll = document.getElementById('reportExportClearAll');
    var exportPanel = document.getElementById('reportExportPanel');
    var exportForm = document.getElementById('reportExportForm');
    var exportSectionsField = document.getElementById('reportExportSectionsField');
    var exportRecordsTypeField = document.getElementById('reportExportRecordsTypeField');
    var exportRecordsFilter = document.getElementById('reportExportRecordsFilter');
    var exportYearField = document.getElementById('reportExportYearField');
    var exportYearSelect = document.getElementById('reportExportYearSelect');
    var exportRecordsYearNote = document.getElementById('reportExportRecordsYearNote');

    function syncExportRecordsYearField() {
        if (!exportYearSelect || !exportYearField) {
            return;
        }

        exportYearField.value = exportYearSelect.value;

        if (exportRecordsYearNote) {
            exportRecordsYearNote.innerHTML = 'Includes quarterly summary plus full record rows for <strong class="text-slate-600">'
                + exportYearSelect.value
                + '</strong>.';
        }
    }

    function syncRecordsTypeFilterVisibility() {
        if (!exportRecordsFilter) return;

        var recordsSelected = false;
        exportChecks.forEach(function (input) {
            if (input.checked && input.value === 'records') {
                recordsSelected = true;
            }
        });

        exportRecordsFilter.classList.toggle('hidden', !recordsSelected);
        if (recordsSelected) {
            syncExportRecordsYearField();
        }
    }

    if (exportSelectAll) {
        exportSelectAll.addEventListener('click', function () {
            exportChecks.forEach(function (input) { input.checked = true; });
            syncRecordsTypeFilterVisibility();
        });
    }

    if (exportClearAll) {
        exportClearAll.addEventListener('click', function () {
            exportChecks.forEach(function (input) { input.checked = false; });
            syncRecordsTypeFilterVisibility();
        });
    }

    exportChecks.forEach(function (input) {
        input.addEventListener('change', syncRecordsTypeFilterVisibility);
    });

    if (exportYearSelect) {
        exportYearSelect.addEventListener('change', syncExportRecordsYearField);
    }

    function getSelectedExportSections() {
        var selected = [];
        exportChecks.forEach(function (input) {
            if (input.checked) selected.push(input.value);
        });
        return selected;
    }

    function getSelectedRecordsType() {
        var selected = document.querySelector('input[name="reportExportRecordsType"]:checked');
        return selected ? selected.value : 'all';
    }

    function exportSelectedSections() {
        var selected = getSelectedExportSections();
        if (selected.length === 0) {
            window.alert('Select at least one report section to export.');
            return;
        }

        if (!exportForm || !exportSectionsField || !exportRecordsTypeField) {
            return;
        }

        exportSectionsField.value = selected.join(',');
        exportRecordsTypeField.value = selected.indexOf('records') !== -1
            ? getSelectedRecordsType()
            : 'all';

        if (selected.indexOf('records') !== -1) {
            syncExportRecordsYearField();
        }

        if (exportPanel) exportPanel.classList.add('hidden');
        exportForm.submit();
    }

    if (exportSubmit) {
        exportSubmit.addEventListener('click', exportSelectedSections);
    }

    syncRecordsTypeFilterVisibility();
    bindDropdown('reportExportMenu', 'reportExportBtn', 'reportExportPanel');

    var exportDirectBtn = document.getElementById('reportExportDirectBtn');

    function exportCurrentDetailSection() {
        if (!exportDirectBtn || !exportForm || !exportSectionsField || !exportRecordsTypeField) {
            return;
        }

        var section = String(exportDirectBtn.getAttribute('data-export-section') || '').trim();
        if (!section) {
            return;
        }

        exportSectionsField.value = section;
        exportRecordsTypeField.value = 'all';
        if (section === 'records') {
            syncExportRecordsYearField();
        }
        exportForm.submit();
    }

    if (exportDirectBtn) {
        exportDirectBtn.addEventListener('click', exportCurrentDetailSection);
    }

    function submitCustomRangeForm(form) {
        if (!form) {
            return;
        }
        if (window.AlcrosLoading && typeof window.AlcrosLoading.page === 'function') {
            window.AlcrosLoading.page(true, 'Updating report…');
        }
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    document.querySelectorAll('.report-custom-range-form').forEach(function (form) {
        form.querySelectorAll('.js-report-range-date').forEach(function (input) {
            input.addEventListener('change', function () {
                submitCustomRangeForm(form);
            });
        });
    });
})();
