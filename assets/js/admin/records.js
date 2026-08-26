(function () {
    'use strict';

    var cfg = (window.AlcrosPage && AlcrosPage.readConfig('records-config')) || {};
    var csvTemplateColumns = cfg.csvTemplateColumns || {};
    var recordsAuthUrl = cfg.recordsAuthUrl || 'records.php';

    lucide.createIcons();

    const newEntryBtn = document.getElementById('newEntryBtn');
    const newEntryMenu = document.getElementById('newEntryMenu');
    const newEntryWrapper = document.getElementById('newEntryWrapper');

    function openModal(id) {
        const el = document.getElementById(id);
        el.classList.remove('hidden');
        el.classList.add('flex');
        lucide.createIcons();
    }

    function closeAllModals() {
        document.querySelectorAll('#entryModal, #importModal, #viewModal').forEach(el => {
            el.classList.add('hidden');
            el.classList.remove('flex');
        });
    }

    function formatDate(val) {
        if (val === null || val === undefined || val === '') return '—';
        const raw = String(val).substring(0, 10);
        const d = new Date(raw + 'T00:00:00');
        return Number.isNaN(d.getTime()) ? raw : d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }

    function displayValue(val) {
        if (val === null || val === undefined || String(val).trim() === '') return '—';
        return String(val);
    }

    function recordRegistryNumber(r) {
        const registry = (r.registry_number ?? '').toString().trim();
        if (registry !== '') return registry;
        const code = (r.code_number ?? '').toString().trim();
        return code !== '' ? code : '';
    }

    function recordEventDate(r) {
        if (r.record_type === 'birth') {
            return r.birth_date || r.event_date;
        }
        return r.event_date || r.birth_date;
    }

    newEntryBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        newEntryMenu.classList.toggle('hidden');
    });

    document.addEventListener('click', (e) => {
        if (!newEntryWrapper.contains(e.target)) newEntryMenu.classList.add('hidden');
    });

    document.getElementById('addSingleRecordBtn').addEventListener('click', () => {
        newEntryMenu.classList.add('hidden');
        setRecordType('birth');
        openModal('entryModal');
    });

    const recordTypeStyles = {
        birth: 'border-blue-500 bg-blue-50 text-blue-700',
        death: 'border-slate-400 bg-slate-50 text-slate-700',
        marriage: 'border-pink-400 bg-pink-50 text-pink-700'
    };
    const recordTypeIdle = 'border-gray-200 text-gray-500 hover:border-gray-300';

    function setRecordType(type) {
        document.getElementById('recordTypeInput').value = type;
        document.querySelectorAll('.record-type-tab').forEach(tab => {
            const active = tab.dataset.recordType === type;
            tab.className = 'record-type-tab rounded-xl border-2 px-3 py-3 text-center transition ' + (active ? recordTypeStyles[type] : recordTypeIdle);
        });

        const panels = {
            birth: document.getElementById('birthFieldsPanel'),
            death: document.getElementById('deathFieldsPanel'),
            marriage: document.getElementById('marriageFieldsPanel'),
        };
        Object.keys(panels).forEach(key => {
            const panel = panels[key];
            if (!panel) return;
            const active = key === type;
            panel.classList.toggle('hidden', !active);
            panel.querySelectorAll('input, select, textarea').forEach(el => { el.disabled = !active; });
        });

        document.getElementById('birthPersonName').required = type === 'birth';
        document.getElementById('deathPersonName').required = type === 'death';
        document.getElementById('marriagePersonName').required = type === 'marriage';
        if (type === 'birth') syncSingleBirthDetails();
        lucide.createIcons();
    }

    function syncPersonNameAcrossPanels(sourceId) {
        const map = {
            birthPersonName: ['deathPersonName', 'marriagePersonName'],
            deathPersonName: ['birthPersonName', 'marriagePersonName'],
            marriagePersonName: ['birthPersonName', 'deathPersonName'],
        };
        const source = document.getElementById(sourceId);
        if (!source) return;
        (map[sourceId] || []).forEach(id => {
            const target = document.getElementById(id);
            if (target && !target.disabled) target.value = source.value;
        });
    }

    function syncSingleBirthDetails() {
        const panel = document.getElementById('singleBirthDetails');
        const select = document.getElementById('birthTypeSelect');
        if (!panel || !select) return;
        const isSingle = select.value === 'Single';
        panel.classList.toggle('hidden', !isSingle);
        panel.querySelectorAll('input, select, textarea').forEach(el => {
            el.disabled = !isSingle || document.getElementById('birthFieldsPanel').classList.contains('hidden');
        });
    }

    document.querySelectorAll('.record-type-tab').forEach(tab => {
        tab.addEventListener('click', () => {
            const nextType = tab.dataset.recordType;
            const names = {
                birth: document.getElementById('birthPersonName'),
                death: document.getElementById('deathPersonName'),
                marriage: document.getElementById('marriagePersonName'),
            };
            const current = Object.entries(names).find(([key, el]) => el && el.value && !el.closest('.hidden'));
            if (current && names[nextType] && !names[nextType].value) {
                names[nextType].value = current[1].value;
            }
            setRecordType(nextType);
        });
    });

    document.getElementById('birthTypeSelect')?.addEventListener('change', syncSingleBirthDetails);
    document.getElementById('entryForm')?.addEventListener('submit', () => {
        const type = document.getElementById('recordTypeInput').value;
        setRecordType(type);
    });
    setRecordType(document.getElementById('recordTypeInput').value || 'birth');

    document.querySelectorAll('[data-import-type]').forEach(btn => {
        btn.addEventListener('click', () => {
            const type = btn.dataset.importType;
            newEntryMenu.classList.add('hidden');
            document.getElementById('importType').value = type;
            document.getElementById('importModalTitle').textContent = 'Import ' + type.charAt(0).toUpperCase() + type.slice(1) + ' Records';
            document.getElementById('importTemplateLink').href = recordsAuthUrl + (recordsAuthUrl.includes('?') ? '&' : '?') + 'action=template&type=' + type;
            document.getElementById('importTemplateLink').download = 'alcros_' + type + '_template.csv';
            const cols = csvTemplateColumns[type] || [];
            document.getElementById('importColumnsHelp').innerHTML =
                'Upload a CSV with the template headers for <strong>' + type + '</strong> records. Put each value in its own column — do not paste a whole row into cell A. Required: <strong>person_name</strong> (or <strong>husband_name</strong> + <strong>wife_name</strong> for marriage). Dates: YYYY-MM-DD or MM/DD/YYYY. Template sample rows are skipped automatically.<br><span class="text-[10px] text-gray-400 mt-1 inline-block">' + cols.join(', ') + '</span>';
            const fileInput = document.querySelector('#importForm input[name="csv_file"]');
            if (fileInput) fileInput.value = '';
            openModal('importModal');
        });
    });

    document.getElementById('importForm')?.addEventListener('submit', (e) => {
        if (!document.getElementById('importType').value) {
            e.preventDefault();
            alert('Please choose an import type from New Entry → Import.');
            return;
        }
        const submitBtn = e.target.querySelector('button[type="submit"]');
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Importing…';
        }
    });

    document.querySelectorAll('.close-modal').forEach(btn => btn.addEventListener('click', closeAllModals));
    ['entryModal', 'importModal', 'viewModal'].forEach(id => {
        document.getElementById(id)?.addEventListener('click', (e) => {
            if (e.target === e.currentTarget) closeAllModals();
        });
    });

    function formatAgeAtDeath(r) {
        const units = [
            ['age_death_years', 'y'],
            ['age_death_months', 'm'],
            ['age_death_days', 'd'],
            ['age_death_hours', 'h'],
            ['age_death_minutes', 'min'],
        ];
        const parts = units
            .filter(([key]) => r[key] !== null && r[key] !== '' && r[key] !== undefined)
            .map(([key, suffix]) => r[key] + suffix);
        let text = parts.length ? parts.join(' ') : '—';
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
            [label + " — Mother's Maiden Name", r[prefix + '_mother_maiden_name'] || '—'],
        ];
    }

    document.querySelectorAll('.view-record-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const r = JSON.parse(btn.dataset.record);
            let rows = [];

            if (r.record_type === 'birth') {
                rows = [
                    ['Type', displayValue(r.record_type)],
                    ['Registry Number', displayValue(recordRegistryNumber(r))],
                    ['Full Name', displayValue(r.person_name)],
                    ['Date of Birth', formatDate(r.birth_date)],
                    ['Sex', displayValue(r.sex)],
                    ['Time of Birth', displayValue(r.birth_time)],
                    ['Type of Birth', displayValue(r.birth_type)],
                    ['Birth Order', displayValue(r.birth_order)],
                    ['Place of Birth', displayValue(r.place)],
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
                    ['Name of Deceased', displayValue(r.person_name)],
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
                    ['Created', formatDate((r.created_at || '').substring(0, 10))],
                ];
            } else if (r.record_type === 'marriage') {
                rows = [
                    ['Type', displayValue(r.record_type)],
                    ['Registry Number', displayValue(recordRegistryNumber(r))],
                    ['Full Name', displayValue(r.person_name)],
                    ['Date of Birth', formatDate(r.birth_date)],
                    ...marriageSpouseRows(r, 'husband', 'Husband'),
                    ...marriageSpouseRows(r, 'wife', 'Wife'),
                    ['Date of Marriage', formatDate(recordEventDate(r))],
                    ['Time of Marriage', displayValue(r.marriage_time)],
                    ['Place of Marriage', displayValue(r.place)],
                    ['Solemnized By', displayValue(r.solemnized_by)],
                    ['Witnesses', displayValue(r.witnesses)],
                    ['Created', formatDate((r.created_at || '').substring(0, 10))],
                ];
            } else {
                rows = [
                    ['Type', displayValue(r.record_type)],
                    ['Registry Number', displayValue(recordRegistryNumber(r))],
                    ['Person Name', displayValue(r.person_name)],
                    ['Birth Date', formatDate(r.birth_date)],
                    ['Event Date', formatDate(recordEventDate(r))],
                    ['Place', displayValue(r.place)],
                    ['Created', formatDate((r.created_at || '').substring(0, 10))],
                ];
            }

            document.getElementById('viewContent').innerHTML = rows.map(([k, v]) =>
                '<div class="flex justify-between gap-4 border-b border-gray-50 pb-2"><span class="text-gray-400 text-xs font-bold uppercase">' + k + '</span><span class="text-slate-800 text-xs font-semibold text-right">' + String(v).replace(/</g, '&lt;') + '</span></div>'
            ).join('');
            document.getElementById('viewEditLink').href = (cfg.recordsAuthUrl || 'records.php') + (recordsAuthUrl.includes('?') ? '&' : '?') + 'edit=' + r.id;
            openModal('viewModal');
        });
    });

    if (cfg.openEntryModal) {
        document.addEventListener('DOMContentLoaded', function () {
            openModal('entryModal');
        });
    }
})();
