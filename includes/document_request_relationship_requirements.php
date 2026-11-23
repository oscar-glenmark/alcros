<?php
/**
 * Certificate request - requester relationship options and required documents (admin-configurable).
 */

require_once __DIR__ . '/office_requirements.php';

function documentRequestRelationshipSettingKey(): string
{
    return 'document_request_relationship_requirements_json';
}

/** @return array<string, array<string, string>> document_type => relationship_slug => label */
function documentRequestRelationshipCatalog(): array
{
    return [
        'birth' => [
            'document_owner'           => 'Document Owner',
            'parent'                   => 'Parent',
            'legal_spouse'             => 'Legal Spouse',
            'son_daughter'             => 'Son/Daughter',
            'other_relative'           => 'Other Relative',
            'non_family_representative' => 'Non-Family Representative',
        ],
        'death' => [
            'legal_spouse'             => 'Legal Spouse',
            'parent'                   => 'Parent',
            'son_daughter'             => 'Son/Daughter',
            'sibling'                  => 'Sibling',
            'other_relative'           => 'Other Relative',
            'non_family_representative' => 'Non-Family Representative',
        ],
        'marriage' => [
            'husband'                  => 'Husband',
            'wife'                     => 'Wife',
            'parent'                   => 'Parent',
            'son_daughter'             => 'Son/Daughter',
            'other_relative'           => 'Other Relative',
            'non_family_representative' => 'Non-Family Representative',
        ],
    ];
}

function documentRequestRelationshipLabel(string $documentType, string $slug): string
{
    $documentType = trim($documentType);
    $slug = trim($slug);
    $catalog = documentRequestRelationshipCatalog();

    return $catalog[$documentType][$slug] ?? '';
}

function isAllowedDocumentRequestRelationship(string $documentType, string $slug): bool
{
    return documentRequestRelationshipLabel($documentType, $slug) !== '';
}

function validateDocumentRequestRelationshipInput(string $documentType, string $slug): ?string
{
    $slug = trim($slug);
    if ($slug === '') {
        return 'Please select your relationship to the person on record.';
    }
    if (!isAllowedDocumentRequestRelationship($documentType, $slug)) {
        return 'Please select a valid relationship for this certificate type.';
    }

    return null;
}

/**
 * @return array<string, array<string, array{items: list<array{text: string, sub?: list<string>}>, notes: list<string>}>>
 */
function documentRequestRelationshipRequirementsBuiltin(): array
{
    $line = static fn (string $text): array => ['text' => $text];

    return [
        'birth' => [
            'document_owner' => [
                'items' => [$line('Valid government-issued ID')],
                'notes' => [],
            ],
            'parent' => [
                'items' => [$line('Valid ID and proof of parent-child relationship, when needed')],
                'notes' => [],
            ],
            'legal_spouse' => [
                'items' => [$line('Valid ID and marriage certificate as proof of relationship')],
                'notes' => [],
            ],
            'son_daughter' => [
                'items' => [$line('Valid ID and proof of relationship')],
                'notes' => [],
            ],
            'other_relative' => [
                'items' => [$line('Valid ID; request must be referred to LCRO staff to verify legal authority')],
                'notes' => [],
            ],
            'non_family_representative' => [
                'items' => [$line('Valid ID of representative, authorization letter or SPA, and valid ID of document owner')],
                'notes' => [],
            ],
        ],
        'death' => [
            'legal_spouse' => [
                'items' => [$line('Valid ID and proof of marriage')],
                'notes' => [],
            ],
            'parent' => [
                'items' => [$line('Valid ID and proof of parent-child relationship')],
                'notes' => [],
            ],
            'son_daughter' => [
                'items' => [$line('Valid ID and proof of relationship')],
                'notes' => [],
            ],
            'sibling' => [
                'items' => [$line('Valid ID and notarized Affidavit of Kinship, if qualifying as nearest surviving kin')],
                'notes' => [],
            ],
            'other_relative' => [
                'items' => [$line('Valid ID and notarized Affidavit of Kinship, if qualifying as nearest surviving kin')],
                'notes' => [],
            ],
            'non_family_representative' => [
                'items' => [$line('Valid ID and documents establishing legal authority; refer to LCRO for verification')],
                'notes' => [],
            ],
        ],
        'marriage' => [
            'husband' => [
                'items' => [$line('Valid government-issued ID')],
                'notes' => [],
            ],
            'wife' => [
                'items' => [$line('Valid government-issued ID')],
                'notes' => [],
            ],
            'parent' => [
                'items' => [$line('Valid ID and proof of relationship; verify eligibility')],
                'notes' => [],
            ],
            'son_daughter' => [
                'items' => [$line('Valid ID and proof of relationship; verify eligibility')],
                'notes' => [],
            ],
            'other_relative' => [
                'items' => [$line('Valid ID and proof of legal authority or applicable authorization')],
                'notes' => [],
            ],
            'non_family_representative' => [
                'items' => [$line('Valid ID of representative, authorization letter or SPA, and valid ID of document owner')],
                'notes' => [],
            ],
        ],
    ];
}

/** @return array<string, array<string, array{items?: mixed, notes?: mixed}>> */
function getDocumentRequestRelationshipRequirementsRaw(): array
{
    $json = getSetting(documentRequestRelationshipSettingKey(), '');
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $docType => $byRel) {
        if (!is_string($docType) || !is_array($byRel)) {
            continue;
        }
        $relOut = [];
        foreach ($byRel as $slug => $entry) {
            if (!is_string($slug) || !is_array($entry)) {
                continue;
            }
            $relOut[$slug] = $entry;
        }
        if ($relOut !== []) {
            $out[$docType] = $relOut;
        }
    }

    return $out;
}

/**
 * @return array{items: list<array{text: string, sub?: list<string>}>, notes: list<string>}
 */
function getDocumentRequestRelationshipRequirements(string $documentType, string $relationshipSlug): array
{
    $documentType = trim($documentType);
    $relationshipSlug = trim($relationshipSlug);
    if (!isAllowedDocumentRequestRelationship($documentType, $relationshipSlug)) {
        return ['items' => [], 'notes' => []];
    }

    $builtin = documentRequestRelationshipRequirementsBuiltin();
    $default = $builtin[$documentType][$relationshipSlug] ?? ['items' => [], 'notes' => []];

    $raw = getDocumentRequestRelationshipRequirementsRaw();
    $entry = $raw[$documentType][$relationshipSlug] ?? null;

    if (!is_array($entry)) {
        return [
            'items' => normalizeRequirementsItems($default['items'] ?? []),
            'notes' => array_values(array_filter(array_map(
                static fn ($n): string => trim((string) $n),
                $default['notes'] ?? []
            ), static fn (string $n): bool => $n !== '')),
        ];
    }

    $items = isset($entry['items']) && is_array($entry['items'])
        ? normalizeRequirementsItems($entry['items'])
        : normalizeRequirementsItems($default['items'] ?? []);

    $notes = isset($entry['notes']) && is_array($entry['notes'])
        ? array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $entry['notes']), static fn (string $n): bool => $n !== ''))
        : ($default['notes'] ?? []);

    return ['items' => $items, 'notes' => $notes];
}

/** @return array<string, array<string, array{items: string, notes: string}>> */
function documentRequestRelationshipRequirementsFormValues(): array
{
    $catalog = documentRequestRelationshipCatalog();
    $builtin = documentRequestRelationshipRequirementsBuiltin();
    $raw = getDocumentRequestRelationshipRequirementsRaw();
    $values = [];

    foreach ($catalog as $docType => $relationships) {
        foreach ($relationships as $slug => $label) {
            $default = $builtin[$docType][$slug] ?? ['items' => [], 'notes' => []];
            $entry = $raw[$docType][$slug] ?? null;

            $items = isset($entry['items']) && is_array($entry['items'])
                ? normalizeRequirementsItems($entry['items'])
                : normalizeRequirementsItems($default['items'] ?? []);

            $notes = isset($entry['notes']) && is_array($entry['notes'])
                ? array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $entry['notes']), static fn (string $n): bool => $n !== ''))
                : ($default['notes'] ?? []);

            $values[$docType][$slug] = [
                'items' => serializeRequirementsItemsToText($items),
                'notes' => implode("\n", $notes),
            ];
        }
    }

    return $values;
}

/** @param array<string, mixed> $postBlock */
function saveDocumentRequestRelationshipRequirementsFromPost(array $postBlock): void
{
    $catalog = documentRequestRelationshipCatalog();
    $out = [];

    foreach ($catalog as $docType => $relationships) {
        $docPost = $postBlock[$docType] ?? [];
        if (!is_array($docPost)) {
            continue;
        }

        foreach ($relationships as $slug => $_label) {
            $block = $docPost[$slug] ?? [];
            if (!is_array($block)) {
                $block = [];
            }

            $items = parseRequirementsItemsFromText((string) ($block['items'] ?? ''));

            $noteLines = preg_split('/\R/u', (string) ($block['notes'] ?? '')) ?: [];
            $notes = [];
            foreach ($noteLines as $line) {
                $line = trim((string) $line);
                if ($line === '') {
                    continue;
                }
                if (function_exists('mb_strlen') && mb_strlen($line) > 500) {
                    $line = mb_substr($line, 0, 500);
                } elseif (strlen($line) > 500) {
                    $line = substr($line, 0, 500);
                }
                $notes[] = $line;
                if (count($notes) >= 12) {
                    break;
                }
            }

            if (!isset($out[$docType])) {
                $out[$docType] = [];
            }
            $out[$docType][$slug] = [
                'items' => $items,
                'notes' => $notes,
            ];
        }
    }

    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return;
    }

    setSetting(documentRequestRelationshipSettingKey(), $encoded);
}

/** Citizen + JS: flat items/notes strings per doc type and relationship. */
function documentRequestRelationshipRequirementsClientMap(): array
{
    $catalog = documentRequestRelationshipCatalog();
    $map = [];

    foreach ($catalog as $docType => $relationships) {
        foreach ($relationships as $slug => $label) {
            $req = getDocumentRequestRelationshipRequirements($docType, $slug);
            $lines = [];
            foreach ($req['items'] as $item) {
                $text = trim((string) ($item['text'] ?? ''));
                if ($text !== '') {
                    $lines[] = $text;
                }
                foreach ($item['sub'] ?? [] as $sub) {
                    $sub = trim((string) $sub);
                    if ($sub !== '') {
                        $lines[] = $sub;
                    }
                }
            }
            $map[$docType][$slug] = [
                'label' => $label,
                'lines' => $lines,
                'notes' => $req['notes'],
            ];
        }
    }

    return $map;
}

/**
 * @param array{items: list<array{text: string, sub?: list<string>}>, notes: list<string>} $req
 */
function formatDocumentRequestRelationshipRequirementsPlainText(
    string $documentType,
    string $relationshipSlug,
    string $relationshipLabel,
    array $req
): string {
    $lines = ['Required documents (' . $relationshipLabel . '):'];
    foreach ($req['items'] ?? [] as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text !== '') {
            $lines[] = '• ' . $text;
        }
        foreach ($item['sub'] ?? [] as $sub) {
            $sub = trim((string) $sub);
            if ($sub !== '') {
                $lines[] = '  - ' . $sub;
            }
        }
    }
    foreach ($req['notes'] ?? [] as $note) {
        $note = trim((string) $note);
        if ($note !== '') {
            $lines[] = $note;
        }
    }
    if (count($lines) === 1) {
        return '';
    }

    return implode("\n", $lines);
}

/**
 * @param array{items: list<array{text: string, sub?: list<string>}>, notes: list<string>} $req
 */
function formatDocumentRequestRelationshipRequirementsEmailHtml(
    string $documentType,
    string $relationshipSlug,
    string $relationshipLabel,
    array $req
): string {
    if (!function_exists('citizenEmailText')) {
        require_once __DIR__ . '/helpers.php';
    }

    $html = '<div style="margin:18px 0 20px;padding:14px 16px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;">'
        . '<p style="margin:0 0 8px;font-size:10px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#1d4ed8;">'
        . 'Required documents</p>'
        . '<p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#1e3a8a;">'
        . citizenEmailText($relationshipLabel) . '</p>'
        . '<ol style="margin:0;padding-left:18px;font-size:13px;line-height:1.55;color:#334155;">';

    $hasItem = false;
    foreach ($req['items'] ?? [] as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $hasItem = true;
        $html .= '<li style="margin:0 0 6px;">' . citizenEmailText($text);
        $subs = $item['sub'] ?? [];
        if ($subs !== []) {
            $html .= '<ul style="margin:4px 0 0;padding-left:16px;list-style:disc;">';
            foreach ($subs as $sub) {
                $sub = trim((string) $sub);
                if ($sub !== '') {
                    $html .= '<li style="margin:0 0 2px;">' . citizenEmailText($sub) . '</li>';
                }
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    if (!$hasItem) {
        $html .= '<li style="margin:0;color:#64748b;">No requirements listed.</li>';
    }
    $html .= '</ol>';

    foreach ($req['notes'] ?? [] as $note) {
        $note = trim((string) $note);
        if ($note !== '') {
            $html .= '<p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:#475569;">' . citizenEmailText($note) . '</p>';
        }
    }

    $html .= '</div>';

    return $html;
}

/** @param array<string, mixed> $mail */
function appendDocumentRequestRelationshipToCitizenMail(array $mail, array $data): array
{
    $docType = (string) ($data['document_type'] ?? '');
    $slug = (string) ($data['requester_relationship'] ?? '');
    if ($slug === '' || !isAllowedDocumentRequestRelationship($docType, $slug)) {
        return $mail;
    }

    $label = documentRequestRelationshipLabel($docType, $slug);
    $details = isset($mail['details']) && is_array($mail['details']) ? $mail['details'] : [];
    $reordered = [];
    $inserted = false;
    foreach ($details as $key => $value) {
        $reordered[$key] = $value;
        if ($key === 'Document' && !$inserted) {
            $reordered['Relationship'] = $label;
            $inserted = true;
        }
    }
    if (!$inserted) {
        $reordered['Relationship'] = $label;
    }
    $mail['details'] = $reordered;

    $req = getDocumentRequestRelationshipRequirements($docType, $slug);
    $plain = formatDocumentRequestRelationshipRequirementsPlainText($docType, $slug, $label, $req);
    if ($plain !== '') {
        $mail['requirements_plain'] = $plain;
    }
    $mail['requirements_html'] = formatDocumentRequestRelationshipRequirementsEmailHtml($docType, $slug, $label, $req);

    return $mail;
}

function renderDocumentRequestRelationshipRequirementsPanel(string $documentType, string $relationshipSlug): string
{
    if (!isAllowedDocumentRequestRelationship($documentType, $relationshipSlug)) {
        return '';
    }

    $req = getDocumentRequestRelationshipRequirements($documentType, $relationshipSlug);
    $label = documentRequestRelationshipLabel($documentType, $relationshipSlug);
    $html = '<div class="citizen-request-rel-req mt-3" data-rel-req-panel>';
    $html .= '<p class="citizen-request-rel-req__title">Required documents: <strong>' . htmlspecialchars($label) . '</strong></p>';
    $html .= '<ul class="citizen-request-rel-req__list">';
    $hasItem = false;
    foreach ($req['items'] as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $hasItem = true;
        $html .= '<li>' . htmlspecialchars($text);
        $subs = $item['sub'] ?? [];
        if ($subs !== []) {
            $html .= '<ul class="citizen-request-rel-req__sublist">';
            foreach ($subs as $sub) {
                $sub = trim((string) $sub);
                if ($sub !== '') {
                    $html .= '<li>' . htmlspecialchars($sub) . '</li>';
                }
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    if (!$hasItem) {
        $html .= '<li class="text-slate-500">No requirements listed.</li>';
    }
    $html .= '</ul>';
    foreach ($req['notes'] as $note) {
        $note = trim((string) $note);
        if ($note !== '') {
            $html .= '<p class="citizen-request-rel-req__note">' . htmlspecialchars($note) . '</p>';
        }
    }
    $html .= '</div>';

    return $html;
}