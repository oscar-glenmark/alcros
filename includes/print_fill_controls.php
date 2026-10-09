<?php

require_once __DIR__ . '/cascading_location.php';
require_once __DIR__ . '/print_field_definitions.php';
if (!function_exists('printFillFieldGroup')) {
    require_once __DIR__ . '/printing.php';
}

/** @var array<string, mixed> */
$GLOBALS['alcros_print_fill_entry_ctx'] = [];

function printFillEntryFormSetContext(array $context): void
{
    $GLOBALS['alcros_print_fill_entry_ctx'] = $context;
}

/** Registry auto-number + book suggestions for Documents → Fill-in Data (always new record). */
function printFillSetDocumentWorkspaceContext(PDO $pdo, string $certificateType): void
{
    require_once __DIR__ . '/civil_registry_numbering.php';
    printFillEntryFormSetContext([
        'registry_ui'  => 'auto',
        'book_options' => civilRegistryDistinctBookNumbers($pdo, $certificateType),
        'context_id'   => 'documents-' . $certificateType,
    ]);
}

/** @return array<string, mixed> */
function printFillEntryFormContext(): array
{
    return is_array($GLOBALS['alcros_print_fill_entry_ctx'] ?? null)
        ? $GLOBALS['alcros_print_fill_entry_ctx']
        : [];
}

/** Month name as stored on certificates after save (see printDateParts). */
function printFillMonthPlaceholderExample(): string
{
    return 'January';
}

/** Combined date fields saved as "Month DD, YYYY" (see printFormatDateField). */
function printFillSpelledDatePlaceholder(): string
{
    return printFillMonthPlaceholderExample() . ' 00, 0000';
}

function printFillDefaultPlaceholder(string $fieldName, string $label): string
{
    static $exact = [
        'page_number'              => 'Page in the register book',
        'province'                 => 'Province',
        'city_municipality'        => 'City or municipality',
        'sex'                      => 'Male or Female',
        'birth_type'               => 'e.g. Single, Twin, Triplet',
        'birth_weight'             => 'e.g. 3.2 kg',
        'birth_time'               => 'e.g. 2:30 AM',
        'death_time'               => 'e.g. 10:15 PM',
        'marriage_time'            => 'e.g. 10:00 AM',
        'birth_order'              => 'Order if multiple birth',
        'multiple_birth_child_was' => 'e.g. First, Second',
        'witnesses'                => 'Names, separated by commas',
        'remarks_annotations'      => 'Optional remarks',
        'citizenship'              => 'e.g. Filipino',
        'civil_status'             => 'e.g. Single, Married, Widowed',
        'occupation'               => 'e.g. Farmer, Teacher',
        'religion'                 => 'e.g. Roman Catholic',
        'autopsy_performed'        => 'Yes or No',
        'solemnizing_officer'      => 'Name of solemnizing officer',
        'place_of_death'           => 'Hospital, home, or other place',
        'place_of_burial'          => 'Cemetery or burial place',
        'marriage_place'           => 'City/municipality and province',
        'birth_place'              => 'Barangay, municipality, province',
    ];

    if (isset($exact[$fieldName])) {
        return $exact[$fieldName];
    }

    if ($fieldName === 'registration_date' || $fieldName === 'registrar_date') {
        return printFillSpelledDatePlaceholder();
    }

    if (preg_match('/citizenship|nationality/i', $fieldName)) {
        return 'e.g. Filipino';
    }
    if (preg_match('/religion/i', $fieldName)) {
        return 'e.g. Roman Catholic';
    }
    if (preg_match('/occupation/i', $fieldName)) {
        return 'e.g. Farmer, Teacher';
    }
    if (preg_match('/_(day)$/', $fieldName)) {
        return '00';
    }
    if (preg_match('/_(month)$/', $fieldName)) {
        return printFillMonthPlaceholderExample();
    }
    if (preg_match('/_(year)$/', $fieldName)) {
        return '0000';
    }
    if (str_contains($fieldName, 'age')) {
        return 'Age in years';
    }
    if (preg_match('/_(email|e_mail)$/i', $fieldName)) {
        return 'Email address';
    }
    if (preg_match('/_(phone|mobile|contact)/i', $fieldName)) {
        return 'Contact number';
    }
    if (preg_match('/first_name|firstname/i', $fieldName)) {
        return 'First name';
    }
    if (preg_match('/last_name|lastname/i', $fieldName)) {
        return 'Last name';
    }
    if (preg_match('/middle_name|middlename/i', $fieldName)) {
        return 'Middle name (optional)';
    }
    if (str_contains($fieldName, 'address') || str_contains($fieldName, 'residence')) {
        return 'Complete address';
    }
    if (str_contains($fieldName, 'signature')) {
        return 'Name for signature line';
    }
    if (str_contains($fieldName, 'title') || str_contains($fieldName, 'position')) {
        return 'Title or position';
    }
    if (str_contains($fieldName, 'date')) {
        return printFillSpelledDatePlaceholder();
    }
    if (str_contains($fieldName, 'cause')) {
        return 'As on the certificate';
    }
    if (str_contains($fieldName, 'name')) {
        return 'Full name';
    }

    $label = trim($label);
    if ($label === '') {
        return 'Enter value';
    }

    return 'Enter ' . mb_strtolower($label);
}

function printFillIsSexField(string $fieldName): bool
{
    return $fieldName === 'sex' || (bool) preg_match('/_sex$/', $fieldName);
}

function printFillIsMarriagePartySexField(string $fieldName): bool
{
    return $fieldName === 'husband_sex' || $fieldName === 'wife_sex';
}

function printFillMarriagePartySexDefault(string $fieldName): ?string
{
    if ($fieldName === 'husband_sex') {
        return 'Male';
    }
    if ($fieldName === 'wife_sex') {
        return 'Female';
    }

    return null;
}

function printFillIsOfficeLocationField(string $fieldName): bool
{
    return $fieldName === 'province' || $fieldName === 'city_municipality';
}

function printFillOfficeLocationDefault(string $fieldName): string
{
    if (!function_exists('printOfficeLocationFields')) {
        require_once __DIR__ . '/printing.php';
    }
    $office = printOfficeLocationFields();

    return trim((string) ($office[$fieldName] ?? ''));
}

function printFillIsBirthTypeField(string $fieldName): bool
{
    return $fieldName === 'birth_type' || $fieldName === 'child_birth_type';
}

/** @return list<string> */
function printFillCalendarDateFieldNames(): array
{
    return [
        'attendant_cert_date',
        'informant_date',
        'prepared_by_date',
        'received_by_date',
        'registration_date',
        'registrar_date',
        'burial_permit_date',
        'transfer_permit_date',
    ];
}

function printFillIsCalendarDateField(string $fieldName): bool
{
    return in_array($fieldName, printFillCalendarDateFieldNames(), true);
}

function printFillCertDateToIso(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d', $ts);
}

function printFillNormalizeCalendarDateSubmitted(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (!function_exists('printFormatDateField')) {
        require_once __DIR__ . '/printing.php';
    }
    $iso = printFillCertDateToIso($value);
    if ($iso !== '') {
        return printFormatDateField($iso);
    }

    return $value;
}

function printFillIsMonthField(string $fieldName): bool
{
    return (bool) preg_match('/_(month)$/', $fieldName);
}

function printFillIsDayField(string $fieldName): bool
{
    return (bool) preg_match('/_(day)$/', $fieldName);
}

function printFillIsYearField(string $fieldName): bool
{
    return (bool) preg_match('/_(year)$/', $fieldName);
}

/** @return list<string> */
function printFillMonthOptions(): array
{
    return [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];
}

/** @return list<string> */
function printFillSexOptions(): array
{
    return ['Male', 'Female'];
}

/** @return list<string> */
function printFillBirthTypeOptions(): array
{
    return ['Single', 'Twin', 'Triplet', 'Quadruplet', 'Quintuplet'];
}

/** @return list<string> */
function printFillDayOptions(): array
{
    $days = [];
    for ($day = 1; $day <= 31; $day++) {
        $days[] = str_pad((string) $day, 2, '0', STR_PAD_LEFT);
    }

    return $days;
}

/** @return list<string> */
function printFillYearOptions(): array
{
    $years = [];
    $maxYear = (int) date('Y') + 1;
    for ($year = $maxYear; $year >= 1900; $year--) {
        $years[] = (string) $year;
    }

    return $years;
}

function printFillOptionMatchesValue(string $value, string $option): bool
{
    return strcasecmp(trim($value), trim($option)) === 0;
}

function printFillOptionMatchesDay(string $value, string $option): bool
{
    if ($value === '' || $option === '') {
        return false;
    }
    if (!ctype_digit(trim($value)) || !ctype_digit(trim($option))) {
        return printFillOptionMatchesValue($value, $option);
    }

    return (int) $value === (int) $option;
}

function printFillOptionSelected(string $fieldName, string $value, string $option): bool
{
    if (printFillIsDayField($fieldName)) {
        return printFillOptionMatchesDay($value, $option);
    }

    return printFillOptionMatchesValue($value, $option);
}

/** @param list<string> $options */
function renderPrintFillSelect(array $fillField, array $options, string $placeholder, string $classes, string $inputName): void
{
    $fieldName = (string) ($fillField['field_name'] ?? '');
    $value = trim((string) ($fillField['value'] ?? ''));
    ?>
    <select data-field-name="<?= htmlspecialchars($fieldName) ?>"
            class="<?= htmlspecialchars(trim($classes . ' print-fill-select')) ?>"
            autocomplete="off"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?>>
        <option value=""<?= $value === '' ? ' selected' : '' ?>><?= htmlspecialchars($placeholder) ?></option>
        <?php foreach ($options as $option): ?>
        <option value="<?= htmlspecialchars($option) ?>"<?= printFillOptionSelected($fieldName, $value, $option) ? ' selected' : '' ?>><?= htmlspecialchars($option) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

function renderPrintFillCalendarDateInput(array $fillField, string $classes, string $inputName): void
{
    $fieldName = (string) ($fillField['field_name'] ?? '');
    $iso = printFillCertDateToIso(trim((string) ($fillField['value'] ?? '')));
    ?>
    <input type="date"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           data-print-fill-date="1"
           value="<?= htmlspecialchars($iso) ?>"
           class="<?= htmlspecialchars(trim($classes . ' print-fill-date')) ?>"
           autocomplete="off"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?>>
    <?php
}

/** Render a print fill-in control (plain text or cascading location for birth place / residence). */
function renderPrintFillFieldInput(array $fillField, array $options = []): void
{
    $fieldName = (string) ($fillField['field_name'] ?? '');
    $value = (string) ($fillField['value'] ?? '');
    $entryCtx = printFillEntryFormContext();
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
        $placeholder = 'Barangay, city/municipality, province';
    } elseif ($isPhMarriage) {
        $placeholder = 'City/municipality, province, country';
    } elseif ($isPhResidence) {
        $placeholder = 'Barangay, city/municipality, province, country';
    } elseif ($isLocation) {
        $placeholder = 'Barangay, city/municipality, province, country';
    } elseif (preg_match('/_(middle_name|middlename)$/i', $fieldName)) {
        $placeholder = 'Middle name (optional)';
    }

    if ($placeholder === '') {
        $placeholder = printFillDefaultPlaceholder($fieldName, (string) ($fillField['label'] ?? ''));
    }

    if ($fieldName === 'registry_number' && !empty($entryCtx['registry_ui'])) {
        $isAuto = ($entryCtx['registry_ui'] ?? '') === 'auto';
        $roClass = trim($classes . ' bg-slate-50 text-slate-600 cursor-not-allowed');
        if ($isAuto) {
            $placeholder = 'Assigned automatically when you save';
            $value = '';
        } else {
            $placeholder = 'Registry number (locked on edit)';
        }
        ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars($value) ?>"
           class="<?= htmlspecialchars($roClass) ?>"
           autocomplete="off"
           spellcheck="false"
           readonly
           tabindex="-1"
           aria-readonly="true"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?><?= $placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '' ?>>
        <?php
        return;
    }

    if ($fieldName === 'book_number' && isset($entryCtx['book_options']) && is_array($entryCtx['book_options'])) {
        $books = $entryCtx['book_options'];
        $listKey = $inputName !== '' ? $inputName : (string) ($entryCtx['context_id'] ?? 'book') . '-book_number';
        $listId = 'bookNumberSuggestions-' . md5($listKey);
        if ($placeholder === '' || $placeholder === 'Enter book number') {
            $placeholder = 'Type book number or pick from recent books';
        }
        ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars(trim($value)) ?>"
           class="<?= htmlspecialchars($classes) ?>"
           list="<?= htmlspecialchars($listId) ?>"
           autocomplete="off"
           spellcheck="false"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?>
           placeholder="<?= htmlspecialchars($placeholder) ?>">
    <datalist id="<?= htmlspecialchars($listId) ?>">
        <?php foreach ($books as $book): ?>
        <option value="<?= htmlspecialchars($book) ?>"></option>
        <?php endforeach; ?>
    </datalist>
        <?php
        return;
    }

    if (printFillIsMarriagePartySexField($fieldName)) {
        $display = trim($value);
        if ($display === '') {
            $display = printFillMarriagePartySexDefault($fieldName) ?? '';
        }
        ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars($display) ?>"
           class="<?= htmlspecialchars($classes) ?>"
           autocomplete="off"
           spellcheck="false"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?>
           placeholder="Male or Female">
        <?php
        return;
    }

    if (printFillIsOfficeLocationField($fieldName)) {
        $display = trim($value);
        if ($display === '') {
            $display = printFillOfficeLocationDefault($fieldName);
        }
        if ($placeholder === '' || in_array($placeholder, ['Province', 'City or municipality'], true)) {
            $placeholder = $fieldName === 'province'
                ? printFillOfficeLocationDefault('province')
                : printFillOfficeLocationDefault('city_municipality');
        }
        ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars($display) ?>"
           class="<?= htmlspecialchars($classes) ?>"
           autocomplete="off"
           spellcheck="false"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?><?= $placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '' ?>>
        <?php
        return;
    }

    if (printFillIsSexField($fieldName)) {
        renderPrintFillSelect($fillField, printFillSexOptions(), 'Select sex', $classes, $inputName);

        return;
    }

    if (printFillIsBirthTypeField($fieldName)) {
        renderPrintFillSelect(
            $fillField,
            printFillBirthTypeOptions(),
            'Select type of birth',
            $classes,
            $inputName
        );

        return;
    }

    if (printFillIsMonthField($fieldName)) {
        renderPrintFillSelect(
            $fillField,
            printFillMonthOptions(),
            'Select month',
            $classes,
            $inputName
        );

        return;
    }

    if (printFillIsDayField($fieldName)) {
        renderPrintFillSelect(
            $fillField,
            printFillDayOptions(),
            '00',
            $classes,
            $inputName
        );

        return;
    }

    if (printFillIsYearField($fieldName)) {
        renderPrintFillSelect(
            $fillField,
            printFillYearOptions(),
            '0000',
            $classes,
            $inputName
        );

        return;
    }

    if (printFillIsCalendarDateField($fieldName)) {
        renderPrintFillCalendarDateInput($fillField, $classes, $inputName);

        return;
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

function renderRecordsEntryPrintFillSection(PDO $pdo, string $type, array $modalRecord, bool $active, bool $entryFormEditMode = false): void
{
    if (!function_exists('printFillEditorFields')) {
        require_once __DIR__ . '/printing.php';
    }
    require_once __DIR__ . '/civil_registry_numbering.php';

    $source = ($modalRecord['record_type'] ?? '') === $type && $modalRecord !== []
        ? $modalRecord
        : ['record_type' => $type];

    $hasExistingId = $entryFormEditMode && !empty($modalRecord['id']);
    printFillEntryFormSetContext([
        'registry_ui'  => $hasExistingId ? 'locked' : 'auto',
        'book_options' => civilRegistryDistinctBookNumbers($pdo, $type),
    ]);

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
    printFillEntryFormSetContext([]);
}
