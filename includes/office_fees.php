<?php
/**
 * Configurable office fees (system_settings.office_fees_json).
 */

function officeFeesSettingKey(): string
{
    return 'office_fees_json';
}

/** @return array<string, array{label: string, hint?: string, defaults: list<string>}> */
function officeFeesCatalog(): array
{
    return [
        'document_certificate' => [
            'label'    => 'Fast-track certificates (birth, death, marriage)',
            'hint'     => 'All Services page, certificate request emails, and citizen notices.',
            'defaults' => ['Processing fee: PHP 100.00'],
        ],
        'delayed-registration-birth' => [
            'label'    => 'Delayed Registration of Birth',
            'defaults' => ['Late registration fee: PHP 300.00'],
        ],
        'report-correction' => [
            'label'    => 'Report Correction (R.A. 9048)',
            'defaults' => ['Filing fee: PHP 1,000.00', 'Publication: PHP 500.00'],
        ],
        'supplemental-report' => [
            'label'    => 'Supplemental Report',
            'defaults' => ['Processing fee: PHP 100.00'],
        ],
        'request-psa-documents' => [
            'label'    => 'Request PSA Documents',
            'defaults' => ['Processing fee: PHP 155.00'],
        ],
        'legitimation' => [
            'label'    => 'Legitimation',
            'defaults' => ['Processing fee: PHP 250.00'],
        ],
        'cenomar' => [
            'label'    => 'CENOMAR',
            'defaults' => ['Processing fee: PHP 210.00'],
        ],
        'acknowledgement' => [
            'label'    => 'Acknowledgement (R.A. 9255)',
            'defaults' => ['Processing fee: PHP 200.00'],
        ],
    ];
}

/** @return array<string, list<string>> */
function getOfficeFeesConfigRaw(): array
{
    $json = getSetting(officeFeesSettingKey(), '');
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $key => $lines) {
        if (!is_string($key) || !is_array($lines)) {
            continue;
        }
        $out[$key] = array_values(array_filter(array_map(
            static fn ($line): string => trim((string) $line),
            $lines
        ), static fn (string $line): bool => $line !== ''));
    }

    return $out;
}

/** @return list<string> */
function getOfficeFeeLines(string $key): array
{
    $catalog = officeFeesCatalog();
    $defaults = $catalog[$key]['defaults'] ?? [];
    $raw = getOfficeFeesConfigRaw();

    if (!array_key_exists($key, $raw)) {
        return $defaults;
    }

    return $raw[$key];
}

/** @return array<string, string> Textarea values for admin form */
function officeFeesFormValues(): array
{
    $values = [];
    foreach (officeFeesCatalog() as $key => $meta) {
        $lines = getOfficeFeeLines($key);
        $values[$key] = $lines !== [] ? implode("\n", $lines) : '';
    }

    return $values;
}

/** @param array<string, mixed> $postFees */
function saveOfficeFeesFromPost(array $postFees): void
{
    $catalog = officeFeesCatalog();
    $out = [];

    foreach ($catalog as $key => $meta) {
        $text = (string) ($postFees[$key] ?? '');
        $lines = preg_split('/\R/u', $text) ?: [];
        $clean = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }
            if (function_exists('mb_strlen') && mb_strlen($line) > 240) {
                $line = mb_substr($line, 0, 240);
            } elseif (strlen($line) > 240) {
                $line = substr($line, 0, 240);
            }
            $clean[] = $line;
            if (count($clean) >= 12) {
                break;
            }
        }
        $out[$key] = $clean;
    }

    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return;
    }

    setSetting(officeFeesSettingKey(), $encoded);
}

/** @param array{notes: list<string>} $req */
function applyConfiguredOfficeFeesToRequirement(array $req, string $feeKey): array
{
    if (!array_key_exists($feeKey, officeFeesCatalog())) {
        return $req;
    }

    $configured = getOfficeFeeLines($feeKey);
    $nonFee = appointmentServiceRequirementsNonFeeNotes($req);
    $req['notes'] = array_merge($configured, $nonFee);

    return $req;
}

require_once __DIR__ . '/office_requirements.php';
