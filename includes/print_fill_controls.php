<?php

require_once __DIR__ . '/cascading_location.php';
require_once __DIR__ . '/print_field_definitions.php';
if (!function_exists('printFillFieldGroup')) {
    require_once __DIR__ . '/printing.php';
}

/** Render a print fill-in control (plain text or cascading location for birth place / residence). */
function renderPrintFillFieldInput(array $fillField, array $options = []): void
{
    $fieldName = (string) ($fillField['field_name'] ?? '');
    $value = (string) ($fillField['value'] ?? '');
    $inputClass = trim((string) ($options['input_class'] ?? ''));
    $inputName = trim((string) ($options['name'] ?? ''));
    $lcroClass = trim((string) ($options['lcro_class'] ?? 'print-cert-fill-field--lcro'));
    $isLocation = cascadingLocationUsesField($fieldName);
    $isLcro = printIsLcroFooterField($fieldName);
    $isPhBirth = cascadingLocationIsPhBirthPlaceField($fieldName);
    $isPhResidence = cascadingLocationIsPhResidenceField($fieldName);
    $isPhMarriage = cascadingLocationIsPhMarriagePlaceField($fieldName);

    $classes = $inputClass;
    if ($isLocation) {
        $classes = cascadingLocationInputClass($classes);
    }
    if ($isLcro && $lcroClass !== '') {
        $classes = trim($classes . ' ' . $lcroClass);
    }

    $placeholder = '';
    if ($isPhBirth) {
        $placeholder = 'Barangay, City/Municipality, Province';
    } elseif ($isPhMarriage) {
        $placeholder = 'City/Municipality, Province, Country';
    } elseif ($isPhResidence) {
        $placeholder = 'Barangay, City/Municipality, Province, Country';
    } elseif ($isLocation) {
        $placeholder = 'Barangay, City/Municipality, Province, Country';
    } elseif (preg_match('/_(middle_name|middlename)$/i', $fieldName)) {
        $placeholder = 'Optional';
    }
    ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars($value) ?>"
           class="<?= htmlspecialchars($classes) ?>"
           autocomplete="off"
           spellcheck="false"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?><?= cascadingLocationDataAttributes($fieldName) ?><?= $placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '' ?>>
    <?php
}

/** @return list<string> */
function printFillEntryFormSectionOrder(string $certificateType, string $pageSide): array
{
    if ($pageSide === 'back') {
        return match ($certificateType) {
            'birth' => ['paternity', 'delayed_birth', 'back_misc'],
            'death' => ['infant_section', 'postmortem', 'delayed_death', 'back_misc'],
            'marriage' => ['witness_affidavit', 'delayed_marriage', 'back_misc'],
            default => ['back_misc'],
        };
    }

    return match ($certificateType) {
        'birth' => [
            'office', 'child', 'mother', 'father', 'parents_marriage', 'attendant_certification',
            'informant', 'prepared_by', 'received_by', 'registrar', 'remarks', 'lcro_footer', 'front_misc',
        ],
        'death' => [
            'office', 'deceased', 'parents', 'medical_causes', 'attendant_certification', 'disposal_permits',
            'informant', 'prepared_by', 'received_by', 'registrar', 'remarks', 'lcro_footer', 'front_misc',
        ],
        'marriage' => [
            'office', 'husband', 'wife', 'marriage_ceremony', 'received_by', 'registrar', 'remarks', 'lcro_footer', 'front_misc',
        ],
        default => ['front_misc'],
    };
}

function printFillEntryFormSectionKey(string $certificateType, string $fieldName, string $pageSide): string
{
    if ($pageSide === 'back') {
        $group = printFillFieldGroup($fieldName);
        if ($group !== '') {
            return $group;
        }
        if (preg_match('/^witness_\d+$/', $fieldName) || str_starts_with($fieldName, 'affidavit_')) {
            return 'witness_affidavit';
        }

        return 'back_misc';
    }

    if (str_starts_with($fieldName, 'lcro_box_')) {
        return 'lcro_footer';
    }
    if ($fieldName === 'remarks_annotations') {
        return 'remarks';
    }
    if (in_array($fieldName, ['province', 'city_municipality', 'registry_number', 'book_number', 'page_number'], true)) {
        return 'office';
    }

    return match ($certificateType) {
        'birth' => match (true) {
            str_starts_with($fieldName, 'child_') => 'child',
            in_array($fieldName, ['sex', 'birth_day', 'birth_month', 'birth_year', 'birth_place', 'birth_type', 'multiple_birth_child_was', 'birth_order', 'birth_weight'], true) => 'child',
            str_starts_with($fieldName, 'mother_') => 'mother',
            str_starts_with($fieldName, 'father_') => 'father',
            str_starts_with($fieldName, 'parents_marriage_') => 'parents_marriage',
            str_starts_with($fieldName, 'attendant_') || $fieldName === 'birth_time' => 'attendant_certification',
            str_starts_with($fieldName, 'informant_') => 'informant',
            str_starts_with($fieldName, 'prepared_by_') => 'prepared_by',
            str_starts_with($fieldName, 'received_by_') => 'received_by',
            str_starts_with($fieldName, 'registrar_') => 'registrar',
            default => 'front_misc',
        },
        'death' => match (true) {
            str_starts_with($fieldName, 'deceased_') => 'deceased',
            in_array($fieldName, ['sex', 'death_day', 'death_month', 'death_year', 'birth_day', 'birth_month', 'birth_year', 'age_at_death', 'place_of_death', 'civil_status', 'religion', 'citizenship', 'residence', 'occupation', 'surviving_spouse_name', 'surviving_spouse_address', 'place_of_burial', 'death_time', 'registration_date'], true) => 'deceased',
            str_starts_with($fieldName, 'father_') || str_starts_with($fieldName, 'mother_') => 'parents',
            str_starts_with($fieldName, 'immediate_') || str_starts_with($fieldName, 'contributory_') || str_starts_with($fieldName, 'underlying_') || str_starts_with($fieldName, 'other_significant_') || str_starts_with($fieldName, 'maternal_condition_') || in_array($fieldName, ['autopsy_performed', 'attending_physician'], true) => 'medical_causes',
            str_starts_with($fieldName, 'attendant_') || str_starts_with($fieldName, 'death_cert_') || str_starts_with($fieldName, 'reviewed_by_') => 'attendant_certification',
            in_array($fieldName, ['corpse_disposal', 'burial_permit_number', 'burial_permit_date', 'transfer_permit_number', 'transfer_permit_date', 'cemetery_crematory'], true) => 'disposal_permits',
            str_starts_with($fieldName, 'informant_') => 'informant',
            str_starts_with($fieldName, 'prepared_by_') => 'prepared_by',
            str_starts_with($fieldName, 'received_by_') => 'received_by',
            str_starts_with($fieldName, 'registrar_') => 'registrar',
            default => 'front_misc',
        },
        'marriage' => match (true) {
            str_starts_with($fieldName, 'husband_') => 'husband',
            str_starts_with($fieldName, 'wife_') => 'wife',
            str_starts_with($fieldName, 'marriage_') || in_array($fieldName, ['solemnizing_officer', 'witnesses'], true) => 'marriage_ceremony',
            str_starts_with($fieldName, 'received_by_') => 'received_by',
            str_starts_with($fieldName, 'registrar_') => 'registrar',
            default => 'front_misc',
        },
        default => 'front_misc',
    };
}

/** @return array{label: string, icon: string, panel_class: string, heading_class: string, wrap: bool} */
function printFillEntryFormSectionPresentation(string $sectionKey): array
{
    static $sections = [
        'office' => [
            'label' => 'Registry Office',
            'icon' => 'landmark',
            'panel_class' => '',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => false,
        ],
        'child' => [
            'label' => "Child's Information",
            'icon' => 'baby',
            'panel_class' => 'rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'deceased' => [
            'label' => 'Deceased Information',
            'icon' => 'user',
            'panel_class' => 'rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'mother' => [
            'label' => "Mother's Information (Her)",
            'icon' => 'user',
            'panel_class' => 'rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'father' => [
            'label' => "Father's Information",
            'icon' => 'user',
            'panel_class' => 'rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'parents' => [
            'label' => 'Parent Names (Certificate)',
            'icon' => 'users',
            'panel_class' => 'rounded-xl border border-blue-100 bg-blue-50/40 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'parents_marriage' => [
            'label' => 'Marriage of Parents',
            'icon' => 'heart',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'husband' => [
            'label' => "Husband's Information",
            'icon' => 'user',
            'panel_class' => 'rounded-xl border border-blue-200 bg-blue-50/50 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-blue-800 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'wife' => [
            'label' => "Wife's Information",
            'icon' => 'user',
            'panel_class' => 'rounded-xl border border-pink-200 bg-pink-50/50 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-pink-800 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'marriage_ceremony' => [
            'label' => 'Marriage Ceremony',
            'icon' => 'heart',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'attendant_certification' => [
            'label' => 'Attendant / Certification',
            'icon' => 'stethoscope',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'medical_causes' => [
            'label' => 'Medical Certificate (Causes of Death)',
            'icon' => 'activity',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'disposal_permits' => [
            'label' => 'Burial / Disposal & Permits',
            'icon' => 'map-pin',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'informant' => [
            'label' => 'Informant',
            'icon' => 'message-square',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'prepared_by' => [
            'label' => 'Prepared By',
            'icon' => 'pen-line',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'received_by' => [
            'label' => 'Received By',
            'icon' => 'inbox',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'registrar' => [
            'label' => 'Civil Registrar',
            'icon' => 'stamp',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'remarks' => [
            'label' => 'Remarks / Annotations',
            'icon' => 'file-text',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'lcro_footer' => [
            'label' => 'LCRO Footer Boxes',
            'icon' => 'grid-3x3',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'paternity' => [
            'label' => 'Paternity Affidavit (Back Page)',
            'icon' => 'file-text',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'delayed_birth' => [
            'label' => 'Delayed Birth Affidavit (Back Page)',
            'icon' => 'file-text',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'delayed_death' => [
            'label' => 'Delayed Death Affidavit (Back Page)',
            'icon' => 'file-text',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'delayed_marriage' => [
            'label' => 'Delayed Marriage Affidavit (Back Page)',
            'icon' => 'file-text',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'infant_section' => [
            'label' => 'Infant 0–7 Days (Back Page)',
            'icon' => 'baby',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'postmortem' => [
            'label' => 'Postmortem / Autopsy (Back Page)',
            'icon' => 'microscope',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'witness_affidavit' => [
            'label' => 'Witnesses & Affidavit (Back Page)',
            'icon' => 'users',
            'panel_class' => 'rounded-xl border border-slate-200 bg-white/70 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-700 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'front_misc' => [
            'label' => 'Additional Print Fields',
            'icon' => 'plus-circle',
            'panel_class' => 'rounded-xl border border-dashed border-slate-300 bg-white/50 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-600 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
        'back_misc' => [
            'label' => 'Additional Back Page Fields',
            'icon' => 'plus-circle',
            'panel_class' => 'rounded-xl border border-dashed border-slate-300 bg-white/50 p-4 space-y-4',
            'heading_class' => 'text-[10px] font-black text-slate-600 uppercase tracking-wider flex items-center gap-2',
            'wrap' => true,
        ],
    ];

    return $sections[$sectionKey] ?? $sections['front_misc'];
}

/**
 * @param list<array<string, mixed>> $fields
 *
 * @return array<string, list<array<string, mixed>>>
 */
function groupPrintFillFieldsForEntryForm(string $certificateType, array $fields): array
{
    $grouped = [];
    foreach ($fields as $field) {
        $pageSide = (string) ($field['page_side'] ?? 'front');
        $sectionKey = printFillEntryFormSectionKey($certificateType, (string) ($field['field_name'] ?? ''), $pageSide);
        $bucket = $pageSide . ':' . $sectionKey;
        if (!isset($grouped[$bucket])) {
            $grouped[$bucket] = [];
        }
        $grouped[$bucket][] = $field;
    }

    return $grouped;
}

function printFillEntryFormInputClass(): string
{
    return 'w-full bg-white border border-gray-200 rounded-xl px-4 py-2.5 text-sm';
}

function renderPrintFillEntryFormField(array $fillField): void
{
    ?>
    <div>
        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1"><?= htmlspecialchars($fillField['label']) ?></label>
        <?php renderPrintFillFieldInput($fillField, [
            'name'        => 'print_fill[' . $fillField['field_name'] . ']',
            'input_class' => printFillEntryFormInputClass(),
            'lcro_class'  => 'records-entry-print-fill__field--lcro',
        ]); ?>
    </div>
    <?php
}

function renderPrintFillWorkspaceField(array $fillField): void
{
    ?>
    <div>
        <label class="block text-[10px] font-bold text-gray-700 uppercase mb-1"><?= htmlspecialchars($fillField['label']) ?></label>
        <?php renderPrintFillFieldInput($fillField, [
            'input_class' => printFillEntryFormInputClass(),
            'lcro_class'  => 'print-cert-fill-field--lcro',
        ]); ?>
    </div>
    <?php
}

/**
 * Classic compact fill-in list (certification documents / print certificate).
 *
 * @param list<array<string, mixed>> $fillEditorFields
 */
function renderPrintFillWorkspaceClassicList(array $fillEditorFields, string $fillSide): void
{
    foreach ($fillEditorFields as $fillField) {
        if (($fillField['page_side'] ?? '') !== $fillSide) {
            continue;
        }
        $fillGroup = printFillFieldGroup((string) ($fillField['field_name'] ?? ''));
        ?>
        <label class="print-cert-fill-field"<?= $fillGroup !== '' ? ' data-fill-group="' . htmlspecialchars($fillGroup) . '"' : '' ?>>
            <span><?= htmlspecialchars((string) ($fillField['label'] ?? '')) ?></span>
            <?php renderPrintFillFieldInput($fillField); ?>
        </label>
        <?php
    }
}

/**
 * Grouped section panels (Child's Information, etc.) — shared by records entry and print/documents workspace.
 *
 * @param array<string, list<array<string, mixed>>> $grouped from groupPrintFillFieldsForEntryForm()
 */
function renderPrintFillGroupedSectionPanels(string $certificateType, array $grouped, string $fillSide, string $context = 'entry'): void
{
    $sectionOrder = printFillEntryFormSectionOrder($certificateType, $fillSide);
    $hasAny = false;
    foreach ($sectionOrder as $sectionKey) {
        if (!empty($grouped[$fillSide . ':' . $sectionKey])) {
            $hasAny = true;
            break;
        }
    }

    if (!$hasAny) {
        $emptyClass = $context === 'entry' ? 'records-entry-print-fill__empty' : 'print-cert-fill-empty';
        echo '<p class="' . htmlspecialchars($emptyClass) . '">No '
            . ($fillSide === 'back' ? 'back page' : 'front page')
            . ' fields configured yet.</p>';

        return;
    }

    $gridClass = $context === 'entry'
        ? 'grid grid-cols-1 sm:grid-cols-2 gap-4 records-entry-print-fill__grid'
        : 'grid grid-cols-1 sm:grid-cols-2 gap-4 print-cert-fill-section-grid';

    foreach ($sectionOrder as $sectionKey) {
        $sectionFields = $grouped[$fillSide . ':' . $sectionKey] ?? [];
        if ($sectionFields === []) {
            continue;
        }
        $presentation = printFillEntryFormSectionPresentation($sectionKey);
        $fillGroupAttr = printFillFieldGroup($sectionFields[0]['field_name'] ?? '');
        if ($fillGroupAttr === '' && in_array($sectionKey, ['paternity', 'delayed_birth', 'delayed_death', 'delayed_marriage', 'infant_section', 'postmortem'], true)) {
            $fillGroupAttr = $sectionKey;
        }
        $groupAttr = $fillGroupAttr !== '' ? ' data-fill-group="' . htmlspecialchars($fillGroupAttr) . '"' : '';
        ?>
        <?php if ($presentation['wrap']): ?>
        <div class="<?= htmlspecialchars($presentation['panel_class']) ?>"<?= $groupAttr ?>>
            <p class="<?= htmlspecialchars($presentation['heading_class']) ?>">
                <i data-lucide="<?= htmlspecialchars($presentation['icon']) ?>" class="w-4 h-4"></i>
                <?= htmlspecialchars($presentation['label']) ?>
            </p>
            <div class="<?= htmlspecialchars($gridClass) ?>">
                <?php foreach ($sectionFields as $fillField) {
                    if ($context === 'entry') {
                        renderPrintFillEntryFormField($fillField);
                    } else {
                        renderPrintFillWorkspaceField($fillField);
                    }
                } ?>
            </div>
        </div>
        <?php else: ?>
        <div class="space-y-4"<?= $groupAttr ?>>
            <p class="<?= htmlspecialchars($presentation['heading_class']) ?>">
                <i data-lucide="<?= htmlspecialchars($presentation['icon']) ?>" class="w-4 h-4"></i>
                <?= htmlspecialchars($presentation['label']) ?>
            </p>
            <div class="<?= htmlspecialchars($gridClass) ?>">
                <?php foreach ($sectionFields as $fillField) {
                    if ($context === 'entry') {
                        renderPrintFillEntryFormField($fillField);
                    } else {
                        renderPrintFillWorkspaceField($fillField);
                    }
                } ?>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
}

function renderRecordsEntryPrintFillSection(PDO $pdo, string $type, array $modalRecord, bool $active): void
{
    if (!function_exists('printFillEditorFields')) {
        require_once __DIR__ . '/printing.php';
    }

    $source = ($modalRecord['record_type'] ?? '') === $type && $modalRecord !== []
        ? $modalRecord
        : ['record_type' => $type];

    $fields = printFillEditorFields($type, $source, [], $pdo);
    $grouped = groupPrintFillFieldsForEntryForm($type, $fields);
    $panelId = $type . 'PrintFillPanel';
    ?>
    <div id="<?= htmlspecialchars($panelId) ?>" class="records-entry-print-fill <?= $active ? '' : 'hidden' ?>">
        <div class="records-entry-print-fill__head">
            <div>
                <p class="records-entry-print-fill__hint">Front and back of the municipal certificate. <strong>Child</strong>, <strong>Deceased</strong>, or <strong>Husband/Wife</strong> sections must be completed to save (middle names optional). Other sections are optional. Custom fields from Print Calibration appear here.</p>
            </div>
        </div>
        <div class="records-entry-print-fill__tabs" role="tablist" aria-label="<?= htmlspecialchars(ucfirst($type)) ?> fill-in page">
            <button type="button" class="records-entry-print-fill__tab is-active" data-entry-fill-tab="front" role="tab" aria-selected="true">Front page</button>
            <button type="button" class="records-entry-print-fill__tab" data-entry-fill-tab="back" role="tab" aria-selected="false">Back page</button>
        </div>
        <?php foreach (['front', 'back'] as $fillSide): ?>
        <div class="records-entry-print-fill__panel space-y-4" data-entry-fill-panel="<?= $fillSide ?>" role="tabpanel"<?= $fillSide === 'back' ? ' hidden' : '' ?>>
            <?php renderPrintFillGroupedSectionPanels($type, $grouped, $fillSide, 'entry'); ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}
