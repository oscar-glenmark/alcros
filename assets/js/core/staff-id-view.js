(function (global) {
    'use strict';

    var dialog = null;
    var imgEl = null;
    var frameEl = null;
    var titleEl = null;
    var navPrev = null;
    var navNext = null;
    var galleryItems = [];
    var galleryIndex = -1;

    function init() {
        dialog = document.getElementById('alcrosStaffIdDialog');
        if (!dialog) {
            return;
        }
        imgEl = document.getElementById('alcrosStaffIdDialogImg');
        frameEl = document.getElementById('alcrosStaffIdDialogFrame');
        titleEl = document.getElementById('alcrosStaffIdDialogTitle');
        navPrev = dialog.querySelector('[data-staff-id-nav="prev"]');
        navNext = dialog.querySelector('[data-staff-id-nav="next"]');

        dialog.addEventListener('click', function (e) {
            if (e.target === dialog) {
                close();
            }
        });

        dialog.addEventListener('close', function () {
            resetMedia();
            clearGallery();
        });

        dialog.addEventListener('keydown', function (e) {
            if (galleryItems.length <= 1) {
                return;
            }
            if (e.key === 'ArrowRight') {
                e.preventDefault();
                stepGallery(1);
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                stepGallery(-1);
            }
        });
    }

    function clearGallery() {
        galleryItems = [];
        galleryIndex = -1;
        updateSideNav();
    }

    function updateSideNav() {
        var show = galleryItems.length > 1;
        if (navPrev) {
            navPrev.classList.toggle('hidden', !show);
        }
        if (navNext) {
            navNext.classList.toggle('hidden', !show);
        }
    }

    function itemFromTrigger(el) {
        return {
            src: el.getAttribute('data-staff-id-src') || '',
            label: el.getAttribute('data-staff-id-label') || 'ID',
            isPdf: el.getAttribute('data-staff-id-pdf') === '1'
        };
    }

    function buildGalleryFromTrigger(trigger) {
        var root = trigger.closest('.admin-id-preview-grid');
        if (!root) {
            return { items: [itemFromTrigger(trigger)], index: 0 };
        }
        var triggers = root.querySelectorAll('[data-staff-id-open]');
        var items = [];
        var index = 0;
        for (var i = 0; i < triggers.length; i++) {
            var t = triggers[i];
            var item = itemFromTrigger(t);
            if (!item.src) {
                continue;
            }
            if (t === trigger) {
                index = items.length;
            }
            items.push(item);
        }
        if (!items.length) {
            items.push(itemFromTrigger(trigger));
            index = 0;
        }
        return { items: items, index: index };
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

    function setDialogContent(src, label, isPdf) {
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
                frameEl.setAttribute('title', label || 'ID document');
            }
        } else {
            if (frameEl) {
                frameEl.classList.add('hidden');
                frameEl.setAttribute('src', 'about:blank');
            }
            if (imgEl) {
                imgEl.classList.remove('hidden');
                imgEl.setAttribute('src', src);
                imgEl.setAttribute('alt', label || 'ID preview');
            }
        }
    }

    function showGalleryAt(index) {
        if (!galleryItems.length) {
            return;
        }
        var len = galleryItems.length;
        galleryIndex = ((index % len) + len) % len;
        var item = galleryItems[galleryIndex];
        if (!item || !item.src) {
            return;
        }
        setDialogContent(item.src, item.label, item.isPdf);
    }

    function stepGallery(delta) {
        if (galleryItems.length <= 1) {
            return;
        }
        showGalleryAt(galleryIndex + delta);
    }

    function openPreview(src, label, isPdf, galleryContext) {
        if (!src) {
            return;
        }
        if (!dialog) {
            init();
        }
        if (!dialog) {
            return;
        }

        if (galleryContext && galleryContext.items && galleryContext.items.length) {
            galleryItems = galleryContext.items;
            galleryIndex = typeof galleryContext.index === 'number' ? galleryContext.index : 0;
        } else {
            galleryItems = [];
            galleryIndex = -1;
        }

        if (galleryItems.length > 1) {
            showGalleryAt(galleryIndex);
        } else {
            setDialogContent(src, label, isPdf);
        }
        updateSideNav();

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
        clearGallery();
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
        var gallery = buildGalleryFromTrigger(trigger);
        var item = gallery.items[gallery.index] || itemFromTrigger(trigger);
        openPreview(item.src, item.label, item.isPdf, gallery);
    }

    function handleCloseClick(e) {
        if (e.target.closest('[data-staff-id-dialog-close]')) {
            e.preventDefault();
            close();
        }
    }

    function handleNavClick(e) {
        var btn = e.target.closest('[data-staff-id-nav]');
        if (!btn || !dialog || !dialog.open) {
            return;
        }
        e.preventDefault();
        e.stopPropagation();
        var dir = btn.getAttribute('data-staff-id-nav');
        if (dir === 'next') {
            stepGallery(1);
        } else if (dir === 'prev') {
            stepGallery(-1);
        }
    }

    document.addEventListener('click', handleOpenClick, true);
    document.addEventListener('click', handleCloseClick, true);
    document.addEventListener('click', handleNavClick, true);

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
