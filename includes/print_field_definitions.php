<?php
/**
 * Field name definitions for Municipal Forms 102, 97, 103 (Revised August 2016).
 * Coordinates are seeded in a grid; use Print Template Calibration to align to physical forms.
 */

function printCertificateMeta(): array
{
    return [
        'birth' => [
            'form_number' => '102',
            'title'       => 'Certificate of Live Birth',
            'reference'   => 'assets/print/forms/birth-front.png',
        ],
        'marriage' => [
            'form_number' => '97',
            'title'       => 'Certificate of Marriage',
            'reference'   => 'assets/print/forms/marriage-front.png',
        ],
        'death' => [
            'form_number' => '103',
            'title'       => 'Certificate of Death',
            'reference'   => 'assets/print/forms/death-front.png',
        ],
    ];
}

function printFieldCatalog(): array
{
    return [
        'birth' => [
            'front' => [
                'province' => 'Province',
                'city_municipality' => 'City/Municipality',
                'registry_number' => 'Registry Number',
                'child_first_name' => 'Child First Name',
                'child_middle_name' => 'Child Middle Name',
                'child_last_name' => 'Child Last Name',
                'sex' => 'Sex',
                'birth_day' => 'Birth Day',
                'birth_month' => 'Birth Month',
                'birth_year' => 'Birth Year',
                'birth_place' => 'Place of Birth',
                'birth_type' => 'Type of Birth',
                'multiple_birth_child_was' => 'If Multiple Birth, Child Was',
                'birth_order' => 'Birth Order',
                'birth_weight' => 'Weight at Birth',
                'mother_first_name' => 'Mother First Name',
                'mother_middle_name' => 'Mother Middle Name',
                'mother_last_name' => 'Mother Last Name',
                'mother_citizenship' => 'Mother Citizenship',
                'mother_religion' => 'Mother Religion',
                'mother_children_born_alive' => 'Total Children Born Alive',
                'mother_children_still_living' => 'Children Still Living',
                'mother_children_born_alive_now_dead' => 'Children Born Alive but Now Dead',
                'mother_occupation' => 'Mother Occupation',
                'mother_age' => 'Mother Age',
                'mother_residence' => 'Mother Residence',
                'father_first_name' => 'Father First Name',
                'father_middle_name' => 'Father Middle Name',
                'father_last_name' => 'Father Last Name',
                'father_citizenship' => 'Father Citizenship',
                'father_religion' => 'Father Religion',
                'father_occupation' => 'Father Occupation',
                'father_age' => 'Father Age',
                'father_residence' => 'Father Residence',
                'parents_marriage_day' => 'Parents Marriage Day',
                'parents_marriage_month' => 'Parents Marriage Month',
                'parents_marriage_year' => 'Parents Marriage Year',
                'parents_marriage_place' => 'Parents Marriage Place',
                'attendant_type' => '21a Attendant Type',
                'birth_time' => '21b Time of Birth',
                'attendant_cert_name' => '21b Attendant Name in Print',
                'attendant_cert_title' => '21b Attendant Title or Position',
                'attendant_cert_address' => '21b Attendant Address',
                'attendant_cert_date' => '21b Attendant Certification Date',
                'informant_name' => '22 Informant Name in Print',
                'informant_relationship' => '22 Informant Relationship',
                'informant_address' => '22 Informant Address',
                'informant_date' => '22 Informant Date',
                'prepared_by_name' => '23 Prepared By Name in Print',
                'prepared_by_title' => '23 Prepared By Title or Position',
                'prepared_by_date' => '23 Prepared By Date',
                'received_by_name' => '24 Received By Name in Print',
                'received_by_title' => '24 Received By Title or Position',
                'received_by_date' => '24 Received By Date',
                'registrar_name' => '25 Civil Registrar Name in Print',
                'registrar_title' => '25 Civil Registrar Title or Position',
                'registrar_date' => '25 Registration Date',
                'remarks_annotations' => 'Remarks/Annotations',
                'lcro_box_8' => 'LCRO Footer Box 8',
                'lcro_box_9' => 'LCRO Footer Box 9',
                'lcro_box_11' => 'LCRO Footer Box 11',
                'lcro_box_13' => 'LCRO Footer Box 13',
                'lcro_box_15' => 'LCRO Footer Box 15',
                'lcro_box_16' => 'LCRO Footer Box 16',
                'lcro_box_17' => 'LCRO Footer Box 17',
                'lcro_box_19' => 'LCRO Footer Box 19',
            ],
            'back' => [
                'paternity_father_name' => 'Paternity Affidavit — Father Name',
                'paternity_mother_name' => 'Paternity Affidavit — Mother Name',
                'paternity_child_name' => 'Paternity Affidavit — Child Name',
                'paternity_birth_date' => 'Paternity Affidavit — Birth Date',
                'paternity_birth_place' => 'Paternity Affidavit — Birth Place',
                'delayed_birth_affiant_name' => 'Delayed Birth — Affiant Name',
                'delayed_birth_affiant_address' => 'Delayed Birth — Affiant Address',
                'delayed_birth_place' => 'Delayed Birth — Place of Birth',
                'delayed_birth_date' => 'Delayed Birth — Date of Birth',
                'delayed_birth_child_name' => 'Delayed Birth — Child Name',
                'delayed_birth_attendant' => 'Delayed Birth — Attendant',
                'delayed_birth_citizenship' => 'Delayed Birth — Citizenship',
                'delayed_birth_parents_marriage_date' => 'Delayed Birth — Parents Marriage Date',
                'delayed_birth_parents_marriage_place' => 'Delayed Birth — Parents Marriage Place',
                'delayed_birth_reason' => 'Delayed Birth — Reason for Delay',
            ],
        ],
        'marriage' => [
            'front' => [
                'province' => 'Province',
                'city_municipality' => 'City/Municipality',
                'registry_number' => 'Registry Number',
                'husband_first_name' => 'Husband First Name',
                'husband_middle_name' => 'Husband Middle Name',
                'husband_last_name' => 'Husband Last Name',
                'husband_birth_day' => 'Husband Birth Day',
                'husband_birth_month' => 'Husband Birth Month',
                'husband_birth_year' => 'Husband Birth Year',
                'husband_age' => 'Husband Age',
                'husband_birth_place' => 'Husband Place of Birth',
                'husband_sex' => 'Husband Sex',
                'husband_citizenship' => 'Husband Citizenship',
                'husband_residence' => 'Husband Residence',
                'husband_religion' => 'Husband Religion',
                'husband_civil_status' => 'Husband Civil Status',
                'husband_father_name' => 'Husband Father Name',
                'husband_father_citizenship' => 'Husband Father Citizenship',
                'husband_mother_maiden_name' => 'Husband Mother Maiden Name',
                'husband_mother_citizenship' => 'Husband Mother Citizenship',
                'husband_consent_person_name' => 'Husband Consent Person Name',
                'husband_consent_relationship' => 'Husband Consent Relationship',
                'husband_consent_residence' => 'Husband Consent Residence',
                'wife_first_name' => 'Wife First Name',
                'wife_middle_name' => 'Wife Middle Name',
                'wife_last_name' => 'Wife Last Name',
                'wife_birth_day' => 'Wife Birth Day',
                'wife_birth_month' => 'Wife Birth Month',
                'wife_birth_year' => 'Wife Birth Year',
                'wife_age' => 'Wife Age',
                'wife_birth_place' => 'Wife Place of Birth',
                'wife_sex' => 'Wife Sex',
                'wife_citizenship' => 'Wife Citizenship',
                'wife_residence' => 'Wife Residence',
                'wife_religion' => 'Wife Religion',
                'wife_civil_status' => 'Wife Civil Status',
                'wife_father_name' => 'Wife Father Name',
                'wife_father_citizenship' => 'Wife Father Citizenship',
                'wife_mother_maiden_name' => 'Wife Mother Maiden Name',
                'wife_mother_citizenship' => 'Wife Mother Citizenship',
                'wife_consent_person_name' => 'Wife Consent Person Name',
                'wife_consent_relationship' => 'Wife Consent Relationship',
                'wife_consent_residence' => 'Wife Consent Residence',
                'marriage_place' => 'Place of Marriage',
                'marriage_day' => 'Marriage Day',
                'marriage_month' => 'Marriage Month',
                'marriage_year' => 'Marriage Year',
                'marriage_time' => 'Time of Marriage',
                'solemnizing_officer' => 'Solemnizing Officer',
                'witnesses' => 'Witnesses',
                'received_by_name' => '21 Received By Name in Print',
                'received_by_title' => '21 Received By Title or Position',
                'received_by_date' => '21 Received By Date',
                'registrar_name' => '22 Civil Registrar Name in Print',
                'registrar_title' => '22 Civil Registrar Title or Position',
                'registrar_date' => '22 Registration Date',
                'remarks_annotations' => 'Remarks/Annotations',
                'lcro_box_4bh' => 'LCRO Footer 4bH',
                'lcro_box_4bw' => 'LCRO Footer 4bW',
                'lcro_box_5h' => 'LCRO Footer 5H',
                'lcro_box_5w' => 'LCRO Footer 5W',
                'lcro_box_6h' => 'LCRO Footer 6H',
                'lcro_box_6w' => 'LCRO Footer 6W',
                'lcro_box_7h' => 'LCRO Footer 7H',
                'lcro_box_7w' => 'LCRO Footer 7W',
            ],
            'back' => [
                'witness_1' => 'Witness 1',
                'witness_2' => 'Witness 2',
                'witness_3' => 'Witness 3',
                'affidavit_officer_name' => 'Affidavit Solemnizing Officer — Name',
                'affidavit_officer_address' => 'Affidavit Solemnizing Officer — Address',
                'affidavit_husband_name' => 'Affidavit — Husband Name',
                'affidavit_wife_name' => 'Affidavit — Wife Name',
                'affidavit_marriage_date' => 'Affidavit — Marriage Date',
                'delayed_marriage_affiant_name' => 'Delayed Marriage — Affiant Name',
                'delayed_marriage_affiant_address' => 'Delayed Marriage — Affiant Address',
                'delayed_marriage_spouse_name' => 'Delayed Marriage — Spouse Name',
                'delayed_marriage_place' => 'Delayed Marriage — Place',
                'delayed_marriage_date' => 'Delayed Marriage — Date',
                'delayed_marriage_reason' => 'Delayed Marriage — Reason',
            ],
        ],
        'death' => [
            'front' => [
                'province' => 'Province',
                'city_municipality' => 'City/Municipality',
                'registry_number' => 'Registry Number',
                'deceased_first_name' => 'Deceased First Name',
                'deceased_middle_name' => 'Deceased Middle Name',
                'deceased_last_name' => 'Deceased Last Name',
                'sex' => 'Sex',
                'death_day' => 'Death Day',
                'death_month' => 'Death Month',
                'death_year' => 'Death Year',
                'birth_day' => 'Birth Day',
                'birth_month' => 'Birth Month',
                'birth_year' => 'Birth Year',
                'age_at_death' => 'Age at Death',
                'place_of_death' => 'Place of Death',
                'civil_status' => 'Civil Status',
                'religion' => 'Religion/Religious Sect',
                'citizenship' => 'Citizenship',
                'residence' => 'Residence',
                'occupation' => 'Occupation',
                'father_first_name' => 'Father First Name',
                'father_middle_name' => 'Father Middle Name',
                'father_last_name' => 'Father Last Name',
                'mother_first_name' => 'Mother First Name',
                'mother_middle_name' => 'Mother Middle Name',
                'mother_last_name' => 'Mother Last Name',
                'immediate_cause' => 'Immediate Cause',
                'contributory_cause' => 'Contributory Cause',
                'autopsy_performed' => 'Autopsy',
                'attending_physician' => 'Attending Physician',
                'surviving_spouse_name' => 'Surviving Spouse',
                'surviving_spouse_address' => 'Spouse Address',
                'place_of_burial' => 'Place of Burial',
                'death_time' => 'Time of Death',
                'registration_date' => 'Date of Registration',
                'attendant_type' => '21a Attendant Type',
                'death_cert_name' => '22 Certifier Name in Print',
                'death_cert_title' => '22 Certifier Title or Position',
                'death_cert_address' => '22 Certifier Address',
                'death_cert_date' => '22 Certification Date',
                'reviewed_by_name' => 'Reviewed By Name in Print',
                'reviewed_by_date' => 'Reviewed By Date',
                'corpse_disposal' => '23 Corpse Disposal',
                'burial_permit_number' => '24a Burial/Cremation Permit No.',
                'burial_permit_date' => '24a Permit Date Issued',
                'transfer_permit_number' => '24b Transfer Permit No.',
                'transfer_permit_date' => '24b Transfer Permit Date',
                'cemetery_crematory' => '25 Cemetery/Crematory Name and Address',
                'informant_name' => '26 Informant Name in Print',
                'informant_relationship' => '26 Informant Relationship',
                'informant_address' => '26 Informant Address',
                'informant_date' => '26 Informant Date',
                'prepared_by_name' => '27 Prepared By Name in Print',
                'prepared_by_title' => '27 Prepared By Title or Position',
                'prepared_by_date' => '27 Prepared By Date',
                'received_by_name' => '28 Received By Name in Print',
                'received_by_title' => '28 Received By Title or Position',
                'received_by_date' => '28 Received By Date',
                'registrar_name' => '29 Civil Registrar Name in Print',
                'registrar_title' => '29 Civil Registrar Title or Position',
                'registrar_date' => '29 Registration Date',
                'remarks_annotations' => 'Remarks/Annotations',
                'lcro_box_5' => 'LCRO Footer Box 5',
                'lcro_box_8' => 'LCRO Footer Box 8',
                'lcro_box_9' => 'LCRO Footer Box 9',
                'lcro_box_10' => 'LCRO Footer Box 10',
                'lcro_box_11' => 'LCRO Footer Box 11',
                'lcro_box_19a' => 'LCRO Footer Box 19a',
                'lcro_box_19c' => 'LCRO Footer Box 19c',
            ],
            'back' => [
                'child_age_mother' => 'Age of Mother (0-7 days)',
                'child_delivery_method' => 'Method of Delivery',
                'child_pregnancy_length' => 'Length of Pregnancy',
                'child_birth_type' => 'Type of Birth',
                'child_birth_order' => 'If Multiple Birth, Child Was',
                'infant_cause_a' => 'Infant Cause A',
                'infant_cause_b' => 'Infant Cause B',
                'infant_cause_c' => 'Infant Cause C',
                'infant_cause_d' => 'Infant Cause D',
                'infant_cause_e' => 'Infant Cause E',
                'postmortem_cause' => 'Postmortem Cause of Death',
                'embalmer_deceased_name' => 'Embalmer — Deceased Name',
                'delayed_death_affiant_name' => 'Delayed Death — Affiant Name',
                'delayed_death_affiant_address' => 'Delayed Death — Affiant Address',
                'delayed_death_deceased_name' => 'Delayed Death — Deceased Name',
                'delayed_death_date' => 'Delayed Death — Date of Death',
                'delayed_death_place' => 'Delayed Death — Place of Death',
                'delayed_death_burial_place' => 'Delayed Death — Burial Place',
                'delayed_death_attendant' => 'Delayed Death — Attendant',
                'delayed_death_cause' => 'Delayed Death — Cause',
                'delayed_death_reason' => 'Delayed Death — Reason for Delay',
            ],
        ],
    ];
}

/** @return list<string> Print certificate fill-in field names (front + back) for CSV import/export. */
function printFillCsvColumns(string $type): array
{
    $catalog = printFieldCatalog()[$type] ?? null;
    if ($catalog === null) {
        return ['registry_number'];
    }

    return array_merge(
        array_keys($catalog['front'] ?? []),
        array_keys($catalog['back'] ?? [])
    );
}

/** @return list<string> */
function printFillFieldNames(string $type): array
{
    return printFillCsvColumns($type);
}

/**
 * Recommended font sizes (pt) for overlay printing on pre-printed civil registry forms.
 *
 * @return array{heading: float, label: float, data: float, name: float, date: float, date_part: float, place: float, small: float}
 */
function printFontSizePresets(): array
{
    return [
        'heading'   => 11.0, // 10–12 pt: province / city-municipality headers
        'label'     => 8.5,  // 8–9 pt: reserved for label-only overlays
        'data'      => 9.5,  // 9–10 pt: general entered / coded information
        'name'      => 9.5,  // 9–10 pt: person names
        'date'      => 9.5,  // 9–10 pt: full date values
        'date_part' => 9.0,  // 9–10 pt: day / month / year cells
        'place'     => 8.5,  // 8–9 pt: addresses and places
        'small'     => 7.5,  // 7–8 pt: registry numbers, codes, remarks
    ];
}

function printRecommendedFontSize(string $fieldName): float
{
    return 10.0;
}

function printDefaultPaperSize(): array
{
    return [
        'paper_width_mm'  => printOfficialPaperWidthMm(),
        'paper_height_mm' => printOfficialPaperHeightMm(),
        'orientation'     => 'portrait',
        'margin_top_mm'   => 0,
        'margin_left_mm'  => 0,
    ];
}

/** Official Municipal Forms 102, 97, 103 width — 8.5 inches. */
function printOfficialPaperWidthMm(): float
{
    return 215.9;
}

/** Official Municipal Forms 102, 97, 103 height — matches scanned form aspect ratio (616×1024 px). */
function printOfficialPaperHeightMm(): float
{
    return 358.9;
}

function printPaperSizeLabel(?float $widthMm = null, ?float $heightMm = null, bool $compact = false): string
{
    $widthMm = $widthMm ?? printOfficialPaperWidthMm();
    $heightMm = $heightMm ?? printOfficialPaperHeightMm();

    if ($compact) {
        return sprintf('%.1f × %.1f mm · Legal bond', $widthMm, $heightMm);
    }

    return sprintf(
        '%.1f mm × %.1f mm (8.5″ × 14.1″ municipal long bond — Forms 102 / 97 / 103)',
        $widthMm,
        $heightMm
    );
}

/** CSS @page size — inches so browsers map to Legal / long bond instead of ignoring custom mm. */
function printPageCssSize(): string
{
    $heightIn = round(printOfficialPaperHeightMm() / 25.4, 3);

    return '8.5in ' . $heightIn . 'in';
}

/** Printer dialog hint — closest named size most drivers expose. */
function printPaperSizePrinterHint(): string
{
    return 'Legal (8.5 × 14 in) or Custom 215.9 × 358.9 mm — never Postcard or A4';
}

function printSeedFieldLayout(string $certificateType, string $pageSide): array
{
    $catalog = printFieldCatalog()[$certificateType][$pageSide] ?? [];
    $presets = printFieldCoordinatePresets()[$certificateType][$pageSide] ?? [];
    $fields = [];
    $y = $pageSide === 'front' ? 42.0 : 36.0;
    $index = 0;

    foreach ($catalog as $fieldName => $label) {
        $fontSize = printRecommendedFontSize($fieldName);
        $preset = $presets[$fieldName] ?? null;
        if ($preset) {
            $fields[] = array_merge([
                'field_name'  => $fieldName,
                'label'       => $label,
                'font_family' => 'Arial',
                'font_size'   => $fontSize,
                'font_weight' => 'normal',
                'alignment'   => 'left',
                'max_length'  => 120,
                'line_height' => 1.2,
                'enabled'     => 1,
            ], $preset);
            continue;
        }

        $col = $index % 2;
        $row = intdiv($index, 2);
        $fields[] = [
            'field_name'  => $fieldName,
            'label'       => $label,
            'x_mm'        => $col === 0 ? 14.0 : 112.0,
            'y_mm'        => $y + ($row * 6.5),
            'width_mm'    => 88.0,
            'height_mm'   => 5.5,
            'font_family' => 'Arial',
            'font_size'   => $fontSize,
            'font_weight' => 'normal',
            'alignment'   => 'left',
            'max_length'  => 120,
            'line_height' => 1.2,
            'enabled'     => 1,
        ];
        $index++;
    }

    return $fields;
}

function printFieldHint(string $fieldName): string
{
    static $hints = [
        'paternity_father_name'                 => "(Father's name)",
        'paternity_mother_name'                 => "(Mother's name)",
        'paternity_child_name'                  => "(Child's name)",
        'paternity_birth_date'                  => '(Date of birth)',
        'paternity_birth_place'                 => '(Place of birth)',
        'delayed_birth_affiant_name'            => "(Affiant's name)",
        'delayed_birth_affiant_address'         => '(Residence address)',
        'delayed_birth_place'                   => '(Place of birth)',
        'delayed_birth_date'                    => '(Date of birth)',
        'delayed_birth_child_name'              => "(Child's name)",
        'delayed_birth_attendant'               => '(Attendant at birth)',
        'delayed_birth_citizenship'             => '(Citizenship)',
        'delayed_birth_parents_marriage_date'   => '(Marriage date)',
        'delayed_birth_parents_marriage_place'  => '(Marriage place)',
        'delayed_birth_reason'                  => '(Reason for delay)',
    ];

    return $hints[$fieldName] ?? '';
}

/** @return array<string, array<string, array<string, array<string, float|int|string>>>> */
function printFieldCoordinatePresets(): array
{
    $line = static fn (float $x, float $y, float $w = 88.0, float $h = 4.5): array => [
        'x_mm' => $x, 'y_mm' => $y, 'width_mm' => $w, 'height_mm' => $h,
    ];

    return [
        'birth' => [
            'front' => [
                'province'          => $line(37, 33.5, 86),
                'city_municipality' => $line(50, 39.5, 76),
                'registry_number'   => $line(157.32, 36.40, 27.80, 5.93),
                'child_first_name'  => $line(47, 50.5, 54),
                'child_middle_name' => $line(97, 50.5, 45),
                'child_last_name'   => $line(147, 50.5, 58),
                'sex'               => $line(42, 59.5, 29),
                'birth_day'         => $line(115, 59.5, 17),
                'birth_month'       => $line(141, 59.5, 22),
                'birth_year'        => $line(176, 59.5, 25),
                'birth_place'       => $line(58.90, 70.5, 147),
                'birth_type'        => $line(29, 84.5, 41),
                'multiple_birth_child_was' => $line(78, 84.5, 44),
                'birth_order'       => $line(131, 84.5, 35),
                'birth_weight'      => $line(176, 84.5, 21),
                'mother_first_name' => $line(46, 95.5, 45),
                'mother_middle_name'=> $line(101, 95, 34.04, 5.73),
                'mother_last_name'  => $line(155, 94.5, 50, 5.5),
                'mother_citizenship'=> $line(28, 104.5, 84),
                'mother_religion'   => $line(119, 104.5, 87),
                'mother_children_born_alive' => $line(30, 117.5, 21),
                'mother_children_still_living' => $line(56, 117.5, 25),
                'mother_children_born_alive_now_dead' => $line(88, 117.5, 29),
                'mother_occupation' => $line(121, 117.5, 52),
                'mother_age'        => $line(177.50, 117.17, 27.47),
                'mother_residence'  => $line(43.84, 128.18, 162),
                'father_first_name' => $line(43, 139.5, 43),
                'father_middle_name'=> $line(94, 139.5, 44),
                'father_last_name'  => $line(152, 139.5, 49),
                'father_citizenship'=> $line(27, 150.5, 42.92),
                'father_religion'   => $line(73.76, 150.62, 48.97),
                'father_occupation' => $line(127.71, 149.69, 44.34),
                'father_age'        => $line(176.54, 151.21, 29.71),
                'father_residence'  => $line(47.29, 162.81, 158),
                'parents_marriage_day'   => $line(55.98, 178.45, 18),
                'parents_marriage_month' => $line(39, 178.45, 16),
                'parents_marriage_year'  => $line(74, 178.45, 17),
                'parents_marriage_place' => $line(106.59, 178.03, 99.45),
                'attendant_type'        => $line(21.33, 191.68, 8, 4),
                'birth_time'            => $line(129.18, 199.96, 18.30, 4.5),
                'attendant_cert_name'   => $line(41.98, 214.61, 67, 4.5),
                'attendant_cert_title'  => $line(43, 221, 67, 4),
                'attendant_cert_address'=> $line(129.77, 208.77, 72.51, 4.5),
                'attendant_cert_date'   => $line(122.83, 219.46, 59, 4),
                'informant_name'        => $line(41, 245, 69, 4),
                'informant_relationship'=> $line(53, 251, 50, 4),
                'informant_address'     => $line(34, 257, 78, 4),
                'informant_date'        => $line(37, 263, 40, 4),
                'prepared_by_name'      => $line(137, 246, 61, 4),
                'prepared_by_title'     => $line(139, 252, 58, 4),
                'prepared_by_date'      => $line(132, 257, 40, 4),
                'received_by_name'      => $line(43, 279, 60, 4),
                'received_by_title'     => $line(44, 285, 62, 4),
                'received_by_date'      => $line(37.91, 291.77, 40, 4),
                'registrar_name'        => $line(138, 279, 65, 4),
                'registrar_title'       => $line(140, 285, 64, 4),
                'registrar_date'        => $line(134, 291, 40, 4),
                'remarks_annotations'   => $line(21.80, 301.97, 182, 20),
                'lcro_box_8'            => $line(22.78, 335.08, 11, 5),
                'lcro_box_9'            => $line(34.57, 334.90, 13, 5),
                'lcro_box_11'           => $line(47.81, 335.08, 19, 5),
                'lcro_box_13'           => $line(66.73, 334.90, 45, 5),
                'lcro_box_15'           => $line(112, 334.90, 11, 5),
                'lcro_box_16'           => $line(123.87, 334.90, 12, 5),
                'lcro_box_17'           => $line(136.66, 334.53, 20, 5),
                'lcro_box_19'           => $line(156.76, 334.35, 27, 5),
            ],
            'back' => [
                'paternity_father_name' => $line(34, 51, 60.96),
                'paternity_mother_name' => $line(16, 46, 188),
                'paternity_child_name'  => $line(16, 54, 188),
                'paternity_birth_date'  => $line(27, 28, 46, 5.5),
                'paternity_birth_place' => $line(81, 29, 88, 5.5),
                'delayed_birth_affiant_name' => $line(16, 130, 188),
                'delayed_birth_affiant_address' => $line(14, 42.5, 88, 5.5),
                'delayed_birth_place' => $line(112, 42.5, 88, 5.5),
                'delayed_birth_date' => $line(14, 49, 88, 5.5),
                'delayed_birth_child_name' => $line(112, 49, 88, 5.5),
                'delayed_birth_attendant' => $line(14, 55.5, 88, 5.5),
                'delayed_birth_citizenship' => $line(112, 55.5, 88, 5.5),
                'delayed_birth_parents_marriage_date' => $line(107, 201, 42, 5.5),
                'delayed_birth_parents_marriage_place' => $line(154.90, 200, 42, 5.5),
                'delayed_birth_reason'  => $line(30, 231, 161),
            ],
        ],
        'marriage' => [
            'front' => [
                'province'          => $line(30, 28.5, 86),
                'city_municipality' => $line(40, 34.5, 76),
                'registry_number'   => $line(170, 26.5, 34, 6),
                'husband_first_name'=> $line(16, 46.5, 42),
                'husband_middle_name'=> $line(16, 52.5, 42),
                'husband_last_name' => $line(16, 58.5, 42),
                'husband_birth_day' => $line(16, 64.5, 18),
                'husband_birth_month'=> $line(36, 64.5, 22),
                'husband_birth_year'=> $line(60, 64.5, 22),
                'husband_sex'       => $line(16, 64.5, 42),
                'husband_age'       => $line(16, 70.5, 42),
                'husband_birth_place'=> $line(16, 76.5, 42),
                'husband_citizenship'=> $line(16, 82.5, 42),
                'husband_residence' => $line(16, 88.5, 42),
                'husband_religion'  => $line(16, 94.5, 42),
                'husband_civil_status'=> $line(16, 100.5, 42),
                'husband_father_name'=> $line(16, 106.5, 42),
                'husband_father_citizenship'=> $line(16, 108.5, 42),
                'husband_mother_maiden_name'=> $line(16, 112.5, 42),
                'husband_mother_citizenship'=> $line(16, 114.5, 42),
                'husband_consent_person_name'=> $line(16, 118.5, 42),
                'husband_consent_relationship'=> $line(16, 122.5, 42),
                'husband_consent_residence'=> $line(16, 126.5, 42),
                'wife_first_name'   => $line(114, 46.5, 42),
                'wife_middle_name'  => $line(114, 52.5, 42),
                'wife_last_name'    => $line(114, 58.5, 42),
                'wife_birth_day'    => $line(114, 64.5, 18),
                'wife_birth_month'  => $line(134, 64.5, 22),
                'wife_birth_year'   => $line(158, 64.5, 22),
                'wife_sex'          => $line(114, 64.5, 42),
                'wife_age'          => $line(114, 70.5, 42),
                'wife_birth_place'  => $line(114, 76.5, 42),
                'wife_citizenship'  => $line(114, 82.5, 42),
                'wife_residence'    => $line(114, 88.5, 42),
                'wife_religion'     => $line(114, 94.5, 42),
                'wife_civil_status' => $line(114, 100.5, 42),
                'wife_father_name'  => $line(114, 106.5, 42),
                'wife_father_citizenship'=> $line(114, 108.5, 42),
                'wife_mother_maiden_name'=> $line(114, 112.5, 42),
                'wife_mother_citizenship'=> $line(114, 114.5, 42),
                'wife_consent_person_name'=> $line(114, 118.5, 42),
                'wife_consent_relationship'=> $line(114, 122.5, 42),
                'wife_consent_residence'=> $line(114, 126.5, 42),
                'marriage_place'    => $line(16, 140.5, 188),
                'marriage_day'      => $line(54, 146.5, 22),
                'marriage_month'    => $line(84, 146.5, 32),
                'marriage_year'     => $line(124, 146.5, 32),
                'marriage_time'     => $line(16, 152.5, 188),
                'solemnizing_officer'=> $line(16, 170.5, 188),
                'witnesses'         => $line(16, 180.5, 188),
                'received_by_name'  => $line(58, 248, 50),
                'received_by_title' => $line(58, 253, 50),
                'received_by_date'  => $line(16, 258, 40),
                'registrar_name'    => $line(154, 248, 50),
                'registrar_title'   => $line(154, 253, 50),
                'registrar_date'    => $line(112, 258, 40),
                'remarks_annotations'=> $line(16, 272, 188, 16),
                'lcro_box_4bh'      => $line(18, 318, 20, 5),
                'lcro_box_4bw'      => $line(42, 318, 20, 5),
                'lcro_box_5h'       => $line(66, 318, 20, 5),
                'lcro_box_5w'       => $line(90, 318, 20, 5),
                'lcro_box_6h'       => $line(114, 318, 20, 5),
                'lcro_box_6w'       => $line(138, 318, 20, 5),
                'lcro_box_7h'       => $line(162, 318, 20, 5),
                'lcro_box_7w'       => $line(186, 318, 20, 5),
            ],
            'back' => [
                'witness_1' => $line(16, 34, 188),
                'witness_2' => $line(16, 42, 188),
                'witness_3' => $line(16, 50, 188),
                'affidavit_officer_name' => $line(16, 72, 188),
                'affidavit_officer_address' => $line(16, 80, 188),
                'affidavit_husband_name' => $line(16, 88, 188),
                'affidavit_wife_name' => $line(16, 96, 188),
                'affidavit_marriage_date' => $line(16, 104, 188),
                'delayed_marriage_affiant_name' => $line(16, 140, 188),
                'delayed_marriage_affiant_address' => $line(16, 148, 188),
                'delayed_marriage_spouse_name' => $line(16, 156, 188),
                'delayed_marriage_place' => $line(16, 164, 188),
                'delayed_marriage_date' => $line(16, 172, 188),
                'delayed_marriage_reason' => $line(16, 180, 188),
            ],
        ],
        'death' => [
            'front' => [
                'province'          => $line(30, 28.5, 86),
                'city_municipality' => $line(40, 34.5, 76),
                'registry_number'   => $line(170, 26.5, 34, 6),
                'deceased_first_name'=> $line(16, 42.5, 54),
                'deceased_middle_name'=> $line(78, 42.5, 54),
                'deceased_last_name'=> $line(140, 42.5, 64),
                'sex'               => $line(16, 47.5, 42),
                'death_day'         => $line(54, 52.5, 22),
                'death_month'       => $line(84, 52.5, 32),
                'death_year'        => $line(124, 52.5, 32),
                'birth_day'         => $line(54, 57.5, 22),
                'birth_month'       => $line(84, 57.5, 32),
                'birth_year'        => $line(124, 57.5, 32),
                'age_at_death'      => $line(16, 62.5, 188),
                'place_of_death'    => $line(16, 67.5, 188),
                'civil_status'      => $line(16, 72.5, 188),
                'religion'          => $line(19.43, 89.02, 48.15, 5.68),
                'citizenship'       => $line(16, 82.5, 188),
                'residence'         => $line(16, 87.5, 188),
                'occupation'        => $line(16, 92.5, 188),
                'father_first_name' => $line(16, 97.5, 54),
                'father_middle_name'=> $line(78, 97.5, 54),
                'father_last_name'  => $line(140, 97.5, 64),
                'mother_first_name' => $line(16, 102.5, 54),
                'mother_middle_name'=> $line(78, 102.5, 54),
                'mother_last_name'  => $line(140, 102.5, 64),
                'immediate_cause'   => $line(16, 117.5, 188),
                'contributory_cause'=> $line(16, 122.5, 188),
                'autopsy_performed' => $line(16, 134.5, 188),
                'attending_physician'=> $line(16, 139.5, 188),
                'surviving_spouse_name' => $line(16, 147.5, 188),
                'surviving_spouse_address' => $line(16, 152.5, 188),
                'place_of_burial'   => $line(16, 162.5, 188),
                'death_time'        => $line(16, 167.5, 48),
                'registration_date' => $line(100, 167.5, 88),
                'attendant_type'    => $line(158, 175, 46, 4),
                'death_cert_name'   => $line(62, 190, 48, 4),
                'death_cert_title'  => $line(118, 190, 86, 4),
                'death_cert_address'=> $line(16, 195, 188, 4),
                'death_cert_date'   => $line(16, 200, 44, 4),
                'reviewed_by_name'  => $line(58, 212, 50, 4),
                'reviewed_by_date'  => $line(16, 217, 40, 4),
                'corpse_disposal'   => $line(16, 224, 188, 4),
                'burial_permit_number' => $line(16, 229, 50, 4),
                'burial_permit_date'=> $line(72, 229, 40, 4),
                'transfer_permit_number' => $line(118, 229, 50, 4),
                'transfer_permit_date'=> $line(174, 229, 30, 4),
                'cemetery_crematory' => $line(16, 234, 188, 4),
                'informant_name'    => $line(58, 246, 50, 4),
                'informant_relationship'=> $line(58, 251, 50, 4),
                'informant_address' => $line(16, 256, 96, 4),
                'informant_date'    => $line(16, 261, 40, 4),
                'prepared_by_name'  => $line(154, 246, 50, 4),
                'prepared_by_title' => $line(154, 251, 50, 4),
                'prepared_by_date'  => $line(112, 256, 40, 4),
                'received_by_name'  => $line(58, 274, 50, 4),
                'received_by_title' => $line(58, 279, 50, 4),
                'received_by_date'  => $line(16, 284, 40, 4),
                'registrar_name'    => $line(154, 274, 50, 4),
                'registrar_title'   => $line(154, 279, 50, 4),
                'registrar_date'    => $line(112, 284, 40, 4),
                'remarks_annotations'=> $line(16, 296, 188, 16),
                'lcro_box_5'        => $line(18, 318, 18, 5),
                'lcro_box_8'        => $line(42, 318, 18, 5),
                'lcro_box_9'        => $line(66, 318, 18, 5),
                'lcro_box_10'       => $line(90, 318, 18, 5),
                'lcro_box_11'       => $line(114, 318, 18, 5),
                'lcro_box_19a'      => $line(138, 318, 18, 5),
                'lcro_box_19c'      => $line(162, 318, 18, 5),
            ],
            'back' => [
                'child_age_mother'  => $line(16, 28, 188),
                'child_delivery_method' => $line(16, 36, 188),
                'child_pregnancy_length' => $line(16, 44, 188),
                'child_birth_type'  => $line(16, 52, 188),
                'child_birth_order' => $line(16, 60, 188),
                'infant_cause_a'    => $line(16, 72, 188),
                'infant_cause_b'    => $line(16, 80, 188),
                'infant_cause_c'    => $line(16, 88, 188),
                'infant_cause_d'    => $line(16, 96, 188),
                'infant_cause_e'    => $line(16, 104, 188),
                'postmortem_cause'  => $line(16, 118, 188),
                'embalmer_deceased_name' => $line(16, 142, 188),
                'delayed_death_affiant_name' => $line(16, 166, 188),
                'delayed_death_affiant_address' => $line(16, 174, 188),
                'delayed_death_deceased_name' => $line(16, 182, 188),
                'delayed_death_date' => $line(16, 190, 188),
                'delayed_death_place' => $line(16, 198, 188),
                'delayed_death_burial_place' => $line(16, 206, 188),
                'delayed_death_attendant' => $line(16, 214, 188),
                'delayed_death_cause' => $line(16, 222, 188),
                'delayed_death_reason' => $line(16, 230, 188),
            ],
        ],
    ];
}

function printFormReferenceImage(string $certificateType, string $pageSide): string
{
    $svg = 'assets/print/forms/' . $certificateType . '-' . $pageSide . '.svg';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $svg);
    if (is_file($full)) {
        return $svg;
    }

    $png = 'assets/print/forms/' . $certificateType . '-' . $pageSide . '.png';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $png);
    if (is_file($full)) {
        return $png;
    }

    return 'assets/print/forms/' . $certificateType . '-' . $pageSide . '.jpg';
}

/** @return array<string, string> Legacy alias — columns now live in type-specific detail tables. */
function printCivilRecordExtraColumns(): array
{
    $columns = [];
    foreach (civilRecordTypeColumnMap() as $typeColumns) {
        $columns = array_merge($columns, $typeColumns);
    }

    return $columns;
}
