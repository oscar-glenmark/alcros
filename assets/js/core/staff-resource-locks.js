(function () {
    'use strict';

    var heartbeatTimer = null;
    var realtimeStop = null;
    var activeLock = null;
    var openClaimGeneration = 0;

    var LOCK_BANNER_IDS = [
        'view-lock-banner',
        'modal-view-lock-banner',
        'modal-request-header-lock',
        'appt-view-lock-banner',
        'modal-appt-view-lock-banner',
        'modal-appt-header-lock'
    ];

    function pageConfig() {
        if (window.AlcrosPage && typeof window.AlcrosPage.readConfig === 'function') {
            return window.AlcrosPage.readConfig('page-config') || {};
        }
        return {};
    }

    function lockType() {
        var cfg = pageConfig();
        var fromCfg = cfg.lockType || cfg.lock_type || '';
        if (fromCfg) {
            return fromCfg;
        }
        if (document.body) {
            return document.body.getAttribute('data-staff-lock-type') || '';
        }
        return '';
    }

    function locksApiPath() {
        return 'api/staff_locks.php';
    }

    function csrfValue() {
        var selectors = [
            '#requestActionAuthFields input[name="csrf_token"]',
            '#appointmentActionAuthFields input[name="csrf_token"]',
            '#alcros-staff-csrf input[name="csrf_token"]',
            'input[name="csrf_token"]'
        ];
        for (var i = 0; i < selectors.length; i++) {
            var el = document.querySelector(selectors[i]);
            if (el && el.value) {
                return el.value;
            }
        }
        return '';
    }

    function authToken() {
        if (window.AlcrosPoll && typeof window.AlcrosPoll.authToken === 'function') {
            return window.AlcrosPoll.authToken() || '';
        }
        try {
            return sessionStorage.getItem('alcros_auth') || '';
        } catch (e) {
            return '';
        }
    }

    function locksApiBase() {
        if (window.AlcrosPoll && typeof window.AlcrosPoll.buildUrl === 'function') {
            return window.AlcrosPoll.buildUrl(locksApiPath(), {});
        }
        return locksApiPath();
    }

    function postLock(action, resourceType, resourceId) {
        var url = locksApiBase();
        var form = new FormData();
        form.append('action', action);
        form.append('resource_type', resourceType);
        form.append('resource_id', String(resourceId));
        var csrf = csrfValue();
        if (csrf) {
            form.append('csrf_token', csrf);
        }
        var token = authToken();
        if (token) {
            form.append('alcros_auth', token);
        }
        if (window.AlcrosPoll && typeof window.AlcrosPoll.buildUrl === 'function') {
            url = window.AlcrosPoll.buildUrl(url, {});
        }
        var headers = {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        };
        if (csrf) {
            headers['X-CSRF-Token'] = csrf;
        }
        return fetch(url, {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: headers
        }).then(function (res) {
            return res.text().then(function (text) {
                var data = {};
                try {
                    data = text ? JSON.parse(text) : {};
                } catch (e) {
                    data = { ok: false, message: text || 'Invalid server response.' };
                }
                var out = { ok: res.ok, status: res.status, data: data };
                if (res.ok && (action === 'acquire' || action === 'release' || action === 'refresh')) {
                    notifyLockBump();
                }
                return out;
            });
        });
    }

    function notifyLockBump() {
        document.dispatchEvent(new CustomEvent('alcros:staff-locks-bump'));
        try {
            if (typeof BroadcastChannel === 'function') {
                var channel = new BroadcastChannel('alcros-staff-locks');
                channel.postMessage({ at: Date.now(), type: lockType() });
                channel.close();
            }
        } catch (e) { /* ignore */ }
    }

    var globalGuardInstalled = false;
    var realtimeSyncStarted = false;

    function parseRowLockData(row, meta) {
        if (!row || !meta) {
            return null;
        }
        var raw = row.getAttribute(meta.dataAttr);
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function installGlobalOpenGuard() {
        if (globalGuardInstalled) {
            return;
        }
        globalGuardInstalled = true;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.view-request-btn, .view-appointment-btn');
            if (!btn) {
                return;
            }
            if (btn.disabled || btn.classList.contains('is-lock-blocked')) {
                e.preventDefault();
                e.stopImmediatePropagation();
                return;
            }
            var row = btn.closest('.manage-requests-row');
            if (!row) {
                return;
            }
            if (row.getAttribute('data-lock-state') === 'other') {
                e.preventDefault();
                e.stopImmediatePropagation();
                var blocked = parseRowLockData(row, lockPageMeta());
                if (window.AlcrosActionResult) {
                    window.AlcrosActionResult.show('error', openBlockedMessage(blocked));
                }
                return;
            }
            var meta = lockPageMeta();
            var data = parseRowLockData(row, meta) || parseRowLockData(btn, meta);
            if (data && isOpenBlocked(data)) {
                e.preventDefault();
                e.stopImmediatePropagation();
                if (window.AlcrosActionResult) {
                    window.AlcrosActionResult.show('error', openBlockedMessage(data));
                }
            }
        }, true);
    }

    function installBroadcastSync(onBump) {
        try {
            if (typeof BroadcastChannel !== 'function' || typeof onBump !== 'function') {
                return;
            }
            var channel = new BroadcastChannel('alcros-staff-locks');
            channel.onmessage = function () {
                onBump();
            };
        } catch (e) { /* ignore */ }
    }

    function fetchBatchLocks(resourceType, ids) {
        if (!resourceType || !ids || !ids.length) {
            return Promise.resolve({});
        }
        var url = locksApiBase();
        if (window.AlcrosPoll && typeof window.AlcrosPoll.buildUrl === 'function') {
            url = window.AlcrosPoll.buildUrl(locksApiPath(), {
                action: 'batch',
                type: resourceType,
                ids: ids.join(',')
            });
        } else {
            url += (url.indexOf('?') >= 0 ? '&' : '?') + 'action=batch&type=' + encodeURIComponent(resourceType) + '&ids=' + encodeURIComponent(ids.join(','));
        }
        return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (res) { return res.json(); })
            .then(function (data) { return (data && data.locks) || {}; })
            .catch(function () { return {}; });
    }

    function isBlocked(lock) {
        return lock && lock.active && !lock.held_by_you;
    }

    function isOpenBlocked(data) {
        if (!data) {
            return false;
        }
        return isBlocked(data.lock);
    }

    function openBlockedMessage(data) {
        if (data && data.lock && data.lock.label) {
            return data.lock.label;
        }
        return 'This item is being handled by another staff member.';
    }

    function setRowOpenBusy(row, busy) {
        if (!row) {
            return;
        }
        row.querySelectorAll('.view-request-btn, .view-appointment-btn').forEach(function (btn) {
            btn.disabled = !!busy;
            btn.classList.toggle('is-lock-acquiring', !!busy);
        });
    }

    function lockLabel(lock) {
        if (!lock || !lock.active) {
            return '';
        }
        return lock.label || (lock.held_by_you ? 'You are handling this' : 'Being handled by another staff member');
    }

    function updateRowBadge(resourceId, lock) {
        var badge = document.querySelector('[data-lock-badge-for="' + resourceId + '"]');
        if (!badge) {
            return;
        }
        var text = lockLabel(lock);
        if (text) {
            badge.textContent = text;
            badge.classList.remove('hidden');
        } else {
            badge.textContent = '';
            badge.classList.add('hidden');
        }
    }

    function syncRowOpenButtons(resourceId, lock, rowSelector, btnSelector) {
        var row = document.querySelector(rowSelector + '="' + resourceId + '"]');
        if (!row) {
            return;
        }
        var buttons = row.querySelectorAll(btnSelector);
        var blocked = isBlocked(lock);
        if (blocked) {
            row.classList.add('is-lock-row-blocked');
            row.setAttribute('aria-disabled', 'true');
        } else {
            row.classList.remove('is-lock-row-blocked');
            row.removeAttribute('aria-disabled');
        }
        buttons.forEach(function (btn) {
            if (blocked) {
                btn.classList.add('is-lock-blocked');
                btn.setAttribute('aria-disabled', 'true');
                btn.disabled = true;
                btn.title = lockLabel(lock);
            } else if (!btn.classList.contains('is-lock-acquiring')) {
                btn.classList.remove('is-lock-blocked');
                btn.removeAttribute('aria-disabled');
                btn.disabled = false;
                if (btn.title === lockLabel(lock)) {
                    btn.title = '';
                }
            }
        });
    }

    function mergeLockIntoRow(row, lock, dataAttr) {
        if (!row || !dataAttr) {
            return;
        }
        var raw = row.getAttribute(dataAttr);
        if (!raw) {
            return;
        }
        try {
            var data = JSON.parse(raw);
            data.lock = lock || null;
            if (isBlocked(lock)) {
                data._lockReadOnly = true;
            } else {
                delete data._lockReadOnly;
            }
            row.setAttribute(dataAttr, JSON.stringify(data));
            var btn = row.querySelector('.view-request-btn, .view-appointment-btn');
            if (btn) {
                btn.setAttribute(dataAttr, JSON.stringify(data));
            }
        } catch (e) { /* ignore */ }
    }

    function clearLocalActiveIfStale(resourceId, lock) {
        if (!activeLock || parseInt(activeLock.id, 10) !== parseInt(resourceId, 10)) {
            return;
        }
        if (isBlocked(lock)) {
            clearHeartbeat();
            activeLock = null;
        }
    }

    function localHolderLock() {
        return { active: true, held_by_you: true, label: 'You are handling this' };
    }

    function resolveLockForDom(id, lock) {
        var idInt = parseInt(id, 10);
        if (activeLock && parseInt(activeLock.id, 10) === idInt && activeLock.type === lockType()) {
            if (isBlocked(lock)) {
                return lock;
            }
            if (!lock || !lock.active || lock.held_by_you) {
                return localHolderLock();
            }
        }
        return lock;
    }

    function applyLockToDom(id, lock, rowAttr, dataAttr) {
        lock = resolveLockForDom(id, lock);
        clearLocalActiveIfStale(id, lock);
        updateRowBadge(id, lock);
        if (rowAttr && dataAttr) {
            var row = document.querySelector('[' + rowAttr + '="' + id + '"]');
            if (row) {
                mergeLockIntoRow(row, lock, dataAttr);
                if (isBlocked(lock)) {
                    row.setAttribute('data-lock-state', 'other');
                } else if (lock && lock.active && lock.held_by_you) {
                    row.setAttribute('data-lock-state', 'you');
                } else {
                    row.removeAttribute('data-lock-state');
                }
            }
        }
        if (rowAttr === 'data-request-row') {
            syncRowOpenButtons(id, lock, '[data-request-row', '.view-request-btn');
        } else if (rowAttr === 'data-appointment-row') {
            syncRowOpenButtons(id, lock, '[data-appointment-row', '.view-appointment-btn');
        }
    }

    function applyLocksMap(locks, ids, rowAttr, dataAttr) {
        var map = locks || {};
        (ids || []).forEach(function (id) {
            var lock = map[String(id)] !== undefined ? map[String(id)] : (map[id] !== undefined ? map[id] : null);
            applyLockToDom(id, lock, rowAttr, dataAttr);
        });
        document.dispatchEvent(new CustomEvent('alcros:staff-locks-updated', {
            detail: { locks: map, ids: ids, rowAttr: rowAttr, dataAttr: dataAttr, type: lockType() }
        }));
    }

    function applyListLocks(items, idField, rowAttr, dataAttr) {
        var locks = {};
        var ids = [];
        (items || []).forEach(function (item) {
            if (!item || !item[idField]) {
                return;
            }
            ids.push(item[idField]);
            locks[String(item[idField])] = item.lock || null;
        });
        applyLocksMap(locks, ids, rowAttr, dataAttr);
    }

    function clearHeartbeat() {
        if (heartbeatTimer) {
            window.clearInterval(heartbeatTimer);
            heartbeatTimer = null;
        }
    }

    function startHeartbeat(resourceType, resourceId) {
        clearHeartbeat();
        if (!resourceType || !resourceId) {
            return;
        }
        heartbeatTimer = window.setInterval(function () {
            postLock('refresh', resourceType, resourceId).then(function (result) {
                var payload = result.data || {};
                if (payload.skipped || !result.ok) {
                    if (activeLock && parseInt(activeLock.id, 10) === parseInt(resourceId, 10)) {
                        clearHeartbeat();
                        activeLock = null;
                    }
                }
            }).catch(function () { /* ignore */ });
        }, 30000);
    }

    function hideAllLockBanners() {
        LOCK_BANNER_IDS.forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) {
                return;
            }
            el.textContent = '';
            el.classList.add('hidden');
            el.classList.remove('is-lock-other');
        });
    }

    function clearLockUiForSnapshot(snapshot, rowAttr, dataAttr) {
        if (snapshot && snapshot.id) {
            if (rowAttr && dataAttr) {
                applyLockToDom(snapshot.id, null, rowAttr, dataAttr);
            } else {
                var meta = lockPageMeta();
                if (meta) {
                    applyLockToDom(snapshot.id, null, meta.rowAttr, meta.dataAttr);
                }
            }
        }
        hideAllLockBanners();
    }

    function cancelPendingOpenClaim() {
        openClaimGeneration += 1;
    }

    function releaseActiveUnlessSame(resourceType, resourceId) {
        if (
            activeLock
            && activeLock.type === resourceType
            && parseInt(activeLock.id, 10) === parseInt(resourceId, 10)
        ) {
            return Promise.resolve();
        }
        return releaseActive(false, { cancelPendingClaim: false });
    }

    function beginOpenClaim(resourceType, resourceId, row, rowAttr, dataAttr) {
        var claimGeneration = openClaimGeneration;
        var pending = localHolderLock();
        activeLock = { type: resourceType, id: resourceId };
        applyLockToDom(resourceId, pending, rowAttr, dataAttr);
        setRowOpenBusy(row, true);

        function claimWasCancelled() {
            return claimGeneration !== openClaimGeneration;
        }

        function finishCancelledClaim(res) {
            setRowOpenBusy(row, false);
            clearHeartbeat();
            activeLock = null;
            applyLockToDom(resourceId, null, rowAttr, dataAttr);
            hideAllLockBanners();
            if (res && res.ok) {
                postLock('release', resourceType, resourceId).catch(function () { /* ignore */ });
                notifyLockBump();
            }
            return { ok: false, cancelled: true, readOnly: true, lock: null };
        }

        return releaseActiveUnlessSame(resourceType, resourceId).then(function () {
            if (claimWasCancelled()) {
                setRowOpenBusy(row, false);
                activeLock = null;
                applyLockToDom(resourceId, null, rowAttr, dataAttr);
                hideAllLockBanners();
                return { ok: false, cancelled: true, readOnly: true, lock: null };
            }
            return acquireForOpen(resourceType, resourceId);
        }).then(function (res) {
            if (claimWasCancelled()) {
                return finishCancelledClaim(res);
            }
            setRowOpenBusy(row, false);
            if (res.ok && res.lock) {
                applyLockToDom(resourceId, res.lock, rowAttr, dataAttr);
                notifyLockBump();
            } else if (res.lock) {
                clearHeartbeat();
                activeLock = null;
                applyLockToDom(resourceId, res.lock, rowAttr, dataAttr);
                notifyLockBump();
            } else if (!res.ok) {
                clearHeartbeat();
                activeLock = null;
                applyLockToDom(resourceId, null, rowAttr, dataAttr);
            }
            return res;
        }).catch(function () {
            setRowOpenBusy(row, false);
            clearHeartbeat();
            activeLock = null;
            applyLockToDom(resourceId, null, rowAttr, dataAttr);
            hideAllLockBanners();
            return { ok: false, readOnly: true, lock: null, error: 'Could not claim this item.' };
        });
    }

    function modalHeaderLockElement(idStem) {
        if (idStem === 'appt-view') {
            return document.getElementById('modal-appt-header-lock');
        }
        if (idStem === 'view') {
            return document.getElementById('modal-request-header-lock');
        }
        return null;
    }

    function applyLockBannerEl(banner, showYou, showOther, data) {
        if (!banner) {
            return;
        }
        banner.classList.remove('is-lock-other');
        if (showYou) {
            banner.textContent = (data.lock && data.lock.label) || 'You are handling this';
            banner.classList.remove('hidden');
            return;
        }
        if (showOther) {
            banner.textContent = data.lock.label || openBlockedMessage(data);
            banner.classList.add('is-lock-other');
            banner.classList.remove('hidden');
            return;
        }
        banner.textContent = '';
        banner.classList.add('hidden');
    }

    function syncDetailLockBanner(prefix, data, idStem) {
        if (!data || !data.id) {
            return;
        }
        idStem = idStem || 'view';
        var showYou = (data.lock && data.lock.active && data.lock.held_by_you)
            || (activeLock && parseInt(activeLock.id, 10) === parseInt(data.id, 10));
        var showOther = data.lock && isBlocked(data.lock);

        if (prefix === 'modal-') {
            applyLockBannerEl(modalHeaderLockElement(idStem), showYou, showOther, data);
            var bodyBanner = document.getElementById((prefix || '') + idStem + '-lock-banner');
            if (bodyBanner) {
                bodyBanner.textContent = '';
                bodyBanner.classList.add('hidden');
            }
            return;
        }

        applyLockBannerEl(document.getElementById((prefix || '') + idStem + '-lock-banner'), showYou, showOther, data);
        var headerBanner = modalHeaderLockElement(idStem);
        if (headerBanner) {
            headerBanner.textContent = '';
            headerBanner.classList.add('hidden');
        }
    }

    function releaseActive(useBeacon, options) {
        options = options || {};
        if (options.cancelPendingClaim !== false) {
            cancelPendingOpenClaim();
        }
        if (!activeLock) {
            hideAllLockBanners();
            return Promise.resolve();
        }
        var snapshot = activeLock;
        activeLock = null;
        clearHeartbeat();
        clearLockUiForSnapshot(snapshot);

        if (useBeacon && navigator.sendBeacon) {
            var body = new URLSearchParams();
            body.append('action', 'release');
            body.append('resource_type', snapshot.type);
            body.append('resource_id', String(snapshot.id));
            var csrf = csrfValue();
            if (csrf) {
                body.append('csrf_token', csrf);
            }
            var token = authToken();
            if (token) {
                body.append('alcros_auth', token);
            }
            navigator.sendBeacon(locksApiBase(), body);
            notifyLockBump();
            return Promise.resolve();
        }

        return postLock('release', snapshot.type, snapshot.id).then(function () {
            notifyLockBump();
        }).catch(function () { /* ignore */ });
    }

    function syncStaffPostAuth(formData) {
        if (!formData || typeof formData.set !== 'function') {
            return;
        }
        var csrf = csrfValue();
        if (csrf) {
            formData.set('csrf_token', csrf);
        }
        var token = authToken();
        if (token) {
            formData.set('alcros_auth', token);
        }
    }

    function acquireForOpen(resourceType, resourceId) {
        if (!resourceType || !resourceId) {
            return Promise.resolve({ ok: true, readOnly: false, lock: null });
        }

        return postLock('acquire', resourceType, resourceId).then(function (result) {
            var payload = result.data || {};
            if (payload.skipped) {
                return { ok: false, readOnly: true, lock: null, skipped: true };
            }
            if (result.status === 409 || payload.ok === false) {
                return {
                    ok: false,
                    readOnly: true,
                    lock: {
                        active: true,
                        held_by_you: false,
                        label: payload.error || payload.message || 'Being handled by another staff member.'
                    }
                };
            }
            if (!result.ok || payload.ok !== true) {
                return {
                    ok: false,
                    readOnly: true,
                    lock: null,
                    error: payload.message || payload.error || 'Could not claim this item. Refresh and try again.'
                };
            }
            activeLock = { type: resourceType, id: resourceId };
            startHeartbeat(resourceType, resourceId);
            return {
                ok: true,
                readOnly: false,
                lock: { active: true, held_by_you: true, label: 'You are handling this' }
            };
        });
    }

    function lockPageMeta() {
        var type = lockType();
        if (type === 'document_request') {
            return {
                rowAttr: 'data-request-row',
                dataAttr: 'data-request',
                rowSelector: '.manage-requests-row[data-request-row]'
            };
        }
        if (type === 'appointment') {
            return {
                rowAttr: 'data-appointment-row',
                dataAttr: 'data-appointment',
                rowSelector: '.manage-requests-row[data-appointment-row]'
            };
        }
        return null;
    }

    function pullLocksRevision(forceFull, rowAttr, dataAttr, getRowIds) {
        if (!window.AlcrosPoll || typeof getRowIds !== 'function') {
            return Promise.resolve();
        }
        var type = lockType();
        var ids = getRowIds();
        if (!type || !ids.length) {
            return Promise.resolve();
        }
        var params = {
            action: 'revision',
            type: type,
            ids: ids.join(',')
        };
        if (!forceFull && pullLocksRevision._revision) {
            params.since = pullLocksRevision._revision;
        }
        return fetch(window.AlcrosPoll.buildUrl(locksApiPath(), params), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json' }
        })
            .then(function (res) { return res.ok ? res.json() : null; })
            .then(function (data) {
                if (!data || data.ok === false) {
                    return;
                }
                if (data.revision) {
                    pullLocksRevision._revision = data.revision;
                }
                if (data.unchanged) {
                    if (window.AlcrosPoll.markLiveIndicator) {
                        window.AlcrosPoll.markLiveIndicator();
                    }
                    return;
                }
                if (data.locks) {
                    applyLocksMap(data.locks, ids, rowAttr, dataAttr);
                }
                if (window.AlcrosPoll.markLiveIndicator) {
                    window.AlcrosPoll.markLiveIndicator();
                }
            })
            .catch(function () { /* ignore */ });
    }

    function startRealtimeLockSync(getRowIds, rowAttr, dataAttr, intervalMs) {
        var type = lockType();
        if (!type || typeof getRowIds !== 'function' || !window.AlcrosPoll) {
            return function () { };
        }
        if (realtimeStop) {
            realtimeStop();
            realtimeStop = null;
        }
        pullLocksRevision._revision = '';

        var ms = intervalMs
            || (window.AlcrosPollConfig && AlcrosPollConfig.staffLocksMs)
            || 1000;

        realtimeStop = window.AlcrosPoll.pollJson(
            locksApiPath(),
            function (revision) {
                var ids = getRowIds();
                return {
                    action: 'revision',
                    type: type,
                    ids: ids.join(','),
                    since: revision || undefined
                };
            },
            ms,
            function (data) {
                if (data.locks) {
                    applyLocksMap(data.locks, getRowIds(), rowAttr, dataAttr);
                }
            },
            null,
            { pollInBackground: false, initialRevision: '' }
        );

        function onBump() {
            pullLocksRevision(true, rowAttr, dataAttr, getRowIds);
        }
        document.addEventListener('alcros:staff-locks-bump', onBump);

        pullLocksRevision(true, rowAttr, dataAttr, getRowIds);

        return function stop() {
            document.removeEventListener('alcros:staff-locks-bump', onBump);
            if (realtimeStop) {
                realtimeStop();
                realtimeStop = null;
            }
        };
    }

    function startListLockPoll(getRowIds, rowAttr, dataAttr) {
        return startRealtimeLockSync(getRowIds, rowAttr, dataAttr);
    }

    function autoInitRealtimeLocks() {
        var cfg = pageConfig();
        if (cfg.lockLive === false || !lockType()) {
            return;
        }
        var meta = lockPageMeta();
        if (!meta) {
            return;
        }
        var getRowIds = function () {
            return Array.prototype.map.call(
                document.querySelectorAll(meta.rowSelector),
                function (row) { return parseInt(row.getAttribute(meta.rowAttr), 10); }
            ).filter(function (id) { return id > 0; });
        };
        startRealtimeLockSync(getRowIds, meta.rowAttr, meta.dataAttr);
        installBroadcastSync(function () {
            pullLocksRevision(true, meta.rowAttr, meta.dataAttr, getRowIds);
        });
    }

    function bootstrapStaffLockPage() {
        installGlobalOpenGuard();
        if (realtimeSyncStarted || !lockType()) {
            return;
        }
        realtimeSyncStarted = true;
        autoInitRealtimeLocks();
    }

    window.AlcrosStaffLocks = {
        lockType: lockType,
        isBlocked: isBlocked,
        isOpenBlocked: isOpenBlocked,
        openBlockedMessage: openBlockedMessage,
        setRowOpenBusy: setRowOpenBusy,
        lockLabel: lockLabel,
        updateRowBadge: updateRowBadge,
        mergeLockIntoRow: mergeLockIntoRow,
        applyListLocks: applyListLocks,
        acquireForOpen: acquireForOpen,
        beginOpenClaim: beginOpenClaim,
        syncDetailLockBanner: syncDetailLockBanner,
        releaseActive: releaseActive,
        releaseActiveUnlessSame: releaseActiveUnlessSame,
        hideAllLockBanners: hideAllLockBanners,
        clearHeartbeat: clearHeartbeat,
        startListLockPoll: startListLockPoll,
        startRealtimeLockSync: startRealtimeLockSync,
        syncStaffPostAuth: syncStaffPostAuth,
        notifyLockBump: notifyLockBump,
        bootstrapStaffLockPage: bootstrapStaffLockPage,
        getActive: function () { return activeLock; }
    };
})();
