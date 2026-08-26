(function () {
    'use strict';

    function initGmailVerify(options) {
        options = options || {};
        var verifyBtn = document.getElementById(options.verifyBtnId || 'verifyGmailBtn');
        var gmailInput = document.getElementById(options.inputId || 'gmailInput');
        var emailVerified = document.getElementById(options.verifiedId || 'emailVerified');
        var gmailStatus = document.getElementById(options.statusId || 'gmailStatus');
        var continueBtn = document.getElementById(options.continueBtnId || 'step2ContinueBtn');
        var submitBtn = document.getElementById(options.submitBtnId || 'bookSubmitBtn');
        var form = document.getElementById(options.formId || 'requirementsForm');
        var blockTarget = continueBtn || submitBtn;

        function setStatus(text, type) {
            if (!gmailStatus) return;
            gmailStatus.classList.remove('hidden');
            gmailStatus.textContent = text;
            gmailStatus.className = 'text-xs mt-2 font-semibold ' + (
                type === 'ok' ? 'text-green-600' : 'text-red-600'
            );
        }

        function setVerified(ok, email) {
            if (emailVerified) emailVerified.value = ok ? '1' : '0';
            if (blockTarget) blockTarget.disabled = !ok;
            if (ok && email && gmailInput) gmailInput.value = email;
        }

        if (verifyBtn && gmailInput) {
            verifyBtn.addEventListener('click', function () {
                var email = gmailInput.value.trim();
                if (!email) {
                    setStatus('Enter your Gmail address first.', 'err');
                    return;
                }
                setVerified(false);
                verifyBtn.disabled = true;
                verifyBtn.textContent = 'Checking…';

                var body = new FormData();
                body.append('email', email);

                fetch('api/verify_email.php', { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            setVerified(true, data.email);
                            setStatus(data.message || 'Gmail verified.', 'ok');
                        } else {
                            setVerified(false);
                            setStatus(data.error || 'Verification failed.', 'err');
                        }
                    })
                    .catch(function () {
                        setVerified(false);
                        setStatus('Network error. Try again.', 'err');
                    })
                    .finally(function () {
                        verifyBtn.disabled = false;
                        verifyBtn.textContent = 'Verify Gmail';
                    });
            });
        }

        if (gmailInput) {
            gmailInput.addEventListener('input', function () {
                setVerified(false);
                if (gmailStatus) gmailStatus.classList.add('hidden');
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                if (emailVerified && emailVerified.value !== '1') {
                    e.preventDefault();
                    setStatus('Click Verify Gmail before continuing.', 'err');
                }
            });
        }
    }

    function initCivilRecordCheck() {
        var checkBtn = document.getElementById('checkRecordBtn');
        var nameInput = document.getElementById('citizenNameInput');
        var dobInput = document.getElementById('dateOfBirthInput');
        var recordVerified = document.getElementById('recordVerified');
        var recordStatus = document.getElementById('recordStatus');
        var continueBtn = document.getElementById('step1ContinueBtn');
        var form = document.getElementById('identificationForm');

        function setRecordStatus(text, type) {
            if (!recordStatus) return;
            recordStatus.classList.remove('hidden');
            recordStatus.textContent = text;
            recordStatus.className = 'text-xs mt-2 font-semibold ' + (
                type === 'ok' ? 'text-green-600' : 'text-red-600'
            );
        }

        function setRecordVerified(ok) {
            if (recordVerified) recordVerified.value = ok ? '1' : '0';
            if (continueBtn) continueBtn.disabled = !ok;
        }

        function resetRecordCheck() {
            setRecordVerified(false);
            if (recordStatus) recordStatus.classList.add('hidden');
        }

        if (checkBtn && nameInput && dobInput) {
            checkBtn.addEventListener('click', function () {
                var name = nameInput.value.trim();
                var dob = dobInput.value.trim();
                if (!name) {
                    setRecordStatus('Enter your full name on record first.', 'err');
                    return;
                }
                if (!dob) {
                    setRecordStatus('Enter your date of birth first.', 'err');
                    return;
                }
                setRecordVerified(false);
                checkBtn.disabled = true;
                checkBtn.textContent = 'Checking…';

                var body = new FormData();
                body.append('citizen_name', name);
                body.append('date_of_birth', dob);

                fetch('api/check_civil_record.php', { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            setRecordVerified(true);
                            setRecordStatus(data.message || 'Record found.', 'ok');
                        } else {
                            setRecordVerified(false);
                            setRecordStatus(data.error || 'Record not found.', 'err');
                        }
                    })
                    .catch(function () {
                        setRecordVerified(false);
                        setRecordStatus('Network error. Try again.', 'err');
                    })
                    .finally(function () {
                        checkBtn.disabled = false;
                        checkBtn.textContent = 'Check Record';
                    });
            });
        }

        if (nameInput) nameInput.addEventListener('input', resetRecordCheck);
        if (dobInput) dobInput.addEventListener('input', resetRecordCheck);

        if (form) {
            form.addEventListener('submit', function (e) {
                if (recordVerified && recordVerified.value !== '1') {
                    e.preventDefault();
                    setRecordStatus('Click Check Record before continuing.', 'err');
                }
            });
        }
    }

    function initFileInputLabels(ids) {
        (ids || ['idFront', 'idBack']).forEach(function (id) {
            var input = document.getElementById(id);
            var label = document.getElementById(id + 'Label');
            if (input && label) {
                input.addEventListener('change', function () {
                    if (input.files && input.files[0]) {
                        label.textContent = input.files[0].name;
                    }
                });
            }
        });
    }

    window.AlcrosForms = {
        initGmailVerify: initGmailVerify,
        initCivilRecordCheck: initCivilRecordCheck,
        initFileInputLabels: initFileInputLabels
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('checkRecordBtn')) initCivilRecordCheck();
        if (document.getElementById('verifyGmailBtn')) {
            initGmailVerify({
                formId: document.body.dataset.gmailForm || 'requirementsForm',
                continueBtnId: document.body.dataset.gmailContinue || 'step2ContinueBtn',
                submitBtnId: document.body.dataset.gmailSubmit || 'bookSubmitBtn'
            });
        }
        if (document.getElementById('idFront') || document.getElementById('idBack')) {
            initFileInputLabels((document.body.dataset.fileInputLabels || 'idFront,idBack').split(','));
        }
    });
})();
