<?php
/**
 * Civil registry schema: shared base record plus type-specific detail tables.
 */

/** @return list<string> Detail fields stored as integers. */
function civilRecordIntDetailFields(): array
{
    return [
        'mother_age', 'father_age', 'husband_age', 'wife_age',
        'age_death_years', 'age_death_months', 'age_death_days', 'age_death_hours', 'age_death_minutes',
    ];
}

/** @return list<string> CSV import/export columns — same field names as print certificate fill-in boxes. */
function civilRecordCsvColumns(string $type): array
{
    if (!function_exists('printFillCsvColumns')) {
        require_once __DIR__ . '/print_field_definitions.php';
    }

    return printFillCsvColumns($type);
}

/** @return list<string|null> Sample row aligned with civilRecordCsvColumns(). */
function civilRecordCsvSampleRow(string $type): array
{
    if (!function_exists('civilRecordCsvSampleRowFromPrintFields')) {
        throw new RuntimeException('Print helpers are not loaded.');
    }

    return civilRecordCsvSampleRowFromPrintFields($type);
}

function civilRecordViewFieldLabel(string $field): string
{
    static $labels = [
        '_person_name'                         => 'Full Name',
        '_age_at_death'                        => 'Age at Death',
        '_death_time'                          => 'Time of Death',
        'registry_number'                      => 'Registry Number',
        'book_number'                          => 'Book Number',
        'page_number'                          => 'Page Number',
        'birth_date'                           => 'Date of Birth',
        'event_date'                           => 'Event Date',
        'birth_time'                           => 'Time of Birth',
        'birth_type'                           => 'Type of Birth',
        'birth_order'                          => 'Birth Order',
        'birth_weight'                         => 'Weight at Birth',
        'place'                                => 'Place',
        'sex'                                  => 'Sex',
        'mother_name'                          => 'Mother',
        'mother_age'                           => 'Mother Age',
        'mother_nationality'                   => 'Mother Nationality',
        'mother_religion'                      => 'Mother Religion',
        'mother_occupation'                    => 'Mother Occupation',
        'mother_residence'                     => 'Mother Residence',
        'mother_children_born_alive'           => 'Children Born Alive',
        'mother_children_still_living'         => 'Children Still Living',
        'mother_children_born_alive_now_dead'  => 'Children Born Alive but Now Dead',
        'father_name'                          => 'Father',
        'father_age'                           => 'Father Age',
        'father_nationality'                   => 'Father Nationality',
        'father_religion'                      => 'Father Religion',
        'father_occupation'                    => 'Father Occupation',
        'father_residence'                     => 'Father Residence',
        'parents_marriage_date'                => 'Parents Marriage Date',
        'parents_marriage_place'               => 'Parents Marriage Place',
        'registration_date'                    => 'Date of Registration',
        'notes'                                => 'Remarks / Notes',
        'created_at'                           => 'Created',
        'residence_deceased'                   => 'Residence of Deceased',
        'residence_length_place'               => 'Length of Residence (Place of Death)',
        'residence_length_ph'                  => 'Length of Residence (Philippines)',
        'nationality'                          => 'Nationality',
        'civil_status'                         => 'Civil Status',
        'religion'                             => 'Religion',
        'occupation'                           => 'Occupation',
        'stillbirth'                           => 'Still-birth',
        'surviving_spouse_name'                => 'Surviving Spouse',
        'surviving_spouse_address'             => 'Spouse Address',
        'place_of_burial'                      => 'Place of Burial',
        'immediate_cause'                      => 'Immediate Cause',
        'contributory_cause'                   => 'Contributory Cause',
        'attending_physician'                  => 'Attending Physician',
        'autopsy_performed'                    => 'Autopsy Performed',
        'code_number'                          => 'Code Number',
        'child_age_mother'                     => 'Age of Mother (0–7 days)',
        'child_delivery_method'                => 'Method of Delivery',
        'child_pregnancy_length'               => 'Length of Pregnancy',
        'child_birth_type'                     => 'Type of Birth (Infant)',
        'child_birth_order_infant'             => 'Birth Order (Infant)',
        'infant_cause_a'                       => 'Infant Cause A',
        'infant_cause_b'                       => 'Infant Cause B',
        'infant_cause_c'                       => 'Infant Cause C',
        'infant_cause_d'                       => 'Infant Cause D',
        'infant_cause_e'                       => 'Infant Cause E',
        'postmortem_cause'                     => 'Postmortem Cause',
        'husband_name'                         => 'Full Name',
        'wife_name'                            => 'Full Name',
        'marriage_time'                        => 'Time of Marriage',
        'solemnized_by'                        => 'Solemnized By',
        'witnesses'                            => 'Witnesses',
        'husband_father_citizenship'           => 'Father Citizenship',
        'husband_mother_citizenship'           => 'Mother Citizenship',
        'husband_consent_person_name'          => 'Consent Person Name',
        'husband_consent_relationship'         => 'Consent Relationship',
        'husband_consent_residence'            => 'Consent Residence',
        'wife_father_citizenship'              => 'Father Citizenship',
        'wife_mother_citizenship'              => 'Mother Citizenship',
        'wife_consent_person_name'             => 'Consent Person Name',
        'wife_consent_relationship'            => 'Consent Relationship',
        'wife_consent_residence'               => 'Consent Residence',
    ];

    if (isset($labels[$field])) {
        return $labels[$field];
    }

    if (preg_match('/^(husband|wife)_(birth_date|age|birth_place|citizenship|religion|civil_status|residence|father_name|mother_maiden_name)$/', $field, $m)) {
        $role = ucfirst($m[1]);
        return civilRecordViewFieldLabel($m[2] === 'birth_date' ? 'birth_date' : ($m[2] === 'birth_place' ? 'place' : $m[2]));
    }

    return ucwords(str_replace('_', ' ', $field));
}

/** @return list<array{title: string, fields: list<array{key: string, label: string, format?: string, full?: bool}>}> */
function civilRecordViewSections(string $type): array
{
    $field = static fn (string $key, ?string $label = null, array $opts = []): array => array_merge(
        ['key' => $key, 'label' => $label ?? civilRecordViewFieldLabel($key)],
        $opts
    );

    if ($type === 'birth') {
        return [
            [
                'title' => 'Child Information',
                'fields' => [
                    $field('_person_name', 'Full Name', ['full' => true]),
                    $field('registry_number'),
                    $field('book_number'),
                    $field('page_number'),
                    $field('sex'),
                    $field('birth_date', 'Date of Birth', ['format' => 'date']),
                    $field('birth_time'),
                    $field('birth_type'),
                    $field('birth_order'),
                    $field('birth_weight'),
                    $field('place', 'Place of Birth', ['full' => true]),
                ],
            ],
            [
                'title' => 'Mother',
                'fields' => [
                    $field('mother_name', 'Full Name', ['full' => true]),
                    $field('mother_age'),
                    $field('mother_nationality'),
                    $field('mother_religion'),
                    $field('mother_occupation'),
                    $field('mother_residence', null, ['full' => true]),
                    $field('mother_children_born_alive'),
                    $field('mother_children_still_living'),
                    $field('mother_children_born_alive_now_dead'),
                ],
            ],
            [
                'title' => 'Father',
                'fields' => [
                    $field('father_name', 'Full Name', ['full' => true]),
                    $field('father_age'),
                    $field('father_nationality'),
                    $field('father_religion'),
                    $field('father_occupation'),
                    $field('father_residence', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Marriage of Parents',
                'fields' => [
                    $field('parents_marriage_date', null, ['format' => 'date']),
                    $field('parents_marriage_place', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Registration',
                'fields' => [
                    $field('registration_date', null, ['format' => 'date']),
                    $field('notes', null, ['full' => true]),
                    $field('created_at', null, ['format' => 'date']),
                ],
            ],
        ];
    }

    if ($type === 'death') {
        return [
            [
                'title' => 'Deceased',
                'fields' => [
                    $field('_person_name', 'Full Name', ['full' => true]),
                    $field('registry_number'),
                    $field('book_number'),
                    $field('page_number'),
                    $field('sex'),
                    $field('birth_date', 'Date of Birth', ['format' => 'date']),
                    $field('registration_date', null, ['format' => 'date']),
                    $field('_age_at_death'),
                    $field('nationality'),
                    $field('civil_status'),
                    $field('religion'),
                    $field('occupation'),
                    $field('stillbirth', null, ['format' => 'bool']),
                ],
            ],
            [
                'title' => 'Residence & Family',
                'fields' => [
                    $field('residence_deceased', null, ['full' => true]),
                    $field('residence_length_place'),
                    $field('residence_length_ph'),
                    $field('father_name', null, ['full' => true]),
                    $field('mother_name', null, ['full' => true]),
                    $field('surviving_spouse_name', null, ['full' => true]),
                    $field('surviving_spouse_address', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Death Event',
                'fields' => [
                    $field('event_date', 'Date of Death', ['format' => 'date']),
                    $field('_death_time'),
                    $field('place', 'Place of Death', ['full' => true]),
                    $field('place_of_burial', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Medical Information',
                'fields' => [
                    $field('immediate_cause', null, ['full' => true]),
                    $field('contributory_cause', null, ['full' => true]),
                    $field('attending_physician', null, ['full' => true]),
                    $field('autopsy_performed'),
                    $field('code_number'),
                ],
            ],
            [
                'title' => 'Infant Details (0–7 Days)',
                'fields' => [
                    $field('child_age_mother'),
                    $field('child_delivery_method'),
                    $field('child_pregnancy_length'),
                    $field('child_birth_type'),
                    $field('child_birth_order_infant'),
                    $field('infant_cause_a', null, ['full' => true]),
                    $field('infant_cause_b', null, ['full' => true]),
                    $field('infant_cause_c', null, ['full' => true]),
                    $field('infant_cause_d', null, ['full' => true]),
                    $field('infant_cause_e', null, ['full' => true]),
                    $field('postmortem_cause', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Registration',
                'fields' => [
                    $field('notes', null, ['full' => true]),
                    $field('created_at', null, ['format' => 'date']),
                ],
            ],
        ];
    }

    if ($type === 'marriage') {
        $spouseFields = static function (string $prefix, string $roleLabel) use ($field): array {
            return [
                'title' => $roleLabel,
                'fields' => [
                    $field($prefix . '_name', 'Full Name', ['full' => true]),
                    $field($prefix . '_birth_date', 'Date of Birth', ['format' => 'date']),
                    $field($prefix . '_age'),
                    $field($prefix . '_birth_place', 'Place of Birth', ['full' => true]),
                    $field($prefix . '_citizenship'),
                    $field($prefix . '_religion'),
                    $field($prefix . '_civil_status'),
                    $field($prefix . '_residence', null, ['full' => true]),
                    $field($prefix . '_father_name', "Father's Name", ['full' => true]),
                    $field($prefix . '_mother_maiden_name', "Mother's Maiden Name", ['full' => true]),
                    $field($prefix . '_father_citizenship'),
                    $field($prefix . '_mother_citizenship'),
                    $field($prefix . '_consent_person_name', 'Consent Person Name', ['full' => true]),
                    $field($prefix . '_consent_relationship'),
                    $field($prefix . '_consent_residence', null, ['full' => true]),
                ],
            ];
        };

        return [
            [
                'title' => 'Record',
                'fields' => [
                    $field('_person_name', 'Couple', ['full' => true]),
                    $field('registry_number'),
                    $field('book_number'),
                    $field('page_number'),
                ],
            ],
            $spouseFields('husband', 'Husband'),
            $spouseFields('wife', 'Wife'),
            [
                'title' => 'Marriage Ceremony',
                'fields' => [
                    $field('event_date', 'Date of Marriage', ['format' => 'date']),
                    $field('marriage_time'),
                    $field('place', 'Place of Marriage', ['full' => true]),
                    $field('solemnized_by', null, ['full' => true]),
                    $field('witnesses', null, ['full' => true]),
                ],
            ],
            [
                'title' => 'Registration',
                'fields' => [
                    $field('notes', null, ['full' => true]),
                    $field('created_at', null, ['format' => 'date']),
                ],
            ],
        ];
    }

    return [];
}

/** @return array<string, string> */
function civilRecordPrintFillFieldLabels(string $type): array
{
    if (!function_exists('printFieldCatalog')) {
        require_once __DIR__ . '/print_field_definitions.php';
    }

    $catalog = printFieldCatalog()[$type] ?? [];

    return array_merge($catalog['front'] ?? [], $catalog['back'] ?? []);
}

/** Apply detail-table values from user/CSV input onto a normalized record payload. */
function civilRecordApplyInputDetailFields(array &$data, array $input, string $type): void
{
    $intFields = civilRecordIntDetailFields();
    foreach (civilRecordTypeFieldNames($type) as $field) {
        if (!array_key_exists($field, $input)) {
            continue;
        }
        $raw = $input[$field];
        if ($field === 'stillbirth') {
            $data[$field] = in_array(strtolower(trim((string) $raw)), ['1', 'yes', 'true'], true) ? 1 : 0;
        } elseif (in_array($field, $intFields, true)) {
            $data[$field] = ($raw ?? '') !== '' ? (int) $raw : null;
        } elseif ($field === 'birth_type') {
            $data[$field] = trim((string) $raw) ?: 'Single';
        } else {
            $data[$field] = trim((string) $raw) ?: null;
        }
    }
}

function civilRecordBaseColumnDefs(): array
{
    return [
        'record_type'     => "ENUM('birth','death','marriage') NOT NULL",
        'registry_number' => 'VARCHAR(50) NULL',
        'book_number'     => 'VARCHAR(20) NULL',
        'page_number'     => 'VARCHAR(20) NULL',
        'first_name'      => 'VARCHAR(80) NULL',
        'middle_name'     => 'VARCHAR(80) NULL',
        'last_name'       => 'VARCHAR(80) NULL',
        'birth_date'      => 'DATE NULL',
        'event_date'      => 'DATE NULL',
        'place'           => 'VARCHAR(255) NULL',
        'father_name'     => 'VARCHAR(150) NULL',
        'mother_name'     => 'VARCHAR(150) NULL',
        'notes'           => 'TEXT NULL',
        'print_fill_data' => 'JSON NULL',
    ];
}

function civilRecordBirthColumnDefs(): array
{
    return [
        'sex'                               => 'VARCHAR(10) NULL',
        'birth_time'                        => 'VARCHAR(20) NULL',
        'birth_type'                        => "VARCHAR(20) NULL DEFAULT 'Single'",
        'birth_order'                       => 'VARCHAR(50) NULL',
        'birth_weight'                      => 'VARCHAR(20) NULL',
        'mother_age'                        => 'INT NULL',
        'mother_nationality'                => 'VARCHAR(100) NULL',
        'mother_religion'                   => 'VARCHAR(100) NULL',
        'mother_occupation'                 => 'VARCHAR(150) NULL',
        'mother_residence'                  => 'VARCHAR(255) NULL',
        'mother_children_born_alive'        => 'VARCHAR(10) NULL',
        'mother_children_still_living'      => 'VARCHAR(10) NULL',
        'mother_children_born_alive_now_dead' => 'VARCHAR(10) NULL',
        'father_age'                        => 'INT NULL',
        'father_nationality'                => 'VARCHAR(100) NULL',
        'father_religion'                   => 'VARCHAR(100) NULL',
        'father_occupation'                 => 'VARCHAR(150) NULL',
        'father_residence'                  => 'VARCHAR(255) NULL',
        'parents_marriage_date'             => 'DATE NULL',
        'parents_marriage_place'            => 'VARCHAR(255) NULL',
        'registration_date'                 => 'DATE NULL',
    ];
}

function civilRecordDeathColumnDefs(): array
{
    return [
        'sex'                     => 'VARCHAR(10) NULL',
        'registration_date'       => 'DATE NULL',
        'residence_deceased'      => 'VARCHAR(255) NULL',
        'residence_length_place'  => 'VARCHAR(100) NULL',
        'residence_length_ph'     => 'VARCHAR(100) NULL',
        'nationality'             => 'VARCHAR(100) NULL',
        'civil_status'            => 'VARCHAR(50) NULL',
        'religion'                => 'VARCHAR(100) NULL',
        'age_death_years'         => 'INT NULL',
        'age_death_months'        => 'INT NULL',
        'age_death_days'          => 'INT NULL',
        'age_death_hours'         => 'INT NULL',
        'age_death_minutes'       => 'INT NULL',
        'stillbirth'              => 'TINYINT(1) NOT NULL DEFAULT 0',
        'occupation'              => 'VARCHAR(150) NULL',
        'surviving_spouse_name'   => 'VARCHAR(150) NULL',
        'surviving_spouse_address'=> 'VARCHAR(255) NULL',
        'place_of_burial'         => 'VARCHAR(255) NULL',
        'death_time'              => 'VARCHAR(20) NULL',
        'death_time_period'       => 'VARCHAR(10) NULL',
        'immediate_cause'         => 'VARCHAR(255) NULL',
        'contributory_cause'      => 'VARCHAR(255) NULL',
        'attending_physician'     => 'VARCHAR(150) NULL',
        'autopsy_performed'       => 'VARCHAR(10) NULL',
        'code_number'             => 'VARCHAR(50) NULL',
        'child_age_mother'        => 'VARCHAR(20) NULL',
        'child_delivery_method'   => 'VARCHAR(80) NULL',
        'child_pregnancy_length'  => 'VARCHAR(40) NULL',
        'child_birth_type'        => 'VARCHAR(40) NULL',
        'child_birth_order_infant'=> 'VARCHAR(20) NULL',
        'infant_cause_a'          => 'VARCHAR(255) NULL',
        'infant_cause_b'          => 'VARCHAR(255) NULL',
        'infant_cause_c'          => 'VARCHAR(255) NULL',
        'infant_cause_d'          => 'VARCHAR(255) NULL',
        'infant_cause_e'          => 'VARCHAR(255) NULL',
        'postmortem_cause'        => 'VARCHAR(255) NULL',
    ];
}

function civilRecordMarriageColumnDefs(): array
{
    return [
        'husband_name'                => 'VARCHAR(150) NULL',
        'husband_birth_date'          => 'DATE NULL',
        'husband_age'                 => 'INT NULL',
        'husband_birth_place'         => 'VARCHAR(255) NULL',
        'husband_citizenship'         => 'VARCHAR(100) NULL',
        'husband_religion'            => 'VARCHAR(100) NULL',
        'husband_civil_status'        => 'VARCHAR(50) NULL',
        'husband_residence'           => 'VARCHAR(255) NULL',
        'husband_father_name'         => 'VARCHAR(150) NULL',
        'husband_mother_maiden_name'  => 'VARCHAR(150) NULL',
        'husband_father_citizenship'  => 'VARCHAR(100) NULL',
        'husband_mother_citizenship'  => 'VARCHAR(100) NULL',
        'husband_consent_person_name' => 'VARCHAR(150) NULL',
        'husband_consent_relationship'=> 'VARCHAR(80) NULL',
        'husband_consent_residence'   => 'VARCHAR(255) NULL',
        'wife_name'                   => 'VARCHAR(150) NULL',
        'wife_birth_date'             => 'DATE NULL',
        'wife_age'                    => 'INT NULL',
        'wife_birth_place'            => 'VARCHAR(255) NULL',
        'wife_citizenship'            => 'VARCHAR(100) NULL',
        'wife_religion'               => 'VARCHAR(100) NULL',
        'wife_civil_status'           => 'VARCHAR(50) NULL',
        'wife_residence'              => 'VARCHAR(255) NULL',
        'wife_father_name'            => 'VARCHAR(150) NULL',
        'wife_mother_maiden_name'     => 'VARCHAR(150) NULL',
        'wife_father_citizenship'     => 'VARCHAR(100) NULL',
        'wife_mother_citizenship'     => 'VARCHAR(100) NULL',
        'wife_consent_person_name'    => 'VARCHAR(150) NULL',
        'wife_consent_relationship'   => 'VARCHAR(80) NULL',
        'wife_consent_residence'      => 'VARCHAR(255) NULL',
        'marriage_time'               => 'VARCHAR(20) NULL',
        'solemnized_by'               => 'VARCHAR(150) NULL',
        'witnesses'                   => 'TEXT NULL',
    ];
}

/** @return array<string, array<string, string>> */
function civilRecordTypeColumnMap(): array
{
    return [
        'birth'    => civilRecordBirthColumnDefs(),
        'death'    => civilRecordDeathColumnDefs(),
        'marriage' => civilRecordMarriageColumnDefs(),
    ];
}

function civilRecordTypeColumnDefs(string $type): array
{
    return civilRecordTypeColumnMap()[$type] ?? [];
}

function civilRecordDetailTableName(string $type): string
{
    return match ($type) {
        'birth'    => 'birth_record_details',
        'death'    => 'death_record_details',
        'marriage' => 'marriage_record_details',
        default    => '',
    };
}

/** @return list<string> */
function civilRecordBaseFieldNames(): array
{
    return array_keys(civilRecordBaseColumnDefs());
}

/** @return list<string> */
function civilRecordTypeFieldNames(string $type): array
{
    return array_keys(civilRecordTypeColumnDefs($type));
}

/** @return list<string> Legacy flat list used by CSV/export helpers. */
function civilRecordAllTypeFieldNames(): array
{
    $fields = [];
    foreach (civilRecordTypeColumnMap() as $columns) {
        foreach (array_keys($columns) as $field) {
            if (!in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        }
    }

    return $fields;
}

function ensureCivilRecordTypeTables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    foreach (civilRecordBaseColumnDefs() as $column => $definition) {
        try {
            $pdo->query("SELECT `$column` FROM civil_records LIMIT 1");
        } catch (Throwable $e) {
            try {
                $pdo->exec("ALTER TABLE civil_records ADD COLUMN `$column` $definition");
            } catch (Throwable $ignored) {
            }
        }
    }

    foreach (civilRecordTypeColumnMap() as $type => $columns) {
        $table = civilRecordDetailTableName($type);
        if ($table === '') {
            continue;
        }

        $columnSql = [];
        foreach ($columns as $column => $definition) {
            $columnSql[] = "`$column` $definition";
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `$table` (
                civil_record_id INT NOT NULL PRIMARY KEY,
                " . implode(",\n                ", $columnSql) . ",
                CONSTRAINT fk_{$table}_record
                    FOREIGN KEY (civil_record_id) REFERENCES civil_records(id) ON DELETE CASCADE
            ) ENGINE=InnoDB"
        );

        foreach ($columns as $column => $definition) {
            try {
                $pdo->query("SELECT `$column` FROM `$table` LIMIT 1");
            } catch (Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                } catch (Throwable $ignored) {
                }
            }
        }
    }

    migrateCivilRecordFlatFieldsToTypeTables($pdo);
    cleanupCivilRecordLegacyColumns($pdo);
}

/** @return list<string> Columns that should remain on civil_records. */
function civilRecordKeepColumnNames(): array
{
    return array_merge(['id'], civilRecordBaseFieldNames(), ['deleted_at', 'created_at']);
}

/** @return list<string> */
function civilRecordExistingColumnNames(PDO $pdo): array
{
    $stmt = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'civil_records'
         ORDER BY ORDINAL_POSITION"
    );

    return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
}

/** @return list<string> */
function civilRecordLegacyColumnNames(PDO $pdo): array
{
    return array_values(array_diff(civilRecordExistingColumnNames($pdo), civilRecordKeepColumnNames()));
}

/** @return array<string, string> Old flat column names mapped to current detail/print fields. */
function civilRecordLegacyFieldAliases(): array
{
    return [
        'mother_children_total'  => 'mother_children_born_alive',
        'mother_children_living' => 'mother_children_still_living',
        'mother_children_dead'   => 'mother_children_born_alive_now_dead',
        'registered_by'          => 'registrar_name',
        'registered_by_title'    => 'registrar_title',
        'registered_by_signature'=> 'registrar_signature',
        'form_remarks'           => 'remarks_annotations',
    ];
}

function syncFlatFieldsToTypeDetails(PDO $pdo): void
{
    $stmt = $pdo->query('SELECT * FROM civil_records WHERE deleted_at IS NULL');
    if ($stmt === false) {
        return;
    }

    $aliases = civilRecordLegacyFieldAliases();

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recordId = (int) ($row['id'] ?? 0);
        $type = (string) ($row['record_type'] ?? '');
        if ($recordId <= 0 || !in_array($type, ['birth', 'death', 'marriage'], true)) {
            continue;
        }

        $fields = civilRecordTypeFieldNames($type);
        $values = [];
        foreach ($fields as $field) {
            $raw = $row[$field] ?? null;
            if ($raw !== null && $raw !== '') {
                $values[$field] = $raw;
            }
        }

        foreach ($aliases as $legacy => $target) {
            if (!in_array($target, $fields, true)) {
                continue;
            }
            $raw = $row[$legacy] ?? null;
            if ($raw !== null && $raw !== '' && !isset($values[$target])) {
                $values[$target] = $raw;
            }
        }

        if ($values !== []) {
            saveCivilRecordTypeDetails($pdo, $recordId, $type, $values);
        }
    }
}

function preserveOrphanFlatFieldsInPrintFillData(PDO $pdo, array $orphanColumns): void
{
    if ($orphanColumns === []) {
        return;
    }

    $stmt = $pdo->query('SELECT * FROM civil_records WHERE deleted_at IS NULL');
    if ($stmt === false) {
        return;
    }

    $update = $pdo->prepare('UPDATE civil_records SET print_fill_data = ? WHERE id = ?');

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recordId = (int) ($row['id'] ?? 0);
        if ($recordId <= 0) {
            continue;
        }

        $fill = [];
        if (!empty($row['print_fill_data'])) {
            $decoded = json_decode((string) $row['print_fill_data'], true);
            if (is_array($decoded)) {
                $fill = $decoded;
            }
        }

        $changed = false;
        foreach ($orphanColumns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            $target = civilRecordLegacyFieldAliases()[$column] ?? $column;
            if (!isset($fill[$target]) || trim((string) $fill[$target]) === '') {
                $fill[$target] = $value;
                $changed = true;
            }
        }

        if (!$changed) {
            continue;
        }

        $json = json_encode($fill, JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            $update->execute([$json, $recordId]);
        }
    }
}

/** @return array{dropped: int, preserved_orphans: int, skipped?: bool} */
function cleanupCivilRecordLegacyColumns(PDO $pdo): array
{
    if (getSetting('civil_record_flat_columns_dropped', '') === '1') {
        return ['skipped' => true, 'dropped' => 0, 'preserved_orphans' => 0];
    }

    migrateCivilRecordFlatFieldsToTypeTables($pdo);
    syncFlatFieldsToTypeDetails($pdo);

    $legacyColumns = civilRecordLegacyColumnNames($pdo);
    if ($legacyColumns === []) {
        setSetting('civil_record_flat_columns_dropped', '1');
        return ['dropped' => 0, 'preserved_orphans' => 0];
    }

    $knownTypeFields = civilRecordAllTypeFieldNames();
    $orphanColumns = array_values(array_diff($legacyColumns, $knownTypeFields));
    preserveOrphanFlatFieldsInPrintFillData($pdo, $orphanColumns);

    $dropped = 0;
    foreach ($legacyColumns as $column) {
        try {
            $pdo->exec("ALTER TABLE civil_records DROP COLUMN `$column`");
            $dropped++;
        } catch (Throwable $e) {
            // Continue dropping remaining columns if one fails.
        }
    }

    setSetting('civil_record_flat_columns_dropped', '1');

    return [
        'dropped'           => $dropped,
        'preserved_orphans' => count($orphanColumns),
    ];
}

function migrateCivilRecordFlatFieldsToTypeTables(PDO $pdo): void
{
    if (getSetting('civil_record_type_tables_migrated', '') === '1') {
        return;
    }

    $hasFlatBirth = false;
    try {
        $pdo->query('SELECT birth_weight FROM civil_records LIMIT 1');
        $hasFlatBirth = true;
    } catch (Throwable $e) {
    }

    if (!$hasFlatBirth) {
        setSetting('civil_record_type_tables_migrated', '1');
        return;
    }

    $stmt = $pdo->query("SELECT * FROM civil_records WHERE deleted_at IS NULL");
    if ($stmt === false) {
        return;
    }

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $recordId = (int) ($row['id'] ?? 0);
        $type = (string) ($row['record_type'] ?? '');
        if ($recordId <= 0 || !in_array($type, ['birth', 'death', 'marriage'], true)) {
            continue;
        }

        $table = civilRecordDetailTableName($type);
        $fields = civilRecordTypeFieldNames($type);
        $values = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $values[$field] = $row[$field];
            }
        }
        if ($values === []) {
            continue;
        }

        $exists = $pdo->prepare("SELECT 1 FROM `$table` WHERE civil_record_id = ? LIMIT 1");
        $exists->execute([$recordId]);
        if ($exists->fetchColumn()) {
            continue;
        }

        saveCivilRecordTypeDetails($pdo, $recordId, $type, $values);
    }

    setSetting('civil_record_type_tables_migrated', '1');
}

/** @return array<string, mixed> */
function mergeCivilRecordRows(array $base, ?array $detail, string $type): array
{
    $merged = $base;
    if ($detail !== null) {
        unset($detail['civil_record_id']);
        $merged = array_merge($merged, $detail);
    }

    foreach (civilRecordTypeFieldNames($type) as $field) {
        if (($merged[$field] ?? null) === null && array_key_exists($field, $base) && $base[$field] !== null && $base[$field] !== '') {
            $merged[$field] = $base[$field];
        }
    }

    return $merged;
}

function fetchCivilRecordTypeDetails(PDO $pdo, int $recordId, string $type): ?array
{
    $table = civilRecordDetailTableName($type);
    if ($table === '') {
        return null;
    }

    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE civil_record_id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return array<string, mixed> Default values for one record type's detail fields. */
function civilRecordTypeDefaults(string $type): array
{
    $defaults = [];
    foreach (civilRecordTypeFieldNames($type) as $field) {
        $defaults[$field] = match ($field) {
            'stillbirth'  => 0,
            'birth_type'  => 'Single',
            default       => null,
        };
    }

    return $defaults;
}

function saveCivilRecordTypeDetails(PDO $pdo, int $recordId, string $type, array $data, bool $insertOnly = false): void
{
    $table = civilRecordDetailTableName($type);
    if ($table === '' || $recordId <= 0) {
        return;
    }

    $fields = civilRecordTypeFieldNames($type);
    $provided = $data['_provided_fields'] ?? null;
    unset($data['_provided_fields']);

    $payload = [];
    foreach ($fields as $field) {
        if ($provided !== null && !in_array($field, $provided, true)) {
            continue;
        }
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $payload[$field] = $data[$field];
    }
    if ($payload === []) {
        return;
    }

    if (!$insertOnly) {
        $exists = $pdo->prepare("SELECT 1 FROM `$table` WHERE civil_record_id = ? LIMIT 1");
        $exists->execute([$recordId]);
        if ($exists->fetchColumn()) {
            $sets = implode(', ', array_map(static fn ($field) => "`$field` = ?", array_keys($payload)));
            $stmt = $pdo->prepare("UPDATE `$table` SET $sets WHERE civil_record_id = ?");
            $stmt->execute([...array_values($payload), $recordId]);

            return;
        }
    }

    $columns = ['civil_record_id', ...array_keys($payload)];
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare(
        "INSERT INTO `$table` (" . implode(', ', array_map(static fn ($c) => "`$c`", $columns)) . ") VALUES ($placeholders)"
    );
    $stmt->execute([$recordId, ...array_values($payload)]);
}

function fetchFullCivilRecord(PDO $pdo, int $recordId): ?array
{
    if ($recordId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM civil_records WHERE id = ? AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([$recordId]);
    $base = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$base) {
        return null;
    }

    return hydrateCivilRecordRow($pdo, $base);
}

function hydrateCivilRecordRow(PDO $pdo, array $base): array
{
    $recordId = (int) ($base['id'] ?? 0);
    $type = (string) ($base['record_type'] ?? '');
    if ($recordId <= 0 || !in_array($type, ['birth', 'death', 'marriage'], true)) {
        return $base;
    }

    $detail = fetchCivilRecordTypeDetails($pdo, $recordId, $type);

    return mergeCivilRecordRows($base, $detail, $type);
}

/** @param list<array<string, mixed>> $bases */
function hydrateCivilRecordRows(PDO $pdo, array $bases): array
{
    if ($bases === []) {
        return [];
    }

    $indexByType = [
        'birth'    => [],
        'death'    => [],
        'marriage' => [],
    ];

    foreach ($bases as $index => $base) {
        $recordId = (int) ($base['id'] ?? 0);
        $type = (string) ($base['record_type'] ?? '');
        if ($recordId > 0 && isset($indexByType[$type])) {
            $indexByType[$type][$recordId] = $index;
        }
    }

    $detailsById = [];
    foreach ($indexByType as $type => $idMap) {
        if ($idMap === []) {
            continue;
        }

        $table = civilRecordDetailTableName($type);
        $ids = array_keys($idMap);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE civil_record_id IN ($placeholders)");
        $stmt->execute($ids);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $detail) {
            $detailsById[(int) $detail['civil_record_id']] = $detail;
        }
    }

    $hydrated = $bases;
    foreach ($bases as $index => $base) {
        $recordId = (int) ($base['id'] ?? 0);
        $type = (string) ($base['record_type'] ?? '');
        $detail = $detailsById[$recordId] ?? null;
        if ($detail !== null && in_array($type, ['birth', 'death', 'marriage'], true)) {
            $hydrated[$index] = mergeCivilRecordRows($base, $detail, $type);
        }
    }

    return $hydrated;
}

function saveCivilRecord(PDO $pdo, array $data, ?int $recordId = null, array $options = []): int
{
    $type = (string) ($data['record_type'] ?? '');
    if (!in_array($type, ['birth', 'death', 'marriage'], true)) {
        throw new InvalidArgumentException('Invalid record type.');
    }

    $baseFields = civilRecordBaseFieldNames();
    $basePayload = [];
    foreach ($baseFields as $field) {
        if (array_key_exists($field, $data)) {
            $basePayload[$field] = $data[$field];
        }
    }

    if ($recordId === null) {
        $columns = array_keys($basePayload);
        $stmt = $pdo->prepare(
            'INSERT INTO civil_records (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $stmt->execute(array_values($basePayload));
        $recordId = (int) $pdo->lastInsertId();
    } else {
        $sets = implode(', ', array_map(static fn ($field) => "$field = ?", array_keys($basePayload)));
        $stmt = $pdo->prepare("UPDATE civil_records SET $sets WHERE id = ?");
        $stmt->execute([...array_values($basePayload), $recordId]);
    }

    saveCivilRecordTypeDetails(
        $pdo,
        $recordId,
        $type,
        $data,
        !empty($options['insert_details_only'])
    );

    return $recordId;
}

/** One-time legacy sync for derived civil record columns (event_date, registry_number). */
function syncCivilRecordDerivedFields(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $flagFile = dirname(__DIR__) . '/storage/.civil_record_derived_sync_v1';
    if (is_file($flagFile)) {
        return;
    }

    try {
        $pdo->exec(
            "UPDATE civil_records SET event_date = birth_date
             WHERE record_type = 'birth' AND birth_date IS NOT NULL
             AND (event_date IS NULL OR event_date = '')"
        );
        $pdo->exec(
            "UPDATE civil_records cr
             INNER JOIN death_record_details drd ON drd.civil_record_id = cr.id
             SET cr.registry_number = drd.code_number
             WHERE (cr.registry_number IS NULL OR cr.registry_number = '')
             AND drd.code_number IS NOT NULL AND drd.code_number != ''"
        );
        if (!is_dir(dirname($flagFile))) {
            @mkdir(dirname($flagFile), 0755, true);
        }
        @touch($flagFile);
    } catch (Throwable $e) {
        // Non-fatal; will retry on next request until flag is written.
    }
}
