(function (global) {
    'use strict';

    var overlayEl = null;

    function spinner(size) {
        size = size || 'w-4 h-4';
        return '<svg class="' + size + ' animate-spin shrink-0" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">' +
            '<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>' +
            '<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    }

    function ensureOverlay() {
        if (overlayEl) return overlayEl;
        overlayEl = document.createElement('div');
        overlayEl.id = 'alcros-loading-overlay';
        overlayEl.className = 'hidden fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900/25 backdrop-blur-[2px]';
        overlayEl.innerHTML =
            '<div class="bg-white rounded-2xl shadow-xl border border-slate-100 px-6 py-4 flex items-center gap-3">' +
            spinner('w-5 h-5 text-blue-600') +
            '<p class="alcros-loading-message text-sm font-semibold text-slate-700">Working…</p></div>';
        document.body.appendChild(overlayEl);
        return overlayEl;
    }

    function button(el, on, text) {
        if (!el) return;
        if (on) {
            if (!el.dataset.alcrosLoadingSaved) {
                el.dataset.alcrosLoadingSaved = '1';
                el.dataset.alcrosOrigHtml = el.innerHTML;
                el.dataset.alcrosOrigDisabled = el.disabled ? '1' : '0';
            }
            el.disabled = true;
            el.setAttribute('aria-busy', 'true');
            el.classList.add('opacity-80', 'pointer-events-none', 'cursor-wait');
            var label = text || el.dataset.loadingText || 'Please wait…';
            el.innerHTML = '<span class="inline-flex items-center justify-center gap-2">' + spinner() + '<span>' + label + '</span></span>';
        } else {
            el.removeAttribute('aria-busy');
            el.classList.remove('opacity-80', 'pointer-events-none', 'cursor-wait');
            if (el.dataset.alcrosOrigHtml) {
                el.innerHTML = el.dataset.alcrosOrigHtml;
            }
            el.disabled = el.dataset.alcrosOrigDisabled === '1';
            delete el.dataset.alcrosLoadingSaved;
            delete el.dataset.alcrosOrigHtml;
            delete el.dataset.alcrosOrigDisabled;
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
    }

    function page(on, message) {
        var el = ensureOverlay();
        var msg = el.querySelector('.alcros-loading-message');
        if (msg && message) msg.textContent = message;
        el.classList.toggle('hidden', !on);
    }

    function wrap(el, promise, text) {
        button(el, true, text);
        return Promise.resolve(promise).finally(function () {
            button(el, false);
        });
    }

    function preserveSubmitterValue(form, btn) {
        if (!btn || !btn.name || btn.type !== 'submit') return;
        var input = form.querySelector('input[type="hidden"][data-alcros-submit-value="' + btn.name + '"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = btn.name;
            input.setAttribute('data-alcros-submit-value', btn.name);
            form.appendChild(input);
        }
        input.value = btn.value || '';
    }

    function initForms() {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.noLoading !== undefined) return;

            var btn = e.submitter;
            if (!btn || !(btn instanceof HTMLElement)) {
                btn = form.querySelector('button[type="submit"], input[type="submit"]');
            }
            if (!btn || btn.disabled) return;

            if (btn.name === 'action' && btn.value === 'back') return;

            preserveSubmitterValue(form, btn);

            var loadingText = btn.dataset.loadingText ||
                (btn.name === 'action' && btn.value === 'next' ? 'Saving…' : 'Working…');

            // Do not disable the submitter here — some browsers abort the POST if the
            // clicked button is disabled during the submit event.
            if (form.dataset.loadingOverlay !== 'false') {
                page(true, loadingText);
            }
        }, false);
    }

    function initClickActions() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-loading-click]');
            if (!btn || btn.disabled) return;
            button(btn, true, btn.dataset.loadingText || 'Working…');
        });
    }

    global.AlcrosLoading = {
        button: button,
        page: page,
        wrap: wrap,
        spinner: spinner,
        init: function () {
            initForms();
            initClickActions();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', global.AlcrosLoading.init);
    } else {
        global.AlcrosLoading.init();
    }
})(window);
