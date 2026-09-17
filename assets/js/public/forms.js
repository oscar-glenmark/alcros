(function () {
    'use strict';

    function fieldContainer(el) {
        if (!el) return null;
        if (el.closest('[data-id-upload]')) {
            return el.closest('[data-id-upload]').parentElement;
        }
        if (el.closest('.grid')) {
            return el.closest('.grid').parentElement;
        }
        return el.parentElement;
    }

    function ensureFieldErrorEl(container) {
        if (!container) return null;
        var err = container.querySelector('.citizen-field-error');
        if (!err) {
            err = document.createElement('p');
            err.className = 'citizen-field-error hidden';
            err.setAttribute('role', 'alert');
            container.appendChild(err);
        }
        return err;
    }

    function clearFormValidation(form) {
        if (!form) return;
        form.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
        form.querySelectorAll('.citizen-field-error').forEach(function (el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
    }

    function setFieldError(el, message) {
        if (!el || !message) return;
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        var container = fieldContainer(el);
        var err = ensureFieldErrorEl(container);
        if (err) {
            err.textContent = message;
            err.classList.remove('hidden');
        }
    }

    function setGroupError(container, message) {
        if (!container || !message) return;
        var err = ensureFieldErrorEl(container);
        if (err) {
            err.textContent = message;
            err.classList.remove('hidden');
        }
        container.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.classList.add('is-invalid');
            el.setAttribute('aria-invalid', 'true');
        });
    }

    function scrollToFirstError(form) {
        var target = form.querySelector('.is-invalid, .citizen-field-error:not(.hidden)');
        if (target) {
            (target.closest('[data-id-upload]') || target).scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

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

    function validateRequiredFields(form, labels) {
        labels = labels || {};
        var ok = true;
        var seenRadio = {};

        form.querySelectorAll('[required]').forEach(function (el) {
            if (el.disabled) return;
            if (el.type === 'radio') {
                if (seenRadio[el.name]) return;
                seenRadio[el.name] = true;
            }
            if (fieldMeetsRequirement(el)) return;

            ok = false;
            var label = labels[el.name] || labels[el.id] || 'This field';
            if (el.type === 'file') {
                setFieldError(el, label + ' is required.');
            } else if (el.tagName === 'SELECT') {
                setFieldError(el, 'Select ' + label.toLowerCase() + '.');
            } else if (el.pattern && String(el.value || '').trim() !== '') {
                setFieldError(el, label + ' format is invalid.');
            } else {
                setFieldError(el, 'Enter ' + label.toLowerCase() + '.');
            }
        });

        return ok;
    }

    function initGmailVerify(options) {
        options = options || {};
        var verifyBtn = document.getElementById(options.verifyBtnId || 'verifyGmailBtn');
        var gmailInput = document.getElementById(options.inputId || 'gmailInput');
        var emailVerified = document.getElementById(options.verifiedId || 'emailVerified');
        var gmailStatus = document.getElementById(options.statusId || 'gmailStatus');
        var form = document.getElementById(options.formId || 'requirementsForm');

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
            if (ok && email && gmailInput) gmailInput.value = email;
        }

        if (verifyBtn && gmailInput) {
            verifyBtn.addEventListener('click', function () {
                var email = gmailInput.value.trim();
                if (!email) {
                    setFieldError(gmailInput, 'Enter your Gmail address first.');
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
                            gmailInput.classList.remove('is-invalid');
                        } else {
                            setVerified(false);
                            setStatus(data.error || 'Verification failed.', 'err');
                            setFieldError(gmailInput, data.error || 'This Gmail could not be verified.');
                        }
                    })
                    .catch(function () {
                        setVerified(false);
                        setStatus('Network error. Try again.', 'err');
                        setFieldError(gmailInput, 'Network error while verifying Gmail. Try again.');
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
                gmailInput.classList.remove('is-invalid');
            });
        }

        if (form) {
            form.addEventListener('submit', function (e) {
                clearFormValidation(form);
                var ok = validateRequiredFields(form, options.fieldLabels || {});

                if (gmailInput) {
                    var email = gmailInput.value.trim();
                    if (!email) {
                        setFieldError(gmailInput, 'Enter your Gmail address.');
                        setStatus('Enter your Gmail address.', 'err');
                        ok = false;
                    } else if (emailVerified && emailVerified.value !== '1') {
                        setFieldError(gmailInput, 'Click Verify Gmail before continuing.');
                        setStatus('Click Verify Gmail before continuing.', 'err');
                        ok = false;
                    }
                }

                if (form.id === 'bookAppointmentForm') {
                    ok = validateScheduleFields(form) && ok;
                }

                if (!ok) {
                    e.preventDefault();
                    scrollToFirstError(form);
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
        var form = document.getElementById('identificationForm');

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

        function setRecordVerified(ok) {
            if (recordVerified) recordVerified.value = ok ? '1' : '0';
        }

        function resetRecordCheck() {
            setRecordVerified(false);
            if (recordStatus) recordStatus.classList.add('hidden');
        }

        if (checkBtn && firstNameInput && lastNameInput && dobInput) {
            checkBtn.addEventListener('click', function () {
                clearFormValidation(form);
                var firstName = firstNameInput.value.trim();
                var middleName = middleNameInput ? middleNameInput.value.trim() : '';
                var lastName = lastNameInput.value.trim();
                var dob = dobInput.value.trim();
                var documentType = documentTypeSelect ? documentTypeSelect.value : '';
                var dom = domInput ? domInput.value.trim() : '';
                var ok = true;

                if (!documentType) {
                    setFieldError(documentTypeSelect, 'Select the document type you need.');
                    ok = false;
                }
                if (!firstName || !lastName) {
                    setGroupError(firstNameInput.closest('.grid').parentElement, 'Enter your first name and last name on record.');
                    ok = false;
                }
                if (!dob) {
                    setFieldError(dobInput, 'Enter your date of birth.');
                    ok = false;
                }
                if (documentType === 'marriage' && !dom) {
                    setFieldError(domInput, 'Enter your date of marriage.');
                    ok = false;
                }
                if (!ok) {
                    scrollToFirstError(form);
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
                clearFormValidation(form);
                var ok = true;
                var documentType = documentTypeSelect ? documentTypeSelect.value : '';

                if (!documentType) {
                    setFieldError(documentTypeSelect, 'Select the document type you need.');
                    ok = false;
                }
                if (!firstNameInput.value.trim() || !lastNameInput.value.trim()) {
                    setGroupError(firstNameInput.closest('.grid').parentElement, 'Enter your first name and last name on record.');
                    ok = false;
                }
                if (!dobInput.value.trim()) {
                    setFieldError(dobInput, 'Enter your date of birth.');
                    ok = false;
                }
                if (documentType === 'marriage' && domInput && !domInput.value.trim()) {
                    setFieldError(domInput, 'Enter your date of marriage.');
                    ok = false;
                }

                if (recordVerified && recordVerified.value !== '1') {
                    if (recordStatus && recordStatus.textContent && !recordStatus.classList.contains('hidden')) {
                        setRecordStatus(recordStatus.textContent, 'err');
                    } else {
                        setRecordStatus('Click Check Record to verify your LCRO registration before continuing.', 'err');
                    }
                    ok = false;
                }

                if (!ok) {
                    e.preventDefault();
                    scrollToFirstError(form);
                }
            });
        }
    }

    function validateScheduleFields(form) {
        var ok = true;
        var dateInput = form.querySelector('#appointmentDateInput');
        var timeInput = form.querySelector('#appointmentTimeInput');
        var statusEl = document.getElementById('slotAvailabilityStatus');
        var availability = form._alcrosSlotAvailability;

        if (dateInput && !dateInput.value.trim()) {
            setFieldError(dateInput, 'Choose a preferred date.');
            ok = false;
        }

        if (timeInput) {
            if (availability && !availability.bookable) {
                if (statusEl) {
                    statusEl.classList.remove('hidden');
                    statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
                }
                ok = false;
            } else if (timeInput.disabled || !timeInput.value.trim()) {
                setFieldError(timeInput, 'Choose an available time slot.');
                if (statusEl && (!statusEl.textContent || statusEl.classList.contains('hidden'))) {
                    statusEl.textContent = 'Choose an available time slot.';
                    statusEl.className = 'text-xs mt-2 font-semibold text-red-600';
                    statusEl.classList.remove('hidden');
                }
                ok = false;
            }
        }

        return ok;
    }

    function initScheduleSubmitGate() {
        var scheduleForm = document.getElementById('requestScheduleForm');
        if (!scheduleForm) return;

        scheduleForm.addEventListener('submit', function (e) {
            clearFormValidation(scheduleForm);
            var ok = validateScheduleFields(scheduleForm);
            if (!ok) {
                e.preventDefault();
                scrollToFirstError(scheduleForm);
            }
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
                input.classList.remove('is-invalid');

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
                reader.onload = function (ev) {
                    img.src = ev.target.result;
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
        initScheduleSubmitGate: initScheduleSubmitGate,
        initFileInputLabels: initFileInputLabels,
        initIdUploadPreview: initIdUploadPreview
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (document.getElementById('checkRecordBtn')) initCivilRecordCheck();
        if (document.getElementById('verifyGmailBtn')) {
            initGmailVerify({
                formId: document.body.dataset.gmailForm || (document.getElementById('requirementsForm') ? 'requirementsForm' : 'bookAppointmentForm'),
                fieldLabels: {
                    purpose: 'Purpose of request',
                    phone: 'Cellphone number',
                    id_front: 'Front side of your valid ID',
                    first_name: 'First name',
                    last_name: 'Last name',
                    service_type: 'Service type',
                    appointment_date: 'Preferred date',
                    appointment_time: 'Preferred time'
                }
            });
        }
        if (document.getElementById('requestScheduleForm')) {
            initScheduleSubmitGate();
        }
        if (document.getElementById('idFront') || document.getElementById('idBack')) {
            initFileInputLabels((document.body.dataset.fileInputLabels || 'idFront,idBack').split(','));
        }
    });
})();
