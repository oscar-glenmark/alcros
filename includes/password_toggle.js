(function (global) {
    'use strict';

    var EYE_SHOW = '<svg class="eye-show w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var EYE_HIDE = '<svg class="eye-hide w-4 h-4" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"></path><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"></path><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"></path><path d="m2 2 20 20"></path></svg>';

    function bindToggle(toggleBtn, passwordInput) {
        function setPasswordVisible(visible) {
            passwordInput.setAttribute('type', visible ? 'text' : 'password');
            toggleBtn.classList.toggle('is-revealed', visible);
            toggleBtn.setAttribute('aria-pressed', visible ? 'true' : 'false');
            toggleBtn.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        }

        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var hidden = passwordInput.getAttribute('type') !== 'text';
            setPasswordVisible(hidden);
        });
    }

    function createToggleButton() {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'alcros-password-toggle absolute right-3 top-1/2 -translate-y-1/2 z-10 inline-flex items-center justify-center p-1 text-gray-400 hover:text-gray-600';
        btn.setAttribute('aria-label', 'Show password');
        btn.setAttribute('aria-pressed', 'false');
        btn.innerHTML = EYE_SHOW + EYE_HIDE;
        return btn;
    }

    function enhancePasswordInput(input) {
        if (!(input instanceof HTMLInputElement) || input.type !== 'password') return;
        if (input.dataset.alcrosPasswordToggle === 'ready') return;

        var wrap = input.closest('.alcros-password-wrap');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'alcros-password-wrap relative';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
        }

        input.classList.add('pr-12');

        var toggleBtn = wrap.querySelector('.alcros-password-toggle');
        if (!toggleBtn) {
            toggleBtn = createToggleButton();
            wrap.appendChild(toggleBtn);
        }

        bindToggle(toggleBtn, input);
        input.dataset.alcrosPasswordToggle = 'ready';
    }

    function init(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var inputs = scope.querySelectorAll ? scope.querySelectorAll('input[type="password"]') : [];
        inputs.forEach(enhancePasswordInput);
    }

    global.AlcrosPasswordToggle = { init: init };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})(window);
