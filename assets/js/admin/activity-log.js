(function () {
    'use strict';

    function parseLogPayload(row) {
        var raw = row.getAttribute('data-activity-log');
        if (!raw) {
            return null;
        }
        try {
            return JSON.parse(raw);
        } catch (err) {
            return null;
        }
    }

    function refreshModalIcons(modal) {
        if (typeof lucide === 'undefined' || !modal) {
            return;
        }
        var nodes = modal.querySelectorAll('[data-lucide]');
        if (!nodes.length) {
            return;
        }
        lucide.createIcons({ nameAttr: 'data-lucide', nodes: Array.prototype.slice.call(nodes) });
    }

    function openActivityLogModal(payload) {
        var modal = document.getElementById('activityLogDetailModal');
        if (!modal || !payload) {
            return;
        }

        var titleEl = document.getElementById('activityLogDetailTitle');
        var subtitleEl = document.getElementById('activityLogDetailSubtitle');
        var whenEl = document.getElementById('activityLogDetailWhen');
        var staffEl = document.getElementById('activityLogDetailStaff');
        var staffIdEl = document.getElementById('activityLogDetailStaffId');
        var actionEl = document.getElementById('activityLogDetailAction');
        var detailsEl = document.getElementById('activityLogDetailDetails');
        var logIdEl = document.getElementById('activityLogDetailLogId');

        if (titleEl) {
            titleEl.textContent = payload.action || 'Activity';
        }
        if (subtitleEl) {
            subtitleEl.textContent = payload.whenFull || '';
        }
        if (whenEl) {
            var whenParts = [payload.whenDate, payload.whenTime].filter(Boolean);
            whenEl.textContent = whenParts.length ? whenParts.join(' · ') : (payload.whenFull || '—');
        }
        if (staffEl) {
            staffEl.textContent = payload.staffName || 'System';
        }
        if (staffIdEl) {
            if (payload.staffId) {
                staffIdEl.textContent = payload.staffId;
                staffIdEl.classList.remove('hidden');
            } else {
                staffIdEl.textContent = '';
                staffIdEl.classList.add('hidden');
            }
        }
        if (actionEl) {
            actionEl.textContent = payload.action || '—';
        }
        if (detailsEl) {
            var details = String(payload.details || '').trim();
            detailsEl.textContent = details !== '' ? details : 'No additional details were recorded for this action.';
            detailsEl.classList.toggle('activity-log-detail-modal__details--empty', details === '');
        }
        if (logIdEl) {
            logIdEl.textContent = payload.id ? ('Log #' + payload.id) : '';
        }

        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('activity-log-modal-open');
        refreshModalIcons(modal);

        var closeBtn = modal.querySelector('[data-activity-log-close]');
        if (closeBtn && typeof closeBtn.focus === 'function') {
            closeBtn.focus();
        }
    }

    function closeActivityLogModal() {
        var modal = document.getElementById('activityLogDetailModal');
        if (!modal) {
            return;
        }
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('activity-log-modal-open');
    }

    function bindActivityLogRows(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.activity-log-row[data-activity-log]').forEach(function (row) {
            if (row.dataset.activityLogBound === '1') {
                return;
            }
            row.dataset.activityLogBound = '1';
            row.addEventListener('click', function () {
                openActivityLogModal(parseLogPayload(row));
            });
            row.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') {
                    return;
                }
                e.preventDefault();
                openActivityLogModal(parseLogPayload(row));
            });
        });
    }

    function bindActivityLogModal() {
        var modal = document.getElementById('activityLogDetailModal');
        if (!modal) {
            return;
        }

        modal.querySelectorAll('[data-activity-log-close]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                e.preventDefault();
                closeActivityLogModal();
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') {
                return;
            }
            if (modal.classList.contains('hidden')) {
                return;
            }
            e.stopPropagation();
            closeActivityLogModal();
        });
    }

    function initActivityLogPage() {
        bindActivityLogRows();
        bindActivityLogModal();
    }

    document.addEventListener('alcros:manage-list-updated', function (ev) {
        var detail = ev.detail || {};
        if (detail.panelId !== 'activityLogListPanel') {
            return;
        }
        var panel = document.getElementById('activityLogListPanel');
        bindActivityLogRows(panel || document);
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initActivityLogPage);
    } else {
        initActivityLogPage();
    }
})();
