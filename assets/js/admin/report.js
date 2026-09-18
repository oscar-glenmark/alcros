(function () {
    'use strict';

    var printChecks = document.querySelectorAll('.report-print-check');
    var printSubmit = document.getElementById('reportPrintSubmit');
    var printSelectAll = document.getElementById('reportPrintSelectAll');
    var printClearAll = document.getElementById('reportPrintClearAll');
    var printPanel = document.getElementById('reportPrintPanel');

    if (printSelectAll) {
        printSelectAll.addEventListener('click', function () {
            printChecks.forEach(function (input) { input.checked = true; });
        });
    }

    if (printClearAll) {
        printClearAll.addEventListener('click', function () {
            printChecks.forEach(function (input) { input.checked = false; });
        });
    }

    function getSelectedPrintSections() {
        var selected = [];
        printChecks.forEach(function (input) {
            if (input.checked) selected.push(input.value);
        });
        return selected;
    }

    function restorePanels(states) {
        states.forEach(function (state) {
            state.panel.classList.remove('print-selected');
            if (state.hadHidden) {
                state.panel.classList.add('hidden');
            } else {
                state.panel.classList.remove('hidden');
            }
        });
    }

    function printSelectedSections() {
        var selected = getSelectedPrintSections();
        if (selected.length === 0) {
            window.alert('Select at least one report section to print.');
            return;
        }

        var panels = document.querySelectorAll('.report-panel[data-print-section]');
        var previous = [];

        panels.forEach(function (panel) {
            var key = panel.getAttribute('data-print-section');
            previous.push({ panel: panel, hadHidden: panel.classList.contains('hidden') });

            if (selected.indexOf(key) !== -1) {
                panel.classList.remove('hidden');
                panel.classList.add('print-selected');
            } else {
                panel.classList.add('hidden');
                panel.classList.remove('print-selected');
            }
        });

        if (printPanel) printPanel.classList.add('hidden');

        function onAfterPrint() {
            restorePanels(previous);
            window.removeEventListener('afterprint', onAfterPrint);
        }

        window.addEventListener('afterprint', onAfterPrint);
        window.print();

        // Fallback for browsers without afterprint
        setTimeout(function () {
            if (document.querySelector('.report-panel.print-selected')) {
                restorePanels(previous);
            }
        }, 1000);
    }

    if (printSubmit) {
        printSubmit.addEventListener('click', printSelectedSections);
    }

    setupDropdown('reportPrintMenu', 'reportPrintBtn', 'reportPrintPanel');
})();
