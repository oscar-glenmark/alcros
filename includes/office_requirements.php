<?php
/**
 * Configurable special-service requirements (system_settings.office_requirements_json).
 */

require_once __DIR__ . '/office_fees.php';

function officeRequirementsEnsureBuiltinLoaded(): void
{
    if (!function_exists('appointmentServiceRequirementsBuiltinSpec')) {
        require_once __DIR__ . '/appointment_service_requirements.php';
    }
}

function officeRequirementsSettingKey(): string
{
    return 'office_requirements_json';
}

/** Service slugs that support a requirements list (excludes fast-track certificates-only row). */
function officeRequirementsEditableKeys(): array
{
    $keys = [];
    foreach (officeFeesCatalog() as $key => $meta) {
        if ($key === 'document_certificate') {
            continue;
        }
        $keys[] = $key;
    }

    return $keys;
}

function officeRequirementsEditable(string $key): bool
{
    return in_array($key, officeRequirementsEditableKeys(), true);
}

/** @return array<string, array{subtitle?: string, lead?: string, items?: list<array{text: string, sub?: list<string>}>, notes?: list<string>}> */
function getOfficeRequirementsConfigRaw(): array
{
    $json = getSetting(officeRequirementsSettingKey(), '');
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    $out = [];
    foreach ($decoded as $key => $entry) {
        if (!is_string($key) || !is_array($entry)) {
            continue;
        }
        $out[$key] = $entry;
    }

    return $out;
}

/**
 * @param list<array{text: string, sub?: list<string>}> $items
 */
function serializeRequirementsItemsToText(array $items): string
{
    $lines = [];
    foreach ($items as $item) {
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $lines[] = $text;
        foreach ($item['sub'] ?? [] as $sub) {
            $sub = trim((string) $sub);
            if ($sub !== '') {
                $lines[] = '  - ' . $sub;
            }
        }
    }

    return implode("\n", $lines);
}

/**
 * @return list<array{text: string, sub?: list<string>}>
 */
function parseRequirementsItemsFromText(string $text): array
{
    $items = [];
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $line = rtrim((string) $line, "\r\n");
        if (trim($line) === '') {
            continue;
        }
        if (preg_match('/^\s{2,}[-•*]\s+(.*)$/', $line, $matches)) {
            if ($items === []) {
                continue;
            }
            $idx = count($items) - 1;
            $sub = trim((string) ($matches[1] ?? ''));
            if ($sub === '') {
                continue;
            }
            if (!isset($items[$idx]['sub'])) {
                $items[$idx]['sub'] = [];
            }
            $items[$idx]['sub'][] = $sub;
            continue;
        }

        $main = trim($line);
        if ($main !== '') {
            $items[] = ['text' => $main];
        }
    }

    return normalizeRequirementsItems($items);
}

/**
 * @param list<array{text: string, sub?: list<string>}> $items
 * @return list<array{text: string, sub?: list<string>}>
 */
function normalizeRequirementsItems(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $text = trim((string) ($item['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 500);
        } elseif (strlen($text) > 500) {
            $text = substr($text, 0, 500);
        }
        $row = ['text' => $text];
        $subs = [];
        foreach ($item['sub'] ?? [] as $sub) {
            $sub = trim((string) $sub);
            if ($sub === '') {
                continue;
            }
            if (function_exists('mb_strlen') && mb_strlen($sub) > 500) {
                $sub = mb_substr($sub, 0, 500);
            } elseif (strlen($sub) > 500) {
                $sub = substr($sub, 0, 500);
            }
            $subs[] = $sub;
            if (count($subs) >= 20) {
                break;
            }
        }
        if ($subs !== []) {
            $row['sub'] = $subs;
        }
        $out[] = $row;
        if (count($out) >= 40) {
            break;
        }
    }

    return $out;
}

/** @return array{subtitle: string, lead: string, items: string, notes: string} */
function officeRequirementsFormEntryForKey(string $key): array
{
    officeRequirementsEnsureBuiltinLoaded();
    $builtin = appointmentServiceRequirementsBuiltinSpec($key);
    $raw = getOfficeRequirementsConfigRaw();
    $entry = $raw[$key] ?? null;

    if (!is_array($entry)) {
        return [
            'subtitle' => (string) ($builtin['subtitle'] ?? ''),
            'lead'     => (string) ($builtin['lead'] ?? ''),
            'items'    => serializeRequirementsItemsToText($builtin['items'] ?? []),
            'notes'    => implode("\n", appointmentServiceRequirementsNonFeeNotes($builtin)),
        ];
    }

    $items = isset($entry['items']) && is_array($entry['items'])
        ? normalizeRequirementsItems($entry['items'])
        : ($builtin['items'] ?? []);

    $notes = isset($entry['notes']) && is_array($entry['notes'])
        ? array_values(array_filter(array_map(static fn ($n): string => trim((string) $n), $entry['notes']), static fn (string $n): bool => $n !== ''))
        : appointmentServiceRequirementsNonFeeNotes($builtin);

    return [
        'subtitle' => array_key_exists('subtitle', $entry)
            ? trim((string) $entry['subtitle'])
            : (string) ($builtin['subtitle'] ?? ''),
        'lead' => array_key_exists('lead', $entry)
            ? trim((string) $entry['lead'])
            : (string) ($builtin['lead'] ?? ''),
        'items' => serializeRequirementsItemsToText($items),
        'notes' => implode("\n", $notes),
    ];
}

/** @return array<string, array{subtitle: string, lead: string, items: string, notes: string}> */
function officeRequirementsFormValues(): array
{
    $values = [];
    foreach (officeRequirementsEditableKeys() as $key) {
        $values[$key] = officeRequirementsFormEntryForKey($key);
    }

    return $values;
}

/** @param array<string, mixed> $postRequirements */
function saveOfficeRequirementsFromPost(array $postRequirements): void
{
    $out = [];

    foreach (officeRequirementsEditableKeys() as $key) {
        $block = $postRequirements[$key] ?? [];
        if (!is_array($block)) {
            $block = [];
        }

        $subtitle = trim((string) ($block['subtitle'] ?? ''));
        $lead = trim((string) ($block['lead'] ?? ''));
        if (function_exists('mb_strlen') && mb_strlen($lead) > 2000) {
            $lead = mb_substr($lead, 0, 2000);
        } elseif (strlen($lead) > 2000) {
            $lead = substr($lead, 0, 2000);
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

        $out[$key] = [
            'subtitle' => $subtitle,
            'lead'     => $lead,
            'items'    => $items,
            'notes'    => $notes,
        ];
    }

    $encoded = json_encode($out, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return;
    }

    setSetting(officeRequirementsSettingKey(), $encoded);
}

/** @param array{title: string, subtitle: string, lead: string, items: list<array{text: string, sub?: list<string>}>, notes: list<string>} $spec */
function applyConfiguredOfficeRequirements(array $spec, string $key): array
{
    if ($key === 'general' || !officeRequirementsEditable($key)) {
        return $spec;
    }

    $raw = getOfficeRequirementsConfigRaw();
    if (!array_key_exists($key, $raw) || !is_array($raw[$key])) {
        return $spec;
    }

    $entry = $raw[$key];

    if (array_key_exists('subtitle', $entry)) {
        $spec['subtitle'] = trim((string) $entry['subtitle']);
    }
    if (array_key_exists('lead', $entry)) {
        $spec['lead'] = trim((string) $entry['lead']);
    }
    if (array_key_exists('items', $entry) && is_array($entry['items'])) {
        $spec['items'] = normalizeRequirementsItems($entry['items']);
    }
    if (array_key_exists('notes', $entry) && is_array($entry['notes'])) {
        $spec['notes'] = array_values(array_filter(
            array_map(static fn ($note): string => trim((string) $note), $entry['notes']),
            static fn (string $note): bool => $note !== ''
        ));
    }

    return $spec;
}
