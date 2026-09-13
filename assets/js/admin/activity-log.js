(function () {
    'use strict';

    var printBtn = document.getElementById('activityLogPrintBtn');
    if (printBtn) {
        printBtn.addEventListener('click', function () {
            window.print();
        });
    }
})();
