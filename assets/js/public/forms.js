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
        var firstNameInput = document.getElementById('firstNameInput');
        var middleNameInput = document.getElementById('middleNameInput');
        var lastNameInput = document.getElementById('lastNameInput');
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

        if (checkBtn && firstNameInput && lastNameInput && dobInput) {
            checkBtn.addEventListener('click', function () {
                var firstName = firstNameInput.value.trim();
                var middleName = middleNameInput ? middleNameInput.value.trim() : '';
                var lastName = lastNameInput.value.trim();
                var dob = dobInput.value.trim();
                if (!firstName || !lastName) {
                    setRecordStatus('Enter your first name and last name on record first.', 'err');
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
                body.append('first_name', firstName);
                body.append('middle_name', middleName);
                body.append('last_name', lastName);
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

        if (firstNameInput) firstNameInput.addEventListener('input', resetRecordCheck);
        if (middleNameInput) middleNameInput.addEventListener('input', resetRecordCheck);
        if (lastNameInput) lastNameInput.addEventListener('input', resetRecordCheck);
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

    function initIdUploadPreview(ids) {
        (ids || ['idFront', 'idBack']).forEach(function (inputId) {
            var input = document.getElementById(inputId);
            if (!input) return;

            var label = input.closest('[data-id-upload]');
            if (!label) return;

            var empty = label.querySelector('[data-id-upload-empty]');
            var preview = label.querySelector('[data-id-upload-preview]');
            var img = label.querySelector('[data-id-upload-image]');
            var pdfNote = label.querySelector('[data-id-upload-pdf]');
            var caption = label.querySelector('[data-id-upload-caption]');

            function showEmpty() {
                label.classList.remove('has-preview');
                if (empty) empty.classList.remove('hidden');
                if (preview) preview.classList.add('hidden');
                if (img) {
                    img.removeAttribute('src');
                    img.classList.add('hidden');
                }
                if (pdfNote) pdfNote.classList.add('hidden');
                if (caption) caption.textContent = '';
            }

            function showPreview(file) {
                label.classList.add('has-preview');
                if (empty) empty.classList.add('hidden');
                if (preview) preview.classList.remove('hidden');
                if (caption) caption.textContent = file.name;

                var isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
                if (isPdf) {
                    if (img) img.classList.add('hidden');
                    if (pdfNote) pdfNote.classList.remove('hidden');
                    return;
                }

                if (pdfNote) pdfNote.classList.add('hidden');
                if (!img) return;

                img.classList.remove('hidden');
                var reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }

            input.addEventListener('change', function () {
                if (input.files && input.files[0]) {
                    showPreview(input.files[0]);
                } else {
                    showEmpty();
                }
            });
        });
    }

    function initFileInputLabels(ids) {
        initIdUploadPreview(ids);
    }

    window.AlcrosForms = {
        initGmailVerify: initGmailVerify,
        initCivilRecordCheck: initCivilRecordCheck,
        initFileInputLabels: initFileInputLabels,
        initIdUploadPreview: initIdUploadPreview
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
