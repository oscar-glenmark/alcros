(function (global) {
    'use strict';

    var dialog = null;
    var imgEl = null;
    var frameEl = null;
    var titleEl = null;

    function init() {
        dialog = document.getElementById('alcrosStaffIdDialog');
        if (!dialog) {
            return;
        }
        imgEl = document.getElementById('alcrosStaffIdDialogImg');
        frameEl = document.getElementById('alcrosStaffIdDialogFrame');
        titleEl = document.getElementById('alcrosStaffIdDialogTitle');

        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });

        dialog.addEventListener('close', function () {
            resetMedia();
        });
    }

    function resetMedia() {
        if (imgEl) {
            imgEl.removeAttribute('src');
            imgEl.classList.add('hidden');
        }
        if (frameEl) {
            frameEl.setAttribute('src', 'about:blank');
            frameEl.classList.add('hidden');
        }
    }

    function openPreview(src, label, isPdf) {
        if (!src) {
            return;
        }
        if (!dialog) {
            init();
        }
        if (!dialog) {
            return;
        }

        if (titleEl) {
            titleEl.textContent = label || 'ID preview';
        }

        if (isPdf) {
            if (imgEl) {
                imgEl.classList.add('hidden');
                imgEl.removeAttribute('src');
            }
            if (frameEl) {
                frameEl.classList.remove('hidden');
                frameEl.setAttribute('src', src);
            }
        } else {
            if (frameEl) {
                frameEl.classList.add('hidden');
                frameEl.setAttribute('src', 'about:blank');
            }
            if (imgEl) {
                imgEl.classList.remove('hidden');
                imgEl.setAttribute('src', src);
            }
        }

        if (typeof dialog.showModal === 'function') {
            try {
                dialog.showModal();
            } catch (err) {
                dialog.setAttribute('open', 'open');
            }
        } else {
            dialog.setAttribute('open', 'open');
        }
    }

    function close() {
        if (!dialog) {
            return;
        }
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
        resetMedia();
    }

    function handleOpenClick(e) {
        var trigger = e.target.closest('[data-staff-id-open]');
        if (!trigger) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        if (typeof e.stopImmediatePropagation === 'function') {
            e.stopImmediatePropagation();
        }
        openPreview(
            trigger.getAttribute('data-staff-id-src') || '',
            trigger.getAttribute('data-staff-id-label') || 'ID',
            trigger.getAttribute('data-staff-id-pdf') === '1'
        );
    }

    function handleCloseClick(e) {
        if (e.target.closest('[data-staff-id-dialog-close]')) {
            e.preventDefault();
            close();
        }
    }

    document.addEventListener('click', handleOpenClick, true);
    document.addEventListener('click', handleCloseClick, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    global.AlcrosStaffIdView = {
        open: openPreview,
        close: close
    };
})(window);
