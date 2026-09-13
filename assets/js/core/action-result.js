(function (global) {
    'use strict';

    if (global.__alcrosActionResultModule) return;
    global.__alcrosActionResultModule = true;

    var modal = null;
    var iconWrapEl = null;
    var badgeEl = null;
    var titleEl = null;
    var messageEl = null;
    var okBtn = null;

    function readConfig() {
        var el = document.getElementById('alcros-action-result');
        if (!el) return null;
        try {
            var data = JSON.parse(el.textContent || '{}');
            if (data.type && data.message) return data;
        } catch (err) {
            return null;
        }
        return null;
    }

    function ensureModal() {
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'alcrosActionResultModal';
        modal.className = 'alcros-action-result-modal is-hidden';
        modal.setAttribute('role', 'alertdialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'alcrosActionResultTitle');
        modal.innerHTML =
            '<div class="alcros-action-result-modal__panel">' +
                '<div class="alcros-action-result-modal__hero">' +
                    '<div id="alcrosActionResultIconWrap" class="alcros-action-result-modal__icon-wrap alcros-action-result-modal__icon-wrap--success">' +
                        '<div class="alcros-action-result-modal__icon alcros-action-result-modal__icon--success">' +
                            '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>' +
                        '</div>' +
                    '</div>' +
                    '<p id="alcrosActionResultBadge" class="alcros-modal-badge alcros-modal-badge--success">Success</p>' +
                    '<h3 id="alcrosActionResultTitle" class="alcros-action-result-modal__title">Action Successful</h3>' +
                    '<p id="alcrosActionResultMessage" class="alcros-action-result-modal__message"></p>' +
                '</div>' +
                '<button type="button" id="alcrosActionResultOkBtn" class="alcros-action-result-modal__btn alcros-action-result-modal__btn--success">Done</button>' +
            '</div>';

        document.body.appendChild(modal);

        iconWrapEl = modal.querySelector('#alcrosActionResultIconWrap');
        badgeEl = modal.querySelector('#alcrosActionResultBadge');
        titleEl = modal.querySelector('#alcrosActionResultTitle');
        messageEl = modal.querySelector('#alcrosActionResultMessage');
        okBtn = modal.querySelector('#alcrosActionResultOkBtn');

        okBtn.addEventListener('click', function (e) {
            e.preventDefault();
            closeModal();
        });
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
        });

        return modal;
    }

    function applyType(type) {
        var isSuccess = type === 'success';
        var tone = isSuccess ? 'success' : 'error';
        iconWrapEl.className = 'alcros-action-result-modal__icon-wrap alcros-action-result-modal__icon-wrap--' + tone;
        iconWrapEl.innerHTML =
            '<div class="alcros-action-result-modal__icon alcros-action-result-modal__icon--' + tone + '">' +
                (isSuccess
                    ? '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="20 6 9 17 4 12"></polyline></svg>'
                    : '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>') +
            '</div>';
        if (badgeEl) {
            badgeEl.className = 'alcros-modal-badge alcros-modal-badge--' + tone;
            badgeEl.textContent = isSuccess ? 'Success' : 'Error';
        }
        titleEl.textContent = isSuccess ? 'Action Successful' : 'Action Failed';
        okBtn.textContent = isSuccess ? 'Done' : 'Try Again';
        okBtn.className = 'alcros-action-result-modal__btn alcros-action-result-modal__btn--' + tone;
    }

    function openModal(type, message) {
        ensureModal();
        if (global.AlcrosLoading && typeof global.AlcrosLoading.page === 'function') {
            global.AlcrosLoading.page(false);
        }
        applyType(type);
        messageEl.textContent = message || (type === 'success' ? 'The action completed successfully.' : 'The action could not be completed.');
        document.body.appendChild(modal);
        modal.classList.remove('is-hidden');
        modal.classList.add('is-open');
        if (okBtn) okBtn.focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('is-hidden');
        modal.classList.remove('is-open');
    }

    function show(type, message) {
        openModal(type === 'success' ? 'success' : 'error', message);
    }

    function initFromConfig() {
        if (global.__alcrosActionResultInit) return;
        global.__alcrosActionResultInit = true;

        var cfg = readConfig();
        if (cfg) show(cfg.type, cfg.message);
    }

    global.AlcrosActionResult = {
        show: show,
        close: closeModal
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFromConfig);
    } else {
        initFromConfig();
    }
})(window);
