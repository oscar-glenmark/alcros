(function () {
    'use strict';

    var OPERATIONAL = { pending_request: 1, appointment: 1, queue: 1 };

    function staffRoot() {
        return document.getElementById('notif-wrapper') ||
            document.querySelector('[data-staff-id]') ||
            document.body;
    }

    function staffId() {
        var el = staffRoot();
        return el ? String(el.getAttribute('data-staff-id') || 'staff') : 'staff';
    }

    function portalRoleFromDom() {
        var el = staffRoot();
        var role = el ? String(el.getAttribute('data-staff-portal-role') || '').toLowerCase() : '';
        return role === 'administrator' ? 'administrator' : 'staff';
    }

    function storageKey(suffix) {
        return 'alcros_desktop_' + staffId() + '_' + suffix;
    }

    function isEnabled() {
        try {
            return localStorage.getItem(storageKey('on')) === '1';
        } catch (e) {
            return false;
        }
    }

    function setEnabled(on) {
        try {
            localStorage.setItem(storageKey('on'), on ? '1' : '0');
        } catch (e) { /* ignore */ }
    }

    function getShownIds() {
        try {
            var raw = localStorage.getItem(storageKey('shown'));
            var list = raw ? JSON.parse(raw) : [];
            return Array.isArray(list) ? list : [];
        } catch (e) {
            return [];
        }
    }

    function markShown(id) {
        if (!id) return;
        var list = getShownIds();
        if (list.indexOf(id) === -1) {
            list.push(id);
            localStorage.setItem(storageKey('shown'), JSON.stringify(list.slice(-300)));
        }
    }

    function seedShownIds(ids) {
        try {
            localStorage.setItem(storageKey('shown'), JSON.stringify(ids.slice(-300)));
        } catch (e) { /* ignore */ }
    }

    function buildHref(href) {
        if (window.AlcrosPoll && typeof window.AlcrosPoll.buildUrl === 'function') {
            return window.AlcrosPoll.buildUrl(href || 'dashboard.php', {});
        }
        return href || 'dashboard.php';
    }

    function showToast(item) {
        if (!('Notification' in window) || Notification.permission !== 'granted') {
            return;
        }
        var body = String(item.message || '').trim();
        if (item.detail) {
            body = body ? body + ' · ' + item.detail : String(item.detail);
        }
        var note = new Notification(String(item.title || 'ALCROS'), {
            body: body,
            icon: 'images/favicon.png',
            tag: String(item.id || 'alcros-alert')
        });
        note.onclick = function () {
            try {
                window.focus();
                window.location.href = buildHref(item.href);
            } finally {
                note.close();
            }
        };
    }

    var seedPending = false;

    function requestSeedOnNextPoll() {
        seedPending = true;
        if (window.AlcrosAdminLive && typeof window.AlcrosAdminLive.refresh === 'function') {
            window.AlcrosAdminLive.refresh();
        }
    }

    function handleLivePayload(data) {
        if (!isEnabled()) return;
        if ((data.portal_role || portalRoleFromDom()) === 'administrator') return;

        var list = (data.notifications || []).filter(function (n) {
            return n && n.id && OPERATIONAL[n.type];
        });

        if (seedPending) {
            seedPending = false;
            seedShownIds(list.map(function (n) { return n.id; }));
            return;
        }

        if (!list.length) return;

        var shown = getShownIds();
        var shownMap = {};
        shown.forEach(function (id) { shownMap[id] = true; });

        list.forEach(function (n) {
            if (shownMap[n.id]) return;
            markShown(n.id);
            showToast(n);
        });
    }

    function setStatusEl(el, message, tone) {
        if (!el) return;
        el.textContent = message || '';
        el.classList.remove('hidden', 'text-emerald-700', 'text-amber-700', 'text-red-700', 'text-slate-600');
        if (!message) {
            el.classList.add('hidden');
            return;
        }
        if (tone === 'error') el.classList.add('text-red-700');
        else if (tone === 'warn') el.classList.add('text-amber-700');
        else if (tone === 'ok') el.classList.add('text-emerald-700');
        else el.classList.add('text-slate-600');
    }

    function wireSettingsPanel() {
        var panel = document.getElementById('staff-desktop-notify-settings');
        if (!panel) return;

        var toggle = document.getElementById('staffDesktopNotifyEnabled');
        var enableBtn = document.getElementById('staffDesktopNotifyAllowBtn');
        var statusEl = document.getElementById('staffDesktopNotifyStatus');

        if (toggle) {
            toggle.checked = isEnabled();
            toggle.addEventListener('change', function () {
                var want = toggle.checked;
                if (!want) {
                    setEnabled(false);
                    setStatusEl(statusEl, 'Desktop pop-ups disabled for this browser.', 'muted');
                    return;
                }
                if (!('Notification' in window)) {
                    toggle.checked = false;
                    setStatusEl(statusEl, 'This browser does not support notifications.', 'error');
                    return;
                }
                if (Notification.permission === 'granted') {
                    setEnabled(true);
                    requestSeedOnNextPoll();
                    setStatusEl(statusEl, 'Enabled. Keep Chrome or Edge running (minimized is OK).', 'ok');
                    return;
                }
                if (Notification.permission === 'denied') {
                    toggle.checked = false;
                    setStatusEl(statusEl, 'Notifications are blocked. Allow them in browser site settings.', 'warn');
                    return;
                }
                Notification.requestPermission().then(function (perm) {
                    if (perm === 'granted') {
                        setEnabled(true);
                        requestSeedOnNextPoll();
                        setStatusEl(statusEl, 'Enabled. Pop-ups work in another tab or when the browser is minimized.', 'ok');
                    } else {
                        toggle.checked = false;
                        setEnabled(false);
                        setStatusEl(statusEl, 'Permission was not granted.', 'warn');
                    }
                });
            });
        }

        if (enableBtn) {
            enableBtn.addEventListener('click', function () {
                if (!('Notification' in window)) {
                    setStatusEl(statusEl, 'Notifications are not supported here.', 'error');
                    return;
                }
                Notification.requestPermission().then(function (perm) {
                    if (perm === 'granted') {
                        setEnabled(true);
                        if (toggle) toggle.checked = true;
                        requestSeedOnNextPoll();
                        setStatusEl(statusEl, 'Permission granted.', 'ok');
                    } else {
                        setStatusEl(statusEl, 'Permission denied or dismissed.', 'warn');
                    }
                });
            });
        }

    }

    document.addEventListener('alcros:admin-live', function (e) {
        handleLivePayload(e.detail || {});
    });

    document.addEventListener('DOMContentLoaded', wireSettingsPanel);
})();
