(function (global) {
    'use strict';

    var modal = null;
    var iconWrapEl = null;
    var iconEl = null;
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
        modal.className = 'fixed inset-0 bg-black/40 z-[200] hidden items-center justify-center p-4';
        modal.setAttribute('role', 'alertdialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'alcrosActionResultTitle');
        modal.innerHTML =
            '<div class="bg-white rounded-2xl border border-gray-100 shadow-xl w-full max-w-sm p-6">' +
                '<div class="flex items-start gap-3 mb-5">' +
                    '<div id="alcrosActionResultIconWrap" class="p-2 rounded-xl shrink-0">' +
                        '<i id="alcrosActionResultIcon" data-lucide="check-circle" class="w-5 h-5"></i>' +
                    '</div>' +
                    '<div class="min-w-0">' +
                        '<h3 id="alcrosActionResultTitle" class="text-base font-black text-slate-900"></h3>' +
                        '<p id="alcrosActionResultMessage" class="text-sm text-gray-500 mt-1 leading-relaxed"></p>' +
                    '</div>' +
                '</div>' +
                '<button type="button" id="alcrosActionResultOkBtn" class="w-full rounded-xl py-2.5 text-sm font-bold text-white">OK</button>' +
            '</div>';

        document.body.appendChild(modal);

        iconWrapEl = modal.querySelector('#alcrosActionResultIconWrap');
        iconEl = modal.querySelector('#alcrosActionResultIcon');
        titleEl = modal.querySelector('#alcrosActionResultTitle');
        messageEl = modal.querySelector('#alcrosActionResultMessage');
        okBtn = modal.querySelector('#alcrosActionResultOkBtn');

        okBtn.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('flex')) closeModal();
        });

        return modal;
    }

    function applyType(type) {
        var isSuccess = type === 'success';
        iconWrapEl.className = 'p-2 rounded-xl shrink-0 ' + (isSuccess ? 'bg-emerald-50 text-emerald-600' : 'bg-rose-50 text-rose-600');
        iconEl.setAttribute('data-lucide', isSuccess ? 'check-circle' : 'alert-circle');
        titleEl.textContent = isSuccess ? 'Action Successful' : 'Action Failed';
        okBtn.className = 'w-full rounded-xl py-2.5 text-sm font-bold text-white ' + (isSuccess ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700');
    }

    function openModal(type, message) {
        ensureModal();
        applyType(type);
        messageEl.textContent = message || (type === 'success' ? 'The action completed successfully.' : 'The action could not be completed.');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        if (typeof lucide !== 'undefined') lucide.createIcons();
        if (okBtn) okBtn.focus();
    }

    function closeModal() {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    function show(type, message) {
        openModal(type === 'success' ? 'success' : 'error', message);
    }

    function initFromConfig() {
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
