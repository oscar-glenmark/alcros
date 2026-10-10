(function () {
    'use strict';

    var cfg = (window.AlcrosPage && AlcrosPage.readConfig('request-success-config')) || {};
    var code = cfg.trackingCode || '';
    var btn = document.getElementById('copy-tracking-btn');
    if (btn && code) {
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
    }

    var cancelWrap = document.getElementById('citizen-success-cancel-wrap');
    var cancelBtn = document.getElementById('citizen-success-cancel-btn');
    if (!cancelWrap || !cancelBtn || !cfg.canCancel) {
        return;
    }

    var cancelType = cancelWrap.getAttribute('data-cancel-type') || cfg.cancelType || '';
    var cancelCode = cancelWrap.getAttribute('data-cancel-code') || code;
    var csrfEl = cancelWrap.querySelector('input[name="csrf_token"]');

    function askCitizenCancelConfirm(message) {
        if (window.AlcrosConfirm && typeof window.AlcrosConfirm.ask === 'function') {
            return window.AlcrosConfirm.ask(message);
        }
        return Promise.resolve(window.confirm(message));
    }

    cancelBtn.addEventListener('click', function () {
        var confirmMsg = cancelType === 'appointment'
            ? 'Cancel this appointment? Your time slot will be released and staff will not confirm this visit.'
            : 'Cancel this document request? Staff will stop processing it.';

        askCitizenCancelConfirm(confirmMsg).then(function (ok) {
            if (!ok) {
                return;
            }

            if (!csrfEl || !cancelCode) {
                window.alert('Unable to cancel from this page. Please refresh and try again.');
                return;
            }

            cancelBtn.disabled = true;
            cancelBtn.textContent = 'Cancelling…';

            var body = new FormData();
            body.append('csrf_token', csrfEl.value);
            body.append('type', cancelType);
            body.append('code', cancelCode);

            fetch('api/citizen_cancel.php', { method: 'POST', body: body, credentials: 'same-origin' })
                .then(function (r) {
                    return r.json().then(function (data) {
                        return { ok: r.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok || !result.data || !result.data.ok) {
                        throw new Error((result.data && result.data.error) || 'Cancellation failed.');
                    }
                    var modalText = result.data.status_message || result.data.message || 'Cancelled successfully.';
                    if (cancelBtn.parentNode) {
                        cancelBtn.remove();
                    }
                    if (window.AlcrosActionResult && typeof window.AlcrosActionResult.show === 'function') {
                        window.AlcrosActionResult.show('success', modalText, {
                            title: 'Cancellation complete',
                            badge: 'Success',
                            buttonLabel: 'Done'
                        });
                    } else {
                        var done = document.createElement('p');
                        done.className = 'citizen-success-cancel__done';
                        done.textContent = modalText;
                        cancelWrap.appendChild(done);
                    }
                })
                .catch(function (err) {
                    cancelBtn.disabled = false;
                    cancelBtn.textContent = cancelWrap.getAttribute('data-cancel-label') || 'Cancel';
                    window.alert(err.message || 'Unable to cancel. Please use Track or contact the office.');
                });
        });
    });
})();
