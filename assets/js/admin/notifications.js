(function () {
    'use strict';

    var STYLES = {
        pending_request: { icon: 'file-text', label: 'Pending request' },
        ready_pickup:    { icon: 'circle-check', label: 'Ready for pickup' },
        queue:           { icon: 'users', label: 'Queue' },
        appointment:     { icon: 'calendar', label: 'Appointment' },
        system:          { icon: 'alert-triangle', label: 'System' }
    };

    function staffRoot() {
        return document.getElementById('notif-wrapper') ||
            document.querySelector('[data-staff-id]') ||
            document.body;
    }

    function portalRole() {
        var el = staffRoot();
        var role = el ? String(el.getAttribute('data-staff-portal-role') || '').toLowerCase() : '';
        return role === 'administrator' ? 'administrator' : 'staff';
    }

    function filterForPortalRole(all, roleOverride) {
        var role = roleOverride || portalRole();
        var isAdmin = role === 'administrator';
        var operational = { pending_request: 1, ready_pickup: 1, queue: 1, appointment: 1 };

        return (all || []).filter(function (n) {
            var type = n && n.type ? String(n.type) : '';
            var isSystem = type === 'system' || (n.error_key && String(n.error_key).length > 0);
            if (isAdmin) {
                return isSystem;
            }
            return !isSystem && operational[type] === 1;
        });
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
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            /* private mode / quota */
        }
    }

    function getBellAckedIds() {
        var ids = loadJson(staffKey('bell_ack_ids'), null);
        return Array.isArray(ids) ? ids : [];
    }

    function ackBellId(id) {
        if (!id) return;
        var list = getBellAckedIds();
        if (list.indexOf(id) === -1) {
            list.push(id);
            saveJson(staffKey('bell_ack_ids'), list.slice(-500));
        }
    }

    function ackBellIds(ids) {
        var list = getBellAckedIds();
        var changed = false;
        (ids || []).forEach(function (id) {
            if (!id || list.indexOf(id) !== -1) return;
            list.push(id);
            changed = true;
        });
        if (changed) {
            saveJson(staffKey('bell_ack_ids'), list.slice(-500));
        }
    }

    function getClearedAt() {
        return parseInt(localStorage.getItem(staffKey('cleared')) || '0', 10) || 0;
    }

    function setClearedAt(ts) {
        try {
            localStorage.setItem(staffKey('cleared'), String(ts || Date.now()));
        } catch (e) {
            /* ignore */
        }
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

    function visibleList(all) {
        var clearedAt = getClearedAt();
        var dismissed = getDismissed();
        return (all || []).filter(function (n) {
            if (dismissed.indexOf(n.id) !== -1) return false;
            if (clearedAt && notifTime(n) <= clearedAt) return false;
            return true;
        });
    }

    function isBellUnread(n) {
        if (!n || !n.id) return false;
        return getBellAckedIds().indexOf(n.id) === -1;
    }

    function bellBadgeCount(all, counts) {
        var visible = visibleList(all);
        var unread = visible.filter(isBellUnread);
        if (portalRole() === 'administrator') {
            return unread.length;
        }
        var actions = actionCounts(counts);
        var pendingWork = actions.requests + actions.appointments;
        var operationalUnread = unread.filter(function (n) {
            return n.type === 'pending_request' || n.type === 'appointment';
        }).length;
        var otherUnread = unread.filter(function (n) {
            return n.type !== 'pending_request' && n.type !== 'appointment';
        }).length;
        var workUnread = Math.min(pendingWork, operationalUnread) + otherUnread;
        return workUnread;
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

    function actionCounts(counts) {
        return {
            requests: parseInt((counts || {}).pending_requests, 10) || 0,
            appointments: parseInt((counts || {}).pending_appointments, 10) || 0
        };
    }

    function updateBellBadges(all, counts) {
        var count = bellBadgeCount(all, counts);
        updateBadgeEl(document.getElementById('notif-badge'), count);
        updateBadgeEl(document.getElementById('sidebar-notif-badge'), count);
    }

    function sidebarTypeCount(all, counts, type, pendingKey) {
        var actions = actionCounts(counts);
        var total = actions[pendingKey] || 0;
        if (total <= 0) return 0;

        var typed = visibleList(all).filter(function (n) {
            return n.type === type;
        });
        if (!typed.length) {
            return total;
        }
        return typed.some(isBellUnread) ? total : 0;
    }

    function updateSidebarBadges(all, counts) {
        updateBadgeEl(
            document.getElementById('sidebar-request-badge'),
            sidebarTypeCount(all, counts, 'pending_request', 'requests')
        );
        updateBadgeEl(
            document.getElementById('sidebar-appt-badge'),
            sidebarTypeCount(all, counts, 'appointment', 'appointments')
        );
    }

    function updateAllBadges(all, counts) {
        updateBellBadges(all, counts);
        updateSidebarBadges(all, counts);
        updatePanelUnreadMeta(all, counts);
    }

    function updatePanelUnreadMeta(all, counts) {
        var visible = visibleList(all);
        var unread = visible.filter(isBellUnread).length;
        document.querySelectorAll('[data-notif-unread-count]').forEach(function (el) {
            el.textContent = String(unread);
        });
    }

    function renderEmptyState(listEl, title) {
        var hint = listEl.getAttribute('data-notif-empty-hint') || '';
        return '<div class="alcros-notif-empty" role="status">' +
            '<div class="alcros-notif-empty__icon"><i data-lucide="inbox"></i></div>' +
            '<p class="alcros-notif-empty__title">' + escapeHtml(title) + '</p>' +
            (hint ? '<p class="alcros-notif-empty__hint">' + escapeHtml(hint) + '</p>' : '') +
            '</div>';
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
            listEl.innerHTML = renderEmptyState(listEl, emptyText);
            if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
                lucide.createIcons({ nameAttr: 'data-lucide', nodes: listEl.querySelectorAll('[data-lucide]') });
            }
            return;
        }

        var showDetail = options.showDetail !== false;
        listEl.innerHTML = items.map(function (n) {
            var s = STYLES[n.type] || { icon: 'bell', label: 'Alert' };
            var typeKey = n.type ? String(n.type).replace(/[^a-z0-9_]/gi, '') : 'default';
            var href = buildHref(n.href);
            var readClass = isBellUnread(n) ? 'alcros-notif-item--unread' : 'alcros-notif-item--read';
            var detail = showDetail && n.detail
                ? '<p class="alcros-notif-item__detail">' + escapeHtml(n.detail) + '</p>'
                : '';

            var linkLabel = n.link_label
                ? '<p class="alcros-notif-item__cta">' + escapeHtml(n.link_label) + '</p>'
                : '';
            var roleNote = n.role_note
                ? '<p class="alcros-notif-item__note">' + escapeHtml(n.role_note) + '</p>'
                : '';

            var deleteBtn = '<button type="button" class="alcros-notif-item__dismiss notif-delete" data-id="' + escapeHtml(n.id) + '" title="Remove" aria-label="Remove notification">' +
                    '<i data-lucide="x"></i></button>';

            return '<article class="alcros-notif-item alcros-notif-item--' + escapeHtml(typeKey) + ' ' + readClass + ' notif-item" role="listitem" data-notif-id="' + escapeHtml(n.id) + '">' +
                '<a href="' + href + '" class="alcros-notif-item__link notif-item-link">' +
                '<span class="alcros-notif-item__unread" aria-hidden="true"></span>' +
                '<span class="alcros-notif-item__icon"><i data-lucide="' + s.icon + '"></i></span>' +
                '<span class="alcros-notif-item__body">' +
                '<span class="alcros-notif-item__row">' +
                '<span class="alcros-notif-item__type">' + escapeHtml(s.label) + '</span>' +
                '<time class="alcros-notif-item__time" datetime="' + escapeHtml(n.created_at || '') + '">' + formatTime(n.created_at) + '</time>' +
                '</span>' +
                '<h3 class="alcros-notif-item__title">' + escapeHtml(n.title) + '</h3>' +
                '<p class="alcros-notif-item__message">' + escapeHtml(n.message) + '</p>' +
                detail +
                linkLabel +
                roleNote +
                '</span>' +
                '</a>' +
                deleteBtn +
                '</article>';
        }).join('');

        if (typeof lucide !== 'undefined' && typeof lucide.createIcons === 'function') {
            var icons = listEl.querySelectorAll('[data-lucide]');
            if (icons.length) {
                lucide.createIcons({ nameAttr: 'data-lucide', nodes: Array.prototype.slice.call(icons) });
            }
        }
    }

    function renderAllLists(all) {
        document.querySelectorAll('.alcros-notif-list').forEach(function (listEl) {
            var isPage = listEl.id === 'notif-page-list';
            if (isPage && all.length >= 0) {
                var idleEmpty = listEl.getAttribute('data-notif-empty-idle') || 'No notifications';
                listEl.setAttribute('data-notif-empty', idleEmpty);
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

    function bindPanelActions(refresh, latestGetter) {
        document.querySelectorAll('.alcros-notif-mark-read').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                ackBellIds(visibleList(latestGetter()).map(function (n) { return n.id; }));
                refresh();
                flashButton(btn, 'Done');
            });
        });

        document.querySelectorAll('.alcros-notif-clear').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                function proceed() {
                    var items = visibleList(latestGetter());
                    var clearedAt = Date.now();
                    items.forEach(function (n) {
                        var t = notifTime(n);
                        if (!isNaN(t) && t > clearedAt) {
                            clearedAt = t;
                        }
                    });
                    var ids = items.map(function (n) { return n.id; }).filter(Boolean);
                    ids.forEach(function (id) { dismissId(id); });
                    setClearedAt(clearedAt);
                    ackBellIds(ids);
                    refresh();
                    flashButton(btn, 'Cleared');
                }
                if (window.AlcrosConfirm) {
                    var panel = btn.closest('.alcros-notif-panel');
                    var clearPrompt = (panel && panel.getAttribute('data-notif-clear-prompt')) || 'Clear all notifications from this list?';
                    window.AlcrosConfirm.ask(clearPrompt)
                        .then(function (ok) { if (ok) proceed(); });
                    return;
                }
                proceed();
            });
        });

        document.querySelectorAll('.alcros-notif-list').forEach(function (listEl) {
            listEl.addEventListener('click', function (e) {
                var link = e.target.closest('.notif-item-link');
                if (link) {
                    var item = link.closest('.notif-item');
                    var notifId = item ? item.getAttribute('data-notif-id') : '';
                    if (notifId) {
                        ackBellId(notifId);
                        refresh();
                    }
                    return;
                }

                var btn = e.target.closest('.notif-delete');
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                function proceed() {
                    var id = btn.getAttribute('data-id');
                    dismissId(id);
                    ackBellId(id);
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

    function initHeaderDropdown(refresh) {
        var wrapper = document.getElementById('notif-wrapper');
        var bellBtn = document.getElementById('notif-bell-btn');
        var dropdown = document.getElementById('notif-dropdown');
        if (!wrapper || !bellBtn || !dropdown) return;

        var isOpen = false;

        function setOpen(open) {
            isOpen = open;
            dropdown.classList.toggle('hidden', !isOpen);
            bellBtn.classList.toggle('is-open', isOpen);
            bellBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }

        bellBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            setOpen(!isOpen);
            if (isOpen) {
                refresh();
            }
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) {
                setOpen(false);
            }
        });

        document.addEventListener('click', function (e) {
            if (isOpen && !wrapper.contains(e.target)) {
                setOpen(false);
            }
        });
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
        if (window.__alcrosNotificationsInit) return;
        window.__alcrosNotificationsInit = true;

        showInitialSkeletons();

        var latest = [];
        var latestCounts = { pending_requests: 0, pending_appointments: 0 };

        initHeaderDropdown(refresh);

        function refresh() {
            renderAllLists(latest);
            updateAllBadges(latest, latestCounts);
        }

        function ackIdsAndRefresh(ids) {
            var list = (ids || []).filter(Boolean);
            if (!list.length) {
                return;
            }
            ackBellIds(list);
            refresh();
            if (window.AlcrosAdminLive && typeof window.AlcrosAdminLive.refresh === 'function') {
                window.AlcrosAdminLive.refresh();
            }
        }

        function ackForRequest(trackingCode) {
            var code = String(trackingCode || '').trim();
            if (!code) {
                return;
            }
            ackIdsAndRefresh(['req-pending-' + code, 'req-ready-' + code]);
        }

        function ackForAppointment(appointmentCode) {
            var code = String(appointmentCode || '').trim();
            if (!code) {
                return;
            }
            ackIdsAndRefresh(['appt-' + code]);
        }

        function ackForQueueTicket(ticketId) {
            var id = parseInt(ticketId, 10);
            if (!id) {
                return;
            }
            ackIdsAndRefresh(['queue-ticket-' + id]);
        }

        bindPanelActions(refresh, function () { return latest; });

        function applyPayload(data) {
            var role = (data && data.portal_role) ? String(data.portal_role).toLowerCase() : portalRole();
            var next = filterForPortalRole((data && data.notifications) || [], role);
            var nextIds = {};
            next.forEach(function (n) {
                if (n && n.id) {
                    nextIds[n.id] = true;
                }
            });
            latest.forEach(function (n) {
                if (n && n.id && !nextIds[n.id]) {
                    ackBellId(n.id);
                }
            });
            latest = next;
            latestCounts = (data && data.counts) || latestCounts;
            refresh();
        }

        document.addEventListener('alcros:admin-live', function (e) {
            applyPayload(e.detail || {});
        });

        window.AlcrosNotifications = {
            applyPayload: applyPayload,
            refresh: refresh,
            ackBellId: ackBellId,
            ackForRequest: ackForRequest,
            ackForAppointment: ackForAppointment,
            ackForQueueTicket: ackForQueueTicket
        };

        if (window.AlcrosAdminLive && typeof window.AlcrosAdminLive.refresh === 'function') {
            window.AlcrosAdminLive.refresh();
        }
    }

    document.addEventListener('DOMContentLoaded', init);
})();
