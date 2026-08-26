(function () {
    'use strict';

    var cfg = (window.AlcrosPage && AlcrosPage.readConfig('request-success-config')) || {};
    var code = cfg.trackingCode || '';
    var btn = document.getElementById('copy-tracking-btn');
    if (!btn || !code) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        var orig = btn.innerHTML;
        btn.innerHTML = 'Copying…';
        navigator.clipboard.writeText(code).then(function () {
            btn.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Copied!';
            if (typeof lucide !== 'undefined') lucide.createIcons();
            setTimeout(function () {
                btn.disabled = false;
                btn.innerHTML = orig;
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }, 2000);
        }).catch(function () {
            btn.disabled = false;
            btn.innerHTML = orig;
        });
    });
})();
