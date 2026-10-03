<?php
/**
 * Official requirement lists for Special Services (appointment booking).
 */

function appointmentServiceRequirementsKey(string $slug): string
{
    return match ($slug) {
        'delayed-registration-birth' => 'delayed-registration-birth',
        'report-correction'          => 'report-correction',
        'supplemental-report'        => 'supplemental-report',
        'request-psa-documents'      => 'request-psa-documents',
        'legitimation'               => 'legitimation',
        'cenomar'                    => 'cenomar',
        'acknowledgement'            => 'acknowledgement',
        default                      => 'general',
    };
}

/** @return array{title: string, subtitle: string, lead: string, items: list<array{text: string, sub?: list<string>}>, notes: list<string>} */
function getAppointmentServiceRequirements(string $slug, ?string $serviceLabel = null): array
{
    $key = appointmentServiceRequirementsKey($slug);

    $specs = [
        'delayed-registration-birth' => [
            'title'    => 'Delayed Registration of Birth',
            'subtitle' => 'Mandatory requirements',
            'lead'     => 'Bring original documents and photocopies unless the office advises otherwise.',
            'items'    => [
                ['text' => 'Barangay certification as proof of residency'],
                ['text' => 'National ID'],
                [
                    'text' => 'Any two (2) documentary evidence showing the identity of parents, such as:',
                    'sub'  => [
                        'Baptismal certificate / dedication',
                        'Marriage certificate (if married)',
                        'Marriage certificate of parents',
                        "Authenticated copies of the applicant's siblings (birth record)",
                    ],
                ],
                ['text' => 'Unedited front-facing photo of the registrant attached to the application — 2×2, white background'],
                ['text' => 'Negative certification of birth record from PSA'],
                ['text' => 'Affidavit of two (2) disinterested persons who witnessed the birth of the child'],
                ['text' => 'PSA negative certification'],
                ['text' => 'Community tax certificate (cedula)'],
            ],
            'notes' => ['Late registration fee: PHP 300.00'],
        ],
        'report-correction' => [
            'title'    => 'Correction for Clerical Errors',
            'subtitle' => 'R.A. 9048',
            'lead'     => 'Prepare two (2) copies of each document listed below.',
            'items'    => [
                ['text' => 'PSA certificate of live birth, marriage, or death to be corrected'],
                ['text' => 'Baptismal certificate'],
                ['text' => "Voter's certificate"],
                ['text' => 'Marriage certificate'],
                ['text' => 'Birth certificate of siblings (2)'],
                ['text' => 'Birth certificate of children (2)'],
                ['text' => 'Birth certificate of mother or father'],
                ['text' => 'School records'],
                ['text' => 'Valid IDs'],
                ['text' => 'Community tax certificate'],
            ],
            'notes' => ['Filing fee: PHP 1,000.00', 'Publication: PHP 500.00'],
        ],
        'supplemental-report' => [
            'title'    => 'Supplemental Report',
            'subtitle' => 'Requirements for R.A. 10172',
            'lead'     => 'Petition for correction of sex and/or day or month in the date of birth.',
            'items'    => [
                ['text' => 'Birth certificate from PSA'],
                ['text' => 'Earliest school record or earliest school documents'],
                ['text' => 'Medical records / affidavit of non-confinement'],
                ['text' => 'Baptismal certificate and other documents issued by religious authorities'],
                ['text' => 'NBI clearance'],
                ['text' => 'Police clearance'],
                ['text' => 'Barangay clearance'],
                ['text' => 'Certificate of employment / affidavit of non-employment'],
                ['text' => 'Publication'],
                [
                    'text' => 'Medical certification issued by an accredited government physician that the petitioner has not undergone sex change or sex transplant (for petition for correction of sex)',
                ],
                [
                    'text' => 'Certification from the C/MCR that the medical certification issued by the accredited government physician is authentic',
                ],
            ],
            'notes' => ['Processing fee: PHP 100.00'],
        ],
        'request-psa-documents' => [
            'title'    => 'Request PSA Documents',
            'subtitle' => '',
            'lead'     => 'On the appointment form, select whether you need a PSA birth, death, or marriage certificate.',
            'items'    => [
                ['text' => 'Valid government-issued ID (present the original and submit a photocopy)'],
                ['text' => 'Complete name of the person on the certificate and other details the office may request'],
            ],
            'notes'    => ['Processing fee: PHP 155.00'],
        ],
        'legitimation' => [
            'title'    => 'Legitimation of Illegitimate Children',
            'subtitle' => 'Art. 177, E.O. 209',
            'lead'     => '',
            'items'    => [
                ['text' => 'Certificate of Marriage (PSA Copy)'],
                ['text' => 'Certificate of No Marriage of both parents'],
                ['text' => 'Certificate of Live Birth of the Child (PSA Copy) — 6 copies'],
                ['text' => 'Acknowledgment (not required for illegitimate children born on or after August 3, 1988)'],
                ['text' => 'Affidavit of Legitimation (executed by both parents)'],
            ],
            'notes'    => ['Processing fee: PHP 250.00'],
        ],
        'cenomar' => [
            'title'    => 'CENOMAR',
            'subtitle' => 'Certificate of No Marriage Record',
            'lead'     => '',
            'items'    => [
                ['text' => 'Valid Government-Issued ID'],
                ['text' => 'Completely Accomplished CENOMAR Application Form'],
                ['text' => 'Complete Name of Document Owner'],
                ['text' => "Father's Complete Name"],
                ['text' => "Mother's Complete Maiden Name"],
                ['text' => 'Date and Place of Birth'],
                ['text' => 'Purpose of Request'],
                ['text' => 'Number of Copies Needed'],
                [
                    'text' => 'If requested by an authorized representative:',
                    'sub'  => [
                        'Authorization Letter',
                        'Valid ID of Document Owner',
                        'Valid ID of Authorized Representative',
                    ],
                ],
            ],
            'notes'    => ['Processing fee: PHP 210.00'],
        ],
        'acknowledgement' => [
            'title'    => 'Acknowledgement',
            'subtitle' => 'R.A. 9255',
            'lead'     => '',
            'items'    => [
                [
                    'text' => 'Required:',
                    'sub'  => [
                        'Birth Certificate/Civil Registry Record',
                        'Affidavit of Admission of Paternity',
                        "Father's Valid ID",
                    ],
                ],
                [
                    'text' => 'If applicable:',
                    'sub'  => [
                        "Mother's Valid ID",
                        'AUSF (Affidavit to Use the Surname of the Father)',
                        'Private Handwritten Instrument',
                        'Guardian/Authority Documents',
                        'Other Supporting Documents',
                    ],
                ],
            ],
            'notes'    => [
                'Processing fee: PHP 200.00',
                'Legal basis: R.A. 9255 and Revised IRR (Administrative Order No. 1, Series of 2016).',
            ],
        ],
        'general' => [
            'title'    => 'Appointment requirements',
            'subtitle' => '',
            'lead'     => 'Please bring the following when you visit the office for your scheduled appointment.',
            'items'    => [
                ['text' => 'Valid government-issued ID (present the original and submit a photocopy)'],
            ],
            'notes'    => [],
        ],
    ];

    $spec = $specs[$key] ?? $specs['general'];
    if ($key === 'general' && $serviceLabel !== null && trim($serviceLabel) !== '') {
        $spec['title'] = trim($serviceLabel);
    }

    return $spec;
}

function renderAppointmentServiceRequirementsHtml(string $slug): string
{
    $req = getAppointmentServiceRequirements($slug);
    $html = '';

    if ($req['lead'] !== '') {
        $html .= '<p class="svc-req-lead">' . htmlspecialchars($req['lead']) . '</p>';
    }

    $html .= '<ol class="svc-req-list">';
    foreach ($req['items'] as $item) {
        $html .= '<li>' . htmlspecialchars($item['text']);
        if (!empty($item['sub'])) {
            $html .= '<ul class="svc-req-sublist">';
            foreach ($item['sub'] as $sub) {
                $html .= '<li>' . htmlspecialchars($sub) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>';

    if ($req['notes'] !== []) {
        $html .= '<div class="svc-req-fees" role="note">';
        foreach ($req['notes'] as $note) {
            $html .= '<p>' . htmlspecialchars($note) . '</p>';
        }
        $html .= '</div>';
    }

    return $html;
}
