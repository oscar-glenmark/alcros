(function () {
    var modal = document.getElementById('serviceRequirementsModal');
    if (!modal) {
        return;
    }

    var backdrop = document.getElementById('serviceRequirementsBackdrop');
    var closeBtn = document.getElementById('serviceRequirementsClose');
    var dismissBtn = document.getElementById('serviceRequirementsDismiss');
    var titleEl = document.getElementById('serviceRequirementsTitle');
    var subtitleEl = document.getElementById('serviceRequirementsSubtitle');
    var bodyEl = document.getElementById('serviceRequirementsBody');
    var scheduleLink = document.getElementById('serviceRequirementsSchedule');
    var metaEl = document.getElementById('serviceRequirementsMeta');
    var metaMap = {};
    var lastFocus = null;

    if (metaEl && metaEl.textContent) {
        try {
            metaMap = JSON.parse(metaEl.textContent);
        } catch (e) {
            metaMap = {};
        }
    }

    function refreshIcons() {
        if (typeof lucide !== 'undefined' && lucide.createIcons) {
            lucide.createIcons();
        }
    }

    function openRequirementsWithSlug(slug, title, subtitle, scheduleUrl) {
        var template = document.getElementById('svc-req-' + slug);
        if (!template || !bodyEl) {
            return;
        }

        lastFocus = document.activeElement;

        titleEl.textContent = title || 'Requirements';
        if (subtitle && subtitle.trim() !== '') {
            subtitleEl.textContent = subtitle;
            subtitleEl.classList.remove('hidden');
        } else {
            subtitleEl.textContent = '';
            subtitleEl.classList.add('hidden');
        }

        bodyEl.innerHTML = '';
        bodyEl.appendChild(template.content.cloneNode(true));

        if (scheduleLink) {
            scheduleLink.setAttribute('href', scheduleUrl || 'book_appointment.php');
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('svc-req-modal-open');
        if (closeBtn) {
            closeBtn.focus();
        }
        refreshIcons();
    }

    function openRequirements(btn) {
        openRequirementsWithSlug(
            btn.getAttribute('data-open-service-requirements') || '',
            btn.getAttribute('data-req-title') || '',
            btn.getAttribute('data-req-subtitle') || '',
            btn.getAttribute('data-schedule-url') || 'book_appointment.php'
        );
    }

    function closeRequirements() {
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('svc-req-modal-open');
        if (bodyEl) {
            bodyEl.innerHTML = '';
        }
        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    document.querySelectorAll('[data-open-service-requirements]').forEach(function (btn) {
        btn.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openRequirements(btn);
        });
    });

    [closeBtn, dismissBtn, backdrop].forEach(function (el) {
        if (el) {
            el.addEventListener('click', closeRequirements);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeRequirements();
        }
    });

    var params = new URLSearchParams(window.location.search);
    var deepSlug = params.get('requirements');
    if (deepSlug && metaMap[deepSlug]) {
        var entry = metaMap[deepSlug];
        window.setTimeout(function () {
            openRequirementsWithSlug(
                deepSlug,
                entry.title || '',
                entry.subtitle || '',
                entry.scheduleUrl || ('book_appointment.php?service=' + encodeURIComponent(deepSlug))
            );
        }, 0);
    }
})();
