(function (global) {
    'use strict';

    function fieldIsEmpty(el) {
        if (!el) {
            return true;
        }
        var val = el.value;
        if (val === null || val === undefined) {
            return true;
        }
        return String(val).trim() === '';
    }

    function fieldContainer(el) {
        if (!el) {
            return null;
        }
        var printFillWrap = el.closest('.records-entry-print-fill__grid > div, .print-cert-fill-section-grid > div');
        if (printFillWrap) {
            return printFillWrap;
        }
        if (el.closest('.grid')) {
            return el.closest('.grid').parentElement;
        }
        return el.parentElement;
    }

    function ensureFieldErrorEl(container) {
        if (!container) {
            return null;
        }
        var err = container.querySelector('.records-field-error');
        if (!err) {
            err = document.createElement('p');
            err.className = 'records-field-error hidden';
            err.setAttribute('role', 'alert');
            container.appendChild(err);
        }
        return err;
    }

    function clearValidation(root) {
        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('.is-invalid').forEach(function (el) {
            el.classList.remove('is-invalid');
            el.removeAttribute('aria-invalid');
        });
        scope.querySelectorAll('.records-field-error').forEach(function (el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
    }

    function setFieldError(el, message) {
        if (!el || !message) {
            return;
        }
        el.classList.add('is-invalid');
        el.setAttribute('aria-invalid', 'true');
        var container = fieldContainer(el);
        var err = ensureFieldErrorEl(container);
        if (err) {
            err.textContent = message;
            err.classList.remove('hidden');
        }
    }

    function fieldErrorMessage(el, label) {
        label = label || 'This field';
        if (el.tagName === 'SELECT') {
            return 'Select ' + label.toLowerCase() + '.';
        }
        return 'Enter ' + label.toLowerCase() + '.';
    }

    function scrollToFirstError(root) {
        var scope = root && root.querySelector ? root : document;
        var invalid = scope.querySelector('.is-invalid');
        if (!invalid) {
            return;
        }
        if (typeof invalid.scrollIntoView === 'function') {
            invalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        if (typeof invalid.focus === 'function') {
            invalid.focus();
        }
    }

    function enabledFormField(form, name) {
        if (!form || !name) {
            return null;
        }
        var els = form.elements[name];
        if (!els) {
            return null;
        }
        if (typeof els.length === 'number' && els.length > 0) {
            for (var i = 0; i < els.length; i++) {
                if (!els[i].disabled) {
                    return els[i];
                }
            }
            return null;
        }
        return els.disabled ? null : els;
    }

    /**
     * @param {string} recordType birth|death|marriage
     * @param {Record<string, Array<{key: string, label: string}>>} requiredByType
     * @param {{ form?: HTMLFormElement, root?: Element, resolveInput?: function(string): Element|null }} options
     * @returns {boolean} true when validation failed (blocked)
     */
    function validateManualEntrySections(recordType, requiredByType, options) {
        options = options || {};
        var root = options.root || options.form || document;
        clearValidation(root);

        var type = String(recordType || '').trim();
        var required = requiredByType[type];
        if (!required || !required.length) {
            return false;
        }

        var resolve = options.resolveInput || function (fieldName) {
            if (options.form) {
                return enabledFormField(options.form, 'print_fill[' + fieldName + ']');
            }
            return null;
        };

        var blocked = false;
        required.forEach(function (spec) {
            var el = resolve(spec.key);
            if (!fieldIsEmpty(el)) {
                return;
            }
            blocked = true;
            if (el) {
                setFieldError(el, fieldErrorMessage(el, spec.label || spec.key));
            }
        });

        if (blocked) {
            scrollToFirstError(root);
        }
        return blocked;
    }

    function clearFieldValidationState(el) {
        if (!el || !el.matches('input, select, textarea')) {
            return;
        }
        el.classList.remove('is-invalid');
        el.removeAttribute('aria-invalid');
        var container = fieldContainer(el);
        if (!container) {
            return;
        }
        var err = container.querySelector('.records-field-error');
        if (err) {
            err.textContent = '';
            err.classList.add('hidden');
        }
    }

    function bindLiveClear(root) {
        if (!root || !root.addEventListener) {
            return;
        }
        root.addEventListener('input', function (e) {
            clearFieldValidationState(e.target);
        });
        root.addEventListener('change', function (e) {
            clearFieldValidationState(e.target);
        });
    }

    global.AlcrosCivilRecordEntryValidation = {
        validateManualEntrySections: validateManualEntrySections,
        clearValidation: clearValidation,
        clearFieldValidationState: clearFieldValidationState,
        bindLiveClear: bindLiveClear,
        scrollToFirstError: scrollToFirstError,
        fieldContainer: fieldContainer
    };
})(window);
