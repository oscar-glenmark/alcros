(function () {
    'use strict';

    function readConfig() {
        if (!window.AlcrosPage || typeof window.AlcrosPage.readConfig !== 'function') {
            return null;
        }
        return window.AlcrosPage.readConfig('request-relationship-config');
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderRequirementsPanel(requirementsMap, docType, slug) {
        if (!requirementsMap || !docType || !slug) {
            return '';
        }
        var entry = requirementsMap[docType] && requirementsMap[docType][slug];
        if (!entry) {
            return '';
        }

        var html = '<div class="citizen-request-rel-req" data-rel-req-panel>';
        html += '<p class="citizen-request-rel-req__title">Required documents: <strong>' + escapeHtml(entry.label || '') + '</strong></p>';
        html += '<ul class="citizen-request-rel-req__list">';
        var lines = entry.lines || [];
        if (lines.length === 0) {
            html += '<li class="text-slate-500">No requirements listed.</li>';
        } else {
            lines.forEach(function (line) {
                html += '<li>' + escapeHtml(line) + '</li>';
            });
        }
        html += '</ul>';
        (entry.notes || []).forEach(function (note) {
            if (note) {
                html += '<p class="citizen-request-rel-req__note">' + escapeHtml(note) + '</p>';
            }
        });
        html += '</div>';

        return html;
    }

    function repopulateRelationshipSelect(select, catalog, docType, preserveValue) {
        if (!select || !catalog) return;

        var options = catalog[docType] || {};
        var keys = Object.keys(options);
        var current = preserveValue ? select.value : '';
        select.innerHTML = '';

        var placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.disabled = true;
        placeholder.selected = current === '';
        placeholder.textContent = 'Select relationship';
        select.appendChild(placeholder);

        keys.forEach(function (slug) {
            var opt = document.createElement('option');
            opt.value = slug;
            opt.textContent = options[slug];
            if (current === slug) {
                opt.selected = true;
                placeholder.selected = false;
            }
            select.appendChild(opt);
        });

        if (current !== '' && !options[current]) {
            select.value = '';
            placeholder.selected = true;
        }
    }

    function syncBlock(block, cfg) {
        if (!block || !cfg) return;

        var relSelect = block.querySelector('[data-requester-relationship]');
        var host = block.querySelector('[data-rel-req-host]');
        var docTypeSelect = document.getElementById('requestStartDocumentType')
            || document.getElementById('identificationDocumentType')
            || block.closest('form')?.querySelector('[name="document_type"]');

        function currentDocType() {
            if (docTypeSelect && docTypeSelect.value) {
                return docTypeSelect.value;
            }
            return block.getAttribute('data-relationship-doc-type') || '';
        }

        function updateFromDocType() {
            var docType = currentDocType();
            block.setAttribute('data-relationship-doc-type', docType);
            repopulateRelationshipSelect(relSelect, cfg.catalog, docType, true);
            updateRequirements();
        }

        function updateRequirements() {
            if (!host || !relSelect) return;
            var docType = currentDocType();
            var html = renderRequirementsPanel(cfg.requirements, docType, relSelect.value);
            host.innerHTML = html;
        }

        if (docTypeSelect) {
            docTypeSelect.addEventListener('change', function () {
                repopulateRelationshipSelect(relSelect, cfg.catalog, docTypeSelect.value, false);
                block.setAttribute('data-relationship-doc-type', docTypeSelect.value);
                updateRequirements();
            });
        }

        if (relSelect) {
            relSelect.addEventListener('change', updateRequirements);
        }

        updateFromDocType();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var cfg = readConfig();
        if (!cfg) return;

        document.querySelectorAll('#requesterRelationshipBlock').forEach(function (block) {
            syncBlock(block, cfg);
        });
    });
})();