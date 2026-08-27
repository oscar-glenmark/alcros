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
        el.classList.add('flex');
        refreshIcons();
    }

    function closeAllModals() {
        document.querySelectorAll('#entryModal, #importModal, #viewModal').forEach(function (el) {
            el.classList.add('hidden');
            el.classList.remove('flex');
        });
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

        var panels = {
            birth: document.getElementById('birthFieldsPanel'),
            death: document.getElementById('deathFieldsPanel'),
            marriage: document.getElementById('marriageFieldsPanel')
        };
        Object.keys(panels).forEach(function (key) {
            var panel = panels[key];
            if (!panel) return;
            var active = key === type;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea').forEach(function (el) {
                el.disabled = !active;
            });
        });

        ['birthFirstName', 'birthMiddleName', 'birthLastName'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.required = type === 'birth';
        });
        ['deathFirstName', 'deathMiddleName', 'deathLastName'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.required = type === 'death';
        });
        if (type === 'birth') syncSingleBirthDetails();
        refreshIcons();
    }

    function openSingleEntryModal() {
        openModal('entryModal');
        setRecordType('birth');
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
        importTemplateLink.href = recordsAuthUrl + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'action=template&type=' + encodeURIComponent(type) + '&v=2';
        importTemplateLink.download = 'alcros_' + type + '_import_template.csv';
        var cols = csvTemplateColumns[type] || [];
        importColumnsHelp.innerHTML =
            'Upload a CSV with the template headers for <strong>' + type + '</strong> records. Put each value in its own column — do not paste a whole row into cell A. Required: <strong>first_name</strong> and <strong>last_name</strong> (or legacy <strong>person_name</strong> / <strong>full_name</strong>), or <strong>husband_name</strong> + <strong>wife_name</strong> for marriage. Dates: YYYY-MM-DD or MM/DD/YYYY. Template sample rows are skipped automatically.<br><span class="text-[10px] text-gray-400 mt-1 inline-block">' + cols.join(', ') + '</span>';
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
            if (e.target.closest('#addSingleRecordBtn')) {
                e.preventDefault();
                e.stopPropagation();
                newEntryMenu.classList.add('hidden');
                openSingleEntryModal();
            }
        });

        document.addEventListener('click', function (e) {
            if (!newEntryWrapper.contains(e.target)) {
                newEntryMenu.classList.add('hidden');
            }
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

    function marriageSpouseRows(r, prefix, label) {
        return [
            [label + ' — Name', r[prefix + '_name'] || '—'],
            [label + ' — Date of Birth', formatDate(r[prefix + '_birth_date'])],
            [label + ' — Age', r[prefix + '_age'] ?? '—'],
            [label + ' — Place of Birth', r[prefix + '_birth_place'] || '—'],
            [label + ' — Citizenship', r[prefix + '_citizenship'] || '—'],
            [label + ' — Religion', r[prefix + '_religion'] || '—'],
            [label + ' — Civil Status', r[prefix + '_civil_status'] || '—'],
            [label + ' — Residence', r[prefix + '_residence'] || '—'],
            [label + " — Father's Name", r[prefix + '_father_name'] || '—'],
            [label + " — Mother's Maiden Name", r[prefix + '_mother_maiden_name'] || '—']
        ];
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
                var rows = [];

                if (r.record_type === 'birth') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Full Name', displayValue(formatPersonName(r))],
                        ['Date of Birth', formatDate(r.birth_date)],
                        ['Sex', displayValue(r.sex)],
                        ['Time of Birth', displayValue(r.birth_time)],
                        ['Type of Birth', displayValue(r.birth_type)],
                        ['Birth Order', displayValue(r.birth_order)],
                        ['Place of Birth', displayValue(r.place)]
                    ];
                    if (r.birth_type === 'Single' || !r.birth_type) {
                        rows.push(
                            ['Mother', displayValue(r.mother_name)],
                            ['Mother Age', displayValue(r.mother_age)],
                            ['Mother Nationality', displayValue(r.mother_nationality)],
                            ['Mother Religion', displayValue(r.mother_religion)],
                            ['Father', displayValue(r.father_name)],
                            ['Father Age', displayValue(r.father_age)],
                            ['Father Nationality', displayValue(r.father_nationality)],
                            ['Father Religion', displayValue(r.father_religion)],
                            ['Parents Marriage Date', formatDate(r.parents_marriage_date)],
                            ['Parents Marriage Place', displayValue(r.parents_marriage_place)]
                        );
                    }
                    rows.push(['Created', formatDate((r.created_at || '').substring(0, 10))]);
                } else if (r.record_type === 'death') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Name of Deceased', displayValue(formatPersonName(r))],
                        ['Date of Birth', formatDate(r.birth_date)],
                        ['Sex', displayValue(r.sex)],
                        ['Date of Registration', formatDate(r.registration_date)],
                        ['Residence', displayValue(r.residence_deceased)],
                        ['Residence (Place of Death)', displayValue(r.residence_length_place)],
                        ['Residence (Philippines)', displayValue(r.residence_length_ph)],
                        ['Nationality', displayValue(r.nationality)],
                        ['Civil Status', displayValue(r.civil_status)],
                        ['Age at Death', formatAgeAtDeath(r)],
                        ['Occupation', displayValue(r.occupation)],
                        ['Surviving Spouse', displayValue(r.surviving_spouse_name)],
                        ['Spouse Address', displayValue(r.surviving_spouse_address)],
                        ['Place of Burial', displayValue(r.place_of_burial)],
                        ['Date of Death', formatDate(recordEventDate(r))],
                        ['Time of Death', (displayValue(r.death_time) === '—' ? '—' : displayValue(r.death_time) + (r.death_time_period ? ' ' + r.death_time_period : ''))],
                        ['Immediate Cause', displayValue(r.immediate_cause)],
                        ['Contributory Cause', displayValue(r.contributory_cause)],
                        ['Attending Physician', displayValue(r.attending_physician)],
                        ['Autopsy Performed', displayValue(r.autopsy_performed)],
                        ['Code Number', displayValue(r.code_number)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))]
                    ];
                } else if (r.record_type === 'marriage') {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Couple', displayValue(formatPersonName(r))],
                        ...marriageSpouseRows(r, 'husband', 'Husband'),
                        ...marriageSpouseRows(r, 'wife', 'Wife'),
                        ['Date of Marriage', formatDate(recordEventDate(r))],
                        ['Time of Marriage', displayValue(r.marriage_time)],
                        ['Place of Marriage', displayValue(r.place)],
                        ['Solemnized By', displayValue(r.solemnized_by)],
                        ['Witnesses', displayValue(r.witnesses)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))]
                    ];
                } else {
                    rows = [
                        ['Type', displayValue(r.record_type)],
                        ['Registry Number', displayValue(recordRegistryNumber(r))],
                        ['Person Name', displayValue(formatPersonName(r))],
                        ['Birth Date', formatDate(r.birth_date)],
                        ['Event Date', formatDate(recordEventDate(r))],
                        ['Place', displayValue(r.place)],
                        ['Created', formatDate((r.created_at || '').substring(0, 10))]
                    ];
                }

                var viewContent = document.getElementById('viewContent');
                var viewEditLink = document.getElementById('viewEditLink');
                if (!viewContent || !viewEditLink) return;

                viewContent.innerHTML = rows.map(function (entry) {
                    var k = entry[0];
                    var v = entry[1];
                    return '<div class="flex justify-between gap-4 border-b border-gray-50 pb-2"><span class="text-gray-400 text-xs font-bold uppercase">' + k + '</span><span class="text-slate-800 text-xs font-semibold text-right">' + String(v).replace(/</g, '&lt;') + '</span></div>';
                }).join('');
                viewEditLink.href = (cfg.recordsAuthUrl || 'records.php') + (recordsAuthUrl.indexOf('?') !== -1 ? '&' : '?') + 'edit=' + r.id;
                openModal('viewModal');
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
        bindImportForm();
        bindModalClose();
        bindViewRecordButtons();
        bindRecordsSearch();

        var recordTypeInput = document.getElementById('recordTypeInput');
        if (recordTypeInput) {
            setRecordType(recordTypeInput.value || 'birth');
        }

        if (cfg.openEntryModal) {
            openSingleEntryModal();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initRecordsPage);
    } else {
        initRecordsPage();
    }
})();
