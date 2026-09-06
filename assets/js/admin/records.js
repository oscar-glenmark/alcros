(function () {
    'use strict';

    var cfg = {};
    var csvTemplateColumns = {};
    var recordsAuthUrl = 'records.php';

    function readPageConfig() {
        if (window.AlcrosPage && typeof AlcrosPage.readConfig === 'function') {
            cfg = AlcrosPage.readConfig('records-config') || {};
        }
        csvTemplateColumns = cfg.csvTemplateColumns || {};
        recordsAuthUrl = cfg.recordsAuthUrl || 'records.php';
    }

    function refreshIcons() {
        if (window.lucide && typeof lucide.createIcons === 'function') {
            lucide.createIcons();
        }
    }

    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.classList.remove('hidden');
        if (id !== 'viewModal') {
            el.classList.add('flex');
        } else {
            el.setAttribute('aria-hidden', 'false');
            document.body.classList.add('records-view-modal-open');
        }
        refreshIcons();
    }

    function closeAllModals() {
        document.querySelectorAll('#entryModal, #importModal, #viewModal').forEach(function (el) {
            el.classList.add('hidden');
            el.classList.remove('flex');
            if (el.id === 'viewModal') {
                el.setAttribute('aria-hidden', 'true');
            }
        });
        document.body.classList.remove('records-view-modal-open');
    }

    function formatDate(val) {
        if (val === null || val === undefined || val === '') return '—';
        var raw = String(val).substring(0, 10);
        var d = new Date(raw + 'T00:00:00');
        return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function displayValue(val) {
        if (val === null || val === undefined || String(val).trim() === '') return '—';
        return String(val);
    }

    function formatPersonName(r) {
        if (!r) return '—';
        if (r.record_type === 'marriage') {
            var husband = (r.husband_name || '').trim();
            var wife = (r.wife_name || '').trim();
            if (husband && wife) return husband + ' & ' + wife;
        }
        return [
            (r.first_name || '').trim(),
            (r.middle_name || '').trim(),
            (r.last_name || '').trim()
        ].filter(Boolean).join(' ') || (r.person_name || '—');
    }

    function recordRegistryNumber(r) {
        var registry = (r.registry_number ?? '').toString().trim();
        if (registry !== '') return registry;
        var code = (r.code_number ?? '').toString().trim();
        return code !== '' ? code : '';
    }

    function recordEventDate(r) {
        if (r.record_type === 'birth') {
            return r.birth_date || r.event_date;
        }
        return r.event_date || r.birth_date;
    }

    var recordTypeStyles = {
        birth: 'border-blue-500 bg-blue-50 text-blue-700',
        death: 'border-slate-400 bg-slate-50 text-slate-700',
        marriage: 'border-pink-400 bg-pink-50 text-pink-700'
    };
    var recordTypeIdle = 'border-gray-200 text-gray-500 hover:border-gray-300';

    function syncNamePartsAcrossPanels(sourcePrefix, targetPrefix) {
        ['FirstName', 'MiddleName', 'LastName'].forEach(function (part) {
            var source = document.getElementById(sourcePrefix + part);
            var target = document.getElementById(targetPrefix + part);
            if (source && target && !target.value) target.value = source.value;
        });
    }

    function syncSingleBirthDetails() {
        var panel = document.getElementById('singleBirthDetails');
        var select = document.getElementById('birthTypeSelect');
        var birthPanel = document.getElementById('birthFieldsPanel');
        if (!panel || !select || !birthPanel) return;
        var isSingle = select.value === 'Single';
        var birthActive = !birthPanel.classList.contains('hidden');
        panel.classList.toggle('hidden', !isSingle);
        panel.querySelectorAll('input, select, textarea').forEach(function (el) {
            el.disabled = !isSingle || !birthActive;
        });
    }

    function setRecordType(type) {
        var recordTypeInput = document.getElementById('recordTypeInput');
        if (!recordTypeInput || !type) return;
        recordTypeInput.value = type;
        document.querySelectorAll('.record-type-tab').forEach(function (tab) {
            var active = tab.dataset.recordType === type;
            tab.className = 'record-type-tab rounded-xl border-2 px-3 py-3 text-center transition ' + (active ? (recordTypeStyles[type] || recordTypeIdle) : recordTypeIdle);
        });

        var useCompletePrintForm = !!cfg.entryUseCompletePrintForm;
        var panels = {
            birth: document.getElementById('birthFieldsPanel'),
            death: document.getElementById('deathFieldsPanel'),
            marriage: document.getElementById('marriageFieldsPanel')
        };
        var printFillPanels = {
            birth: document.getElementById('birthPrintFillPanel'),
            death: document.getElementById('deathPrintFillPanel'),
            marriage: document.getElementById('marriagePrintFillPanel')
        };

        if (!useCompletePrintForm) {
            Object.keys(panels).forEach(function (key) {
                var panel = panels[key];
                if (!panel) return;
                var active = key === type;
                panel.classList.toggle('hidden', !active);
                panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.disabled = !active;
                });
            });
        } else {
            document.querySelectorAll('.entry-detail-panel').forEach(function (panel) {
                panel.classList.add('hidden');
                panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                    el.disabled = true;
                });
            });
            var entryTitle = document.getElementById('entryModalTitle');
            if (entryTitle) {
                entryTitle.textContent = 'Add ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Record';
            }
        }

        Object.keys(printFillPanels).forEach(function (key) {
            var panel = printFillPanels[key];
            if (!panel) return;
            var active = key === type;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !active;
            });
        });

        if (!useCompletePrintForm) {
            ['birthFirstName', 'birthMiddleName', 'birthLastName'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.required = type === 'birth';
            });
            ['deathFirstName', 'deathMiddleName', 'deathLastName'].forEach(function (id) {
                var el = document.getElementById(id);
                if (el) el.required = type === 'death';
            });
            if (type === 'birth') syncSingleBirthDetails();
        } else {
            document.querySelectorAll('.entry-detail-panel input, .entry-detail-panel select, .entry-detail-panel textarea').forEach(function (el) {
                el.required = false;
            });
        }
        refreshIcons();
    }

    function openSingleEntryModal(type) {
        type = type || cfg.defaultEntryType || 'birth';
        openModal('entryModal');
        setRecordType(type);
    }

    function openImportModal(type) {
        if (!type) return;
        var importType = document.getElementById('importType');
        var importModalTitle = document.getElementById('importModalTitle');
        var importTemplateLink = document.getElementById('importTemplateLink');
        var importColumnsHelp = document.getElementById('importColumnsHelp');
        if (!importType || !importModalTitle || !importTemplateLink || !importColumnsHelp) return;

        importType.value = type;
        importModalTitle.textContent = 'Import ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Records';
        importTemplateLink.href = recordsAuthUrl + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'action=template&type=' + encodeURIComponent(type) + '&v=3';
        importTemplateLink.download = 'alcros_' + type + '_import_template.csv';
        var cols = csvTemplateColumns[type] || [];
        var requiredHint = type === 'marriage'
            ? '<strong>husband_first_name</strong> + <strong>husband_last_name</strong> and <strong>wife_first_name</strong> + <strong>wife_last_name</strong>'
            : (type === 'death'
                ? '<strong>deceased_first_name</strong> and <strong>deceased_last_name</strong>'
                : '<strong>child_first_name</strong> and <strong>child_last_name</strong>');
        importColumnsHelp.innerHTML =
            'Upload a CSV using the same column names as the print certificate fill-in fields for <strong>' + type + '</strong>. Put each value in its own column — do not paste a whole row into cell A. Required: ' + requiredHint + ' (legacy columns such as first_name / last_name or husband_name / wife_name still work). Dates may use YYYY-MM-DD, MM/DD/YYYY, or separate day / month / year columns. Template sample rows are skipped automatically.<br><span class="text-[10px] text-gray-400 mt-1 inline-block">' + cols.join(', ') + '</span>';
        var fileInput = document.querySelector('#importForm input[name="csv_file"]');
        if (fileInput) fileInput.value = '';
        openModal('importModal');
    }

    function bindNewEntryMenu() {
        var newEntryBtn = document.getElementById('newEntryBtn');
        var newEntryMenu = document.getElementById('newEntryMenu');
        var newEntryWrapper = document.getElementById('newEntryWrapper');
        if (!newEntryBtn || !newEntryMenu || !newEntryWrapper) return;

        newEntryBtn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            newEntryMenu.classList.toggle('hidden');
        });

        newEntryWrapper.addEventListener('click', function (e) {
            var importBtn = e.target.closest('[data-import-type]');
            if (importBtn) {
                e.preventDefault();
                e.stopPropagation();
                newEntryMenu.classList.add('hidden');
                openImportModal(importBtn.getAttribute('data-import-type'));
                return;
            }
            if (e.target.closest('[data-single-entry-type]')) {
                e.preventDefault();
                e.stopPropagation();
                newEntryMenu.classList.add('hidden');
                openSingleEntryModal(e.target.closest('[data-single-entry-type]').getAttribute('data-single-entry-type'));
            }
        });

        document.addEventListener('click', function (e) {
            if (!newEntryWrapper.contains(e.target)) {
                newEntryMenu.classList.add('hidden');
            }
        });
    }

    function bindEntryPrintFillTabs() {
        document.querySelectorAll('.records-entry-print-fill').forEach(function (section) {
            section.querySelectorAll('[data-entry-fill-tab]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var side = btn.getAttribute('data-entry-fill-tab');
                    section.querySelectorAll('[data-entry-fill-tab]').forEach(function (tab) {
                        var active = tab.getAttribute('data-entry-fill-tab') === side;
                        tab.classList.toggle('is-active', active);
                        tab.setAttribute('aria-selected', active ? 'true' : 'false');
                    });
                    section.querySelectorAll('[data-entry-fill-panel]').forEach(function (panel) {
                        panel.hidden = panel.getAttribute('data-entry-fill-panel') !== side;
                    });
                });
            });
        });
    }

    function bindRecordTypeTabs() {
        document.querySelectorAll('.record-type-tab').forEach(function (tab) {
            tab.addEventListener('click', function () {
                var nextType = tab.dataset.recordType;
                var recordTypeInput = document.getElementById('recordTypeInput');
                var previousType = recordTypeInput ? recordTypeInput.value : '';
                if (previousType === 'birth' && nextType === 'death') {
                    syncNamePartsAcrossPanels('birth', 'death');
                } else if (previousType === 'death' && nextType === 'birth') {
                    syncNamePartsAcrossPanels('death', 'birth');
                }
                setRecordType(nextType);
            });
        });

        var birthTypeSelect = document.getElementById('birthTypeSelect');
        if (birthTypeSelect) birthTypeSelect.addEventListener('change', syncSingleBirthDetails);

        var entryForm = document.getElementById('entryForm');
        if (entryForm) {
            entryForm.addEventListener('submit', function () {
                var type = document.getElementById('recordTypeInput');
                if (type && type.value) setRecordType(type.value);
            });
        }
    }

    function bindImportForm() {
        var importForm = document.getElementById('importForm');
        if (!importForm) return;
        importForm.addEventListener('submit', function (e) {
            var importType = document.getElementById('importType');
            if (!importType || !importType.value) {
                e.preventDefault();
                alert('Please choose an import type from New Entry → Import.');
                return;
            }
            var submitBtn = e.target.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Importing…';
            }
        });
    }

    function bindModalClose() {
        document.querySelectorAll('.close-modal').forEach(function (btn) {
            btn.addEventListener('click', closeAllModals);
        });
        ['entryModal', 'importModal', 'viewModal'].forEach(function (id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            modal.addEventListener('click', function (e) {
                if (e.target === e.currentTarget) closeAllModals();
            });
        });
    }

    function formatAgeAtDeath(r) {
        var units = [
            ['age_death_years', 'y'],
            ['age_death_months', 'm'],
            ['age_death_days', 'd'],
            ['age_death_hours', 'h'],
            ['age_death_minutes', 'min']
        ];
        var parts = units
            .filter(function (entry) {
                var key = entry[0];
                return r[key] !== null && r[key] !== '' && r[key] !== undefined;
            })
            .map(function (entry) {
                return r[entry[0]] + entry[1];
            });
        var text = parts.length ? parts.join(' ') : '—';
        if (r.stillbirth == 1) text += (text === '—' ? '' : ' ') + '(Still-birth)';
        return text;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function detailRow(label, value, full) {
        var text = value === null || value === undefined ? '—' : String(value);
        var empty = text.trim() === '' || text === '—';
        if (empty) {
            text = '—';
        }
        var rowClass = 'records-detail-row' + (full ? ' records-detail-row--full' : '');
        var valueClass = empty ? ' records-detail-value--empty' : '';
        return '<div class="' + rowClass + '"><dt>' + escapeHtml(label) + '</dt><dd class="' + valueClass.trim() + '">' + escapeHtml(text) + '</dd></div>';
    }

    function detailSection(title, rows, fullLabels) {
        var fullSet = fullLabels || [];
        var rowHtml = rows.map(function (entry) {
            var full = fullSet.indexOf(entry[0]) !== -1;
            return detailRow(entry[0], entry[1], full);
        }).join('');
        return '<section class="records-detail-block"><h3>' + escapeHtml(title) + '</h3><dl class="records-detail-rows">' + rowHtml + '</dl></section>';
    }

    function recordTypeLabel(type) {
        var labels = { birth: 'Birth', death: 'Death', marriage: 'Marriage' };
        return labels[type] || displayValue(type);
    }

    function formatFieldValue(r, fieldDef) {
        var key = fieldDef.key;
        var format = fieldDef.format;

        if (key === '_person_name') {
            return displayValue(formatPersonName(r));
        }
        if (key === '_age_at_death') {
            return formatAgeAtDeath(r);
        }
        if (key === '_death_time') {
            var time = displayValue(r.death_time);
            if (time === '—') {
                return '—';
            }
            return time + (r.death_time_period ? ' ' + r.death_time_period : '');
        }
        if (format === 'date') {
            if (key === 'created_at') {
                return formatDate(String(r.created_at || '').substring(0, 10));
            }
            return formatDate(r[key]);
        }
        if (format === 'bool') {
            return r[key] == 1 ? 'Yes' : 'No';
        }

        return displayValue(r[key]);
    }

    function buildPrintFillSection(r, printValues) {
        var type = r.record_type || '';
        var labels = (cfg.printFillFieldLabels && cfg.printFillFieldLabels[type]) || {};
        var rows = [];

        Object.keys(labels).forEach(function (key) {
            var value = printValues && printValues[key] !== undefined ? printValues[key] : '';
            if (String(value || '').trim() === '') {
                return;
            }
            rows.push([labels[key], value]);
        });

        if (!rows.length) {
            return '';
        }

        return detailSection(
            'Print Certificate Fields',
            rows,
            rows.map(function (entry) { return entry[0]; })
        );
    }

    function buildViewRecordPresentation(r, printValues) {
        var sections = [];
        var type = r.record_type || '';
        var registry = displayValue(recordRegistryNumber(r));
        var eventDate = formatDate(recordEventDate(r));
        var created = formatDate(String(r.created_at || '').substring(0, 10));
        var title = displayValue(formatPersonName(r));
        var subtitleParts = [];
        var viewSections = (cfg.recordViewSections && cfg.recordViewSections[type]) || [];

        if (registry !== '—') {
            subtitleParts.push('Registry #' + registry);
        }
        if (eventDate !== '—') {
            subtitleParts.push(type === 'birth' ? 'Born ' + eventDate : type === 'death' ? 'Died ' + eventDate : 'Married ' + eventDate);
        }

        viewSections.forEach(function (section) {
            var rows = section.fields.map(function (field) {
                return [field.label, formatFieldValue(r, field), !!field.full];
            });
            var fullLabels = rows.filter(function (entry) { return entry[2]; }).map(function (entry) { return entry[0]; });
            sections.push(detailSection(
                section.title,
                rows.map(function (entry) { return [entry[0], entry[1]]; }),
                fullLabels
            ));
        });

        var printSection = buildPrintFillSection(r, printValues || {});
        if (printSection) {
            sections.push(printSection);
        }

        if (!viewSections.length) {
            sections.push(detailSection('Record', [
                ['Person Name', displayValue(formatPersonName(r))],
                ['Birth Date', formatDate(r.birth_date)],
                ['Event Date', formatDate(recordEventDate(r))],
                ['Place', displayValue(r.place)]
            ], ['Place']));
        }

        sections.push(detailSection('System', [
            ['Record Type', recordTypeLabel(type)],
            ['Registry Number', registry],
            ['Created', created]
        ]));

        return {
            title: title,
            subtitle: subtitleParts.join(' · ') || 'Civil registry record',
            badgeLabel: recordTypeLabel(type),
            badgeClass: 'records-type-badge records-type-badge--' + (type || 'birth'),
            html: sections.join('')
        };
    }

    function renderViewRecordPresentation(r, printValues) {
        var viewContent = document.getElementById('viewContent');
        var viewEditLink = document.getElementById('viewEditLink');
        var viewPrintLink = document.getElementById('viewPrintLink');
        var viewModalTitle = document.getElementById('viewModalTitle');
        var viewModalSubtitle = document.getElementById('viewModalSubtitle');
        var viewModalBadge = document.getElementById('viewModalBadge');
        if (!viewContent || !viewEditLink || !r || !r.id) {
            return;
        }

        var presentation = buildViewRecordPresentation(r, printValues);
        viewContent.innerHTML = presentation.html;
        if (viewModalTitle) {
            viewModalTitle.textContent = presentation.title;
        }
        if (viewModalSubtitle) {
            viewModalSubtitle.textContent = presentation.subtitle;
        }
        if (viewModalBadge) {
            viewModalBadge.textContent = presentation.badgeLabel;
            viewModalBadge.className = presentation.badgeClass;
        }
        viewEditLink.href = recordsAuthUrl + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'edit=' + r.id;
        if (viewPrintLink) {
            var printBase = cfg.printCertificateUrl || 'print_certificate.php';
            viewPrintLink.href = printBase + (printBase.indexOf('?') !== -1 ? '&' : '?') + 'record_id=' + r.id;
        }
    }

    function bindViewRecordButtons() {
        document.querySelectorAll('.view-record-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var r;
                try {
                    r = JSON.parse(btn.getAttribute('data-record') || '{}');
                } catch (err) {
                    return;
                }
                if (!r.id) {
                    return;
                }

                var viewContent = document.getElementById('viewContent');
                if (viewContent) {
                    viewContent.innerHTML = '<p class="records-detail-loading">Loading record details…</p>';
                }
                openModal('viewModal');

                var viewUrl = new URL(recordsAuthUrl, window.location.href);
                viewUrl.searchParams.set('action', 'view_record');
                viewUrl.searchParams.set('id', String(r.id));

                fetch(viewUrl.pathname + viewUrl.search, { credentials: 'same-origin' })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.ok || !data.record) {
                            throw new Error((data && data.error) || 'Could not load record.');
                        }
                        renderViewRecordPresentation(data.record, data.print_values || {});
                    })
                    .catch(function () {
                        renderViewRecordPresentation(r, {});
                    });
            });
        });
    }

    function bindRecordsSearch() {
        var input = document.getElementById('recordsSearchInput');
        var tbody = document.getElementById('recordsTableBody');
        var emptyRow = document.getElementById('recordsSearchEmpty');
        if (!input || !tbody) return;

        function filterRows() {
            var query = input.value.trim().toLowerCase();
            var rows = tbody.querySelectorAll('tr.records-table-row');
            var visible = 0;

            rows.forEach(function (row) {
                var haystack = (row.getAttribute('data-search') || '').toLowerCase();
                var show = query === '' || haystack.indexOf(query) !== -1;
                row.classList.toggle('hidden', !show);
                if (show) visible++;
            });

            if (emptyRow) {
                emptyRow.classList.toggle('hidden', visible > 0 || query === '');
            }
        }

        input.addEventListener('input', filterRows);
        filterRows();
    }

    function initRecordsPage() {
        readPageConfig();
        refreshIcons();
        bindNewEntryMenu();
        bindRecordTypeTabs();
        bindEntryPrintFillTabs();
        bindImportForm();
        bindModalClose();
        bindViewRecordButtons();
        bindRecordsSearch();

        var recordTypeInput = document.getElementById('recordTypeInput');
        if (recordTypeInput) {
            setRecordType(recordTypeInput.value || 'birth');
        }

        if (cfg.openEntryModal) {
            openSingleEntryModal(cfg.defaultEntryType || 'birth');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRecordsPage);
    } else {
        initRecordsPage();
    }
})();
