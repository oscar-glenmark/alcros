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

    function syncRecordsTypeFilterVisibility() {
        if (!exportRecordsFilter) return;

        var recordsSelected = false;
        exportChecks.forEach(function (input) {
            if (input.checked && input.value === 'records') {
                recordsSelected = true;
            }
        });

        exportRecordsFilter.classList.toggle('hidden', !recordsSelected);
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

        if (exportPanel) exportPanel.classList.add('hidden');
        exportForm.submit();
    }

    if (exportSubmit) {
        exportSubmit.addEventListener('click', exportSelectedSections);
    }

    syncRecordsTypeFilterVisibility();
    bindDropdown('reportExportMenu', 'reportExportBtn', 'reportExportPanel');
})();
