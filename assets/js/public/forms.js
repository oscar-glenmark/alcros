(function () {
    'use strict';

    function fieldMeetsRequirement(el) {
        if (!el || el.disabled) return true;
        if (el.type === 'checkbox') {
            return !el.required || el.checked;
        }
        if (el.type === 'radio') {
            if (!el.required) return true;
            var form = el.form || document;
            var nodes = form.querySelectorAll('input[type="radio"][name="' + el.name.replace(/"/g, '\\"') + '"]');
            for (var i = 0; i < nodes.length; i++) {
                if (nodes[i].checked) return true;
            }
            return false;
        }
        if (el.type === 'file') {
            if (!el.required) return true;
            return !!(el.files && el.files.length);
        }
        if (el.tagName === 'SELECT') {
            return String(el.value || '').trim() !== '';
        }
        var value = String(el.value || '').trim();
        if (value === '') return false;
        if (el.pattern) {
            try {
                return new RegExp('^(?:' + el.pattern + ')$').test(value);
            } catch (err) {
                return true;
            }
        }
        return true;
    }

    function formFieldsReady(form) {
        if (!form) return false;
        var seenRadio = {};
        var ok = true;
        form.querySelectorAll('[required]').forEach(function (el) {
            if (el.type === 'radio') {
                if (seenRadio[el.name]) return;
                seenRadio[el.name] = true;
            }
            if (!fieldMeetsRequirement(el)) ok = false;
        });
        return ok;
    }

    function bindFormContinueGate(form, btn, extraReady) {
        if (!form || !btn) return function () {};

        function refresh() {
            var ready = formFieldsReady(form);
            if (typeof extraReady === 'function') {
                ready = ready && extraReady();
            }
            btn.disabled = !ready;
            btn.classList.toggle('is-locked', !ready);
            btn.setAttribute('aria-disabled', ready ? 'false' : 'true');
            var hintId = form.dataset.continueHint;
            if (hintId) {
                var hint = document.getElementById(hintId);
                if (hint) hint.classList.toggle('hidden', ready);
            }
        }

        form.addEventListener('input', refresh);
        form.addEventListener('change', refresh);
        refresh();
        return refresh;
    }

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
        var refreshGate = null;

        function setStatus(text, type) {
            if (!gmailStatus) return;
            gmailStatus.classList.remove('hidden');
            gmailStatus.textContent = text;
            gmailStatus.className = 'text-xs mt-2 font-semibold ' + (
                type === 'ok' ? 'text-green-600' : 'text-red-600'
            );
        }

        function isEmailVerified() {
            return emailVerified && emailVerified.value === '1';
        }

        function setVerified(ok, email) {
            if (emailVerified) emailVerified.value = ok ? '1' : '0';
            if (ok && email && gmailInput) gmailInput.value = email;
            if (refreshGate) refreshGate();
            else if (blockTarget) blockTarget.disabled = !ok;
        }

        if (form && blockTarget) {
            refreshGate = bindFormContinueGate(form, blockTarget, isEmailVerified);
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
                    return;
                }
                if (blockTarget && blockTarget.disabled) {
                    e.preventDefault();
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
        var domInput = document.getElementById('dateOfMarriageInput');
        var domWrap = document.getElementById('dateOfMarriageWrap');
        var documentTypeSelect = document.querySelector('#identificationForm select[name="document_type"]');
        var recordVerified = document.getElementById('recordVerified');
        var recordStatus = document.getElementById('recordStatus');
        var continueBtn = document.getElementById('step1ContinueBtn');
        var form = document.getElementById('identificationForm');
        var refreshGate = null;

        function syncMarriageField() {
            var isMarriage = documentTypeSelect && documentTypeSelect.value === 'marriage';
            if (domWrap) domWrap.classList.toggle('hidden', !isMarriage);
            if (domInput) {
                domInput.required = isMarriage;
                if (!isMarriage) domInput.value = '';
            }
        }

        if (documentTypeSelect) {
            documentTypeSelect.addEventListener('change', function () {
                syncMarriageField();
                resetRecordCheck();
            });
            syncMarriageField();
        }

        function setRecordStatus(text, type) {
            if (!recordStatus) return;
            recordStatus.classList.remove('hidden');
            recordStatus.textContent = text;
            recordStatus.className = 'text-xs mt-2 font-semibold ' + (
                type === 'ok' ? 'text-green-600' : 'text-red-600'
            );
        }

        function isRecordVerified() {
            return recordVerified && recordVerified.value === '1';
        }

        function setRecordVerified(ok) {
            if (recordVerified) recordVerified.value = ok ? '1' : '0';
            if (refreshGate) refreshGate();
            else if (continueBtn) continueBtn.disabled = !ok;
        }

        function resetRecordCheck() {
            setRecordVerified(false);
            if (recordStatus) recordStatus.classList.add('hidden');
        }

        if (form && continueBtn) {
            refreshGate = bindFormContinueGate(form, continueBtn, isRecordVerified);
        }

        if (checkBtn && firstNameInput && lastNameInput && dobInput) {
            checkBtn.addEventListener('click', function () {
                var firstName = firstNameInput.value.trim();
                var middleName = middleNameInput ? middleNameInput.value.trim() : '';
                var lastName = lastNameInput.value.trim();
                var dob = dobInput.value.trim();
                var documentType = documentTypeSelect ? documentTypeSelect.value : '';
                var dom = domInput ? domInput.value.trim() : '';
                if (!firstName || !lastName) {
                    setRecordStatus('Enter your first name and last name on record first.', 'err');
                    return;
                }
                if (!dob) {
                    setRecordStatus('Enter your date of birth first.', 'err');
                    return;
                }
                if (documentType === 'marriage' && !dom) {
                    setRecordStatus('Enter your date of marriage first.', 'err');
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
                if (documentType) body.append('document_type', documentType);
                if (documentType === 'marriage') body.append('date_of_marriage', dom);

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
        if (domInput) domInput.addEventListener('input', resetRecordCheck);

        if (form) {
            form.addEventListener('submit', function (e) {
                if (recordVerified && recordVerified.value !== '1') {
                    e.preventDefault();
                    setRecordStatus('Click Check Record before continuing.', 'err');
                    return;
                }
                if (continueBtn && continueBtn.disabled) {
                    e.preventDefault();
                }
            });
        }
    }

    function initScheduleSubmitGate() {
        var form = document.getElementById('requestScheduleForm');
        var submitBtn = document.getElementById('step3SubmitBtn');
        if (!form || !submitBtn) return;

        bindFormContinueGate(form, submitBtn);

        form.addEventListener('submit', function (e) {
            if (submitBtn.disabled) e.preventDefault();
        });
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
            var form = input.form;

            function notifyFormChange() {
                if (form) {
                    form.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

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
                notifyFormChange();
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
                    notifyFormChange();
                    return;
                }

                if (pdfNote) pdfNote.classList.add('hidden');
                if (!img) {
                    notifyFormChange();
                    return;
                }

                img.classList.remove('hidden');
                var reader = new FileReader();
                reader.onload = function (e) {
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
                notifyFormChange();
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
        initScheduleSubmitGate: initScheduleSubmitGate,
        initFileInputLabels: initFileInputLabels,
        initIdUploadPreview: initIdUploadPreview,
        bindFormContinueGate: bindFormContinueGate
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
        if (document.getElementById('requestScheduleForm')) initScheduleSubmitGate();
        if (document.getElementById('idFront') || document.getElementById('idBack')) {
            initFileInputLabels((document.body.dataset.fileInputLabels || 'idFront,idBack').split(','));
        }
    });
})();
