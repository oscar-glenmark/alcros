(function () {
    'use strict';

    var STYLES = {
        pending_request: { icon: 'file-text', bg: 'bg-yellow-100', text: 'text-yellow-600' },
        ready_pickup:    { icon: 'circle-check', bg: 'bg-green-100', text: 'text-green-600' },
        queue:           { icon: 'users', bg: 'bg-blue-100', text: 'text-blue-600' },
        appointment:     { icon: 'calendar', bg: 'bg-purple-100', text: 'text-purple-600' },
        system:          { icon: 'alert-triangle', bg: 'bg-red-100', text: 'text-red-600' }
    };

    function staffRoot() {
        return document.getElementById('notif-wrapper') ||
            document.querySelector('[data-staff-id]');
    }

    function staffKey(suffix) {
        var el = staffRoot();
        var id = el ? el.getAttribute('data-staff-id') : 'staff';
        return 'alcros_notif_' + id + '_' + suffix;
    }

    function loadJson(key, fallback) {
        try {
            var val = localStorage.getItem(key);
            return val ? JSON.parse(val) : fallback;
        } catch (e) {
            return fallback;
        }
    }

    function saveJson(key, value) {
        localStorage.setItem(key, JSON.stringify(value));
    }

    function getSeenAt() {
        return parseInt(localStorage.getItem(staffKey('seen')) || '0', 10) || 0;
    }

    function setSeenAt(ts) {
        localStorage.setItem(staffKey('seen'), String(ts || Date.now()));
    }

    function getBadgesAckAt() {
        return parseInt(localStorage.getItem(staffKey('badges_ack')) || '0', 10) || 0;
    }

    function setBadgesAckAt(ts) {
        localStorage.setItem(staffKey('badges_ack'), String(ts || Date.now()));
    }

    function getClearedAt() {
        return parseInt(localStorage.getItem(staffKey('cleared')) || '0', 10) || 0;
    }

    function setClearedAt(ts) {
        localStorage.setItem(staffKey('cleared'), String(ts || Date.now()));
    }

    function getDismissed() {
        return loadJson(staffKey('dismissed'), []);
    }

    function dismissId(id) {
        if (!id) return;
        var list = getDismissed();
        if (list.indexOf(id) === -1) {
            list.push(id);
            saveJson(staffKey('dismissed'), list.slice(-200));
        }
    }

    function notifTime(n) {
        return new Date((n.created_at || '').replace(' ', 'T')).getTime();
    }

    function isPersistentAlert(n) {
        return n.type === 'pending_request' || n.type === 'appointment';
    }

    function visibleList(all) {
        var clearedAt = getClearedAt();
        var dismissed = getDismissed();
        return (all || []).filter(function (n) {
            if (isPersistentAlert(n)) {
                return true;
            }
            if (dismissed.indexOf(n.id) !== -1) return false;
            if (clearedAt && notifTime(n) <= clearedAt) return false;
            return true;
        });
    }

    function isUnread(n) {
        return notifTime(n) > getSeenAt();
    }

    function countUnread(all) {
        return visibleList(all).filter(isUnread).length;
    }

    function escapeHtml(str) {
        return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function formatTime(datetime) {
        var ts = new Date((datetime || '').replace(' ', 'T'));
        if (isNaN(ts)) return datetime || '';
        var now = new Date();
        if (ts.toDateString() === now.toDateString()) {
            return ts.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        }
        return ts.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' +
            ts.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }

    function buildHref(href) {
        return window.AlcrosPoll ? AlcrosPoll.buildUrl(href || 'dashboard.php', {}) : (href || 'dashboard.php');
    }

    function updateBadgeEl(badge, count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 9 ? '9+' : String(count);
            badge.classList.remove('hidden');
        } else {
            badge.classList.add('hidden');
        }
    }

    function hasNewActivity(all) {
        var visible = visibleList(all);
        if (!visible.length) {
            return false;
        }

        var ackAt = getBadgesAckAt();
        if (!ackAt) {
            return true;
        }

        return visible.some(function (n) {
            return notifTime(n) > ackAt;
        });
    }

    function actionCounts(counts) {
        return {
            requests: parseInt((counts || {}).pending_requests, 10) || 0,
            appointments: parseInt((counts || {}).pending_appointments, 10) || 0
        };
    }

    function computeBadgeCounts(all, counts) {
        var visible = visibleList(all);
        if (!hasNewActivity(all)) {
            return { bell: 0, requests: 0, appointments: 0 };
        }

        var unread = visible.filter(isUnread).length;
        var actions = actionCounts(counts);
        var hasPendingReqNotifs = visible.some(function (n) { return n.type === 'pending_request'; });
        var hasApptNotifs = visible.some(function (n) { return n.type === 'appointment'; });

        return {
            bell: unread,
            requests: hasPendingReqNotifs ? actions.requests : 0,
            appointments: hasApptNotifs ? actions.appointments : 0
        };
    }

    function updateAllBadges(all, counts) {
        var badgeCounts = computeBadgeCounts(all, counts);
        updateBadgeEl(document.getElementById('notif-badge'), badgeCounts.bell);
        updateBadgeEl(document.getElementById('sidebar-notif-badge'), badgeCounts.bell);
        updateBadgeEl(document.getElementById('sidebar-request-badge'), badgeCounts.requests);
        updateBadgeEl(document.getElementById('sidebar-appt-badge'), badgeCounts.appointments);
    }

    function acknowledgeAllBadges(all, counts) {
        var now = Date.now();
        setBadgesAckAt(now);
        setSeenAt(now);
        updateAllBadges(all || [], counts || { pending_requests: 0, pending_appointments: 0 });
    }

    function renderListEl(listEl, all, options) {
        if (!listEl) return;

        if (window.AlcrosLoading && typeof window.AlcrosLoading.clearSkeletonHost === 'function') {
            window.AlcrosLoading.clearSkeletonHost(listEl);
        }

        options = options || {};
        var items = visibleList(all);
        var emptyText = listEl.getAttribute('data-notif-empty') || 'No notifications';

        if (!items.length) {
            listEl.innerHTML = '<p class="text-gray-400 text-xs italic p-8 text-center">' + escapeHtml(emptyText) + '</p>';
            return;
        }

        var showDetail = options.showDetail !== false;
        listEl.innerHTML = items.map(function (n) {
            var s = STYLES[n.type] || { icon: 'bell', bg: 'bg-gray-100', text: 'text-gray-500' };
            var href = buildHref(n.href);
            var faded = isUnread(n) ? '' : ' opacity-60';
            var detail = showDetail && n.detail
                ? '<p class="text-[10px] text-gray-400 font-mono truncate mt-0.5">' + escapeHtml(n.detail) + '</p>'
                : '';

            var deleteBtn = (n.type === 'system' || isPersistentAlert(n))
                ? ''
                : '<button type="button" class="notif-delete p-1.5 rounded-lg text-gray-300 hover:text-red-500 self-start" data-id="' + escapeHtml(n.id) + '" title="Remove">' +
                    '<i data-lucide="x" class="w-3.5 h-3.5"></i></button>';

            return '<div class="notif-item group flex gap-2 px-3 py-3 border-b border-gray-50' + faded + '">' +
                '<a href="' + href + '" class="flex gap-3 min-w-0 flex-1">' +
                '<div class="w-9 h-9 rounded-full ' + s.bg + ' ' + s.text + ' flex items-center justify-center shrink-0">' +
                '<i data-lucide="' + s.icon + '" class="w-4 h-4"></i></div>' +
                '<div class="min-w-0 flex-1">' +
                '<p class="text-xs font-bold text-slate-800 truncate">' + escapeHtml(n.title) + '</p>' +
                '<p class="text-[11px] text-gray-500 line-clamp-2">' + escapeHtml(n.message) + '</p>' +
                detail +
                '</div>' +
                '<span class="text-[9px] text-gray-400 shrink-0 self-start pt-0.5">' + formatTime(n.created_at) + '</span>' +
                '</a>' +
                deleteBtn + '</div>';
        }).join('');

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function renderAllLists(all) {
        document.querySelectorAll('.alcros-notif-list').forEach(function (listEl) {
            var isPage = listEl.id === 'notif-page-list';
            if (isPage && all.length >= 0) {
                listEl.setAttribute('data-notif-empty', 'No notifications');
            }
            renderListEl(listEl, all, { showDetail: isPage });
        });
    }

    function flashButton(btn, text) {
        if (!btn) return;
        var original = btn.textContent;
        btn.textContent = text;
        btn.disabled = true;
        setTimeout(function () {
            btn.textContent = original;
            btn.disabled = false;
        }, 600);
    }

    function bindPanelActions(refresh, latestGetter, countsGetter) {
        document.querySelectorAll('.alcros-notif-mark-read').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                setSeenAt(Date.now());
                refresh();
                flashButton(btn, 'Done');
            });
        });

        document.querySelectorAll('.alcros-notif-clear').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                function proceed() {
                    var now = Date.now();
                    visibleList(latestGetter()).forEach(function (n) {
                        if (!isPersistentAlert(n)) {
                            dismissId(n.id);
                        }
                    });
                    setClearedAt(now);
                    setBadgesAckAt(now);
                    setSeenAt(now);
                    refresh();
                    flashButton(btn, 'Cleared');
                }
                if (window.AlcrosConfirm) {
                    window.AlcrosConfirm.ask('Clear all notifications from this list?')
                        .then(function (ok) { if (ok) proceed(); });
                    return;
                }
                proceed();
            });
        });

        document.querySelectorAll('.alcros-notif-list').forEach(function (listEl) {
            listEl.addEventListener('click', function (e) {
                var link = e.target.closest('.notif-item a[href]');
                if (link) {
                    acknowledgeAllBadges(latestGetter(), countsGetter ? countsGetter() : null);
                    return;
                }

                var btn = e.target.closest('.notif-delete');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                function proceed() {
                    var id = btn.getAttribute('data-id');
                    var item = (latestGetter() || []).filter(function (n) { return n.id === id; })[0];
                    if (item && isPersistentAlert(item)) {
                        return;
                    }
                    dismissId(id);
                    refresh();
                }
                if (window.AlcrosConfirm) {
                    window.AlcrosConfirm.ask('Remove this notification?')
                        .then(function (ok) { if (ok) proceed(); });
                    return;
                }
                proceed();
            });
        });
    }

    function initHeaderDropdown(refresh, latestGetter) {
        var wrapper = document.getElementById('notif-wrapper');
        var bellBtn = document.getElementById('notif-bell-btn');
        var dropdown = document.getElementById('notif-dropdown');
        if (!wrapper || !bellBtn || !dropdown) return null;

        var isOpen = false;

        bellBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            isOpen = !isOpen;
            dropdown.classList.toggle('hidden', !isOpen);
            bellBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            if (isOpen) {
                setSeenAt(Date.now());
            }
            refresh();
        });

        document.addEventListener('click', function (e) {
            if (isOpen && !wrapper.contains(e.target)) {
                isOpen = false;
                dropdown.classList.add('hidden');
                bellBtn.setAttribute('aria-expanded', 'false');
                refresh();
            }
        });

        return function () { return isOpen; };
    }

    function showInitialSkeletons() {
        if (!window.AlcrosLoading || typeof window.AlcrosLoading.skeletonInto !== 'function') {
            return;
        }
        document.querySelectorAll('.alcros-notif-list').forEach(function (listEl) {
            window.AlcrosLoading.skeletonInto(listEl, 'list', listEl.id === 'notif-page-list' ? 6 : 4);
        });
    }

    function init() {
        if (!window.AlcrosPoll) return;

        showInitialSkeletons();

        var latest = [];
        var latestCounts = { pending_requests: 0, pending_appointments: 0 };

        initHeaderDropdown(refresh, function () { return latest; });

        function refresh() {
            renderAllLists(latest);
            updateAllBadges(latest, latestCounts);
        }

        bindPanelActions(refresh, function () { return latest; }, function () { return latestCounts; });

        function applyPayload(data) {
            latest = (data && data.notifications) || [];
            latestCounts = (data && data.counts) || latestCounts;
            refresh();
        }

        document.addEventListener('alcros:admin-live', function (e) {
            applyPayload(e.detail || {});
        });

        window.AlcrosNotifications = {
            applyPayload: applyPayload,
            refresh: refresh
        };
    }

    document.addEventListener('DOMContentLoaded', init);
})();
