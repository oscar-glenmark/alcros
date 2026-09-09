<?php
/**
 * Philippines PSGC-backed cascading location picker (Country → Province → City/Municipality → Barangay).
 */

function cascadingLocationFieldNames(): array
{
    return [
        'birth_place',
        'mother_residence',
        'father_residence',
        'residence',
        'residence_deceased',
        'husband_birth_place',
        'wife_birth_place',
        'husband_residence',
        'wife_residence',
    ];
}

function cascadingLocationUsesField(string $fieldName): bool
{
    return in_array($fieldName, cascadingLocationFieldNames(), true);
}

function cascadingLocationInputClass(string $baseClass = ''): string
{
    $class = trim('js-cascading-location ' . $baseClass);

    return $class;
}

function cascadingLocationCountries(): array
{
    return [
        ['code' => 'PH', 'name' => 'Philippines'],
        ['code' => 'US', 'name' => 'United States'],
        ['code' => 'CA', 'name' => 'Canada'],
        ['code' => 'AU', 'name' => 'Australia'],
        ['code' => 'JP', 'name' => 'Japan'],
        ['code' => 'SG', 'name' => 'Singapore'],
        ['code' => 'SA', 'name' => 'Saudi Arabia'],
        ['code' => 'AE', 'name' => 'United Arab Emirates'],
        ['code' => 'HK', 'name' => 'Hong Kong'],
        ['code' => 'OTHER', 'name' => 'Other Country'],
    ];
}

function cascadingLocationDataPath(string $relative): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'locations' . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
}

/** @return list<array{id:int,name:string}> */
function cascadingLocationProvinces(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $path = cascadingLocationDataPath('ph/provinces.json');
    if (!is_file($path)) {
        return $cache = [];
    }

    $rows = json_decode((string) file_get_contents($path), true);
    if (!is_array($rows)) {
        return $cache = [];
    }

    $items = [];
    foreach ($rows as $row) {
        $name = trim((string) ($row['description'] ?? ''));
        if ($name === '') {
            continue;
        }
        $code = (int) ($row['code'] ?? 0);
        $items[] = [
            'id'     => (int) ($row['province_id'] ?? 0),
            'code'   => $code,
            'prefix' => cascadingLocationPsgcPrefix($code),
            'name'   => $name,
        ];
    }

    usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

    return $cache = $items;
}

function cascadingLocationPsgcPrefix(int $code): int
{
    if ($code <= 0) {
        return 0;
    }

    return (int) floor($code / 100000);
}

function cascadingLocationProvincePrefix(int $provinceId): int
{
    foreach (cascadingLocationProvinces() as $province) {
        if ((int) ($province['id'] ?? 0) === $provinceId) {
            return (int) ($province['prefix'] ?? 0);
        }
    }

    return 0;
}

/** @return list<array{id:int,name:string}> */
function cascadingLocationMunicipalities(int $provinceId): array
{
    static $byPrefix = null;
    if ($byPrefix === null) {
        $byPrefix = [];
        $path = cascadingLocationDataPath('ph/municipalities.json');
        if (is_file($path)) {
            $rows = json_decode((string) file_get_contents($path), true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $code = (int) ($row['code'] ?? 0);
                    $prefix = cascadingLocationPsgcPrefix($code);
                    $name = trim((string) ($row['description'] ?? ''));
                    $id = (int) ($row['muncity_id'] ?? 0);
                    if ($prefix <= 0 || $id <= 0 || $name === '') {
                        continue;
                    }
                    $byPrefix[$prefix][] = ['id' => $id, 'name' => $name];
                }
                foreach ($byPrefix as &$list) {
                    usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
                }
                unset($list);
            }
        }
    }

    $prefix = cascadingLocationProvincePrefix($provinceId);

    return $byPrefix[$prefix] ?? [];
}

/** @return list<array{id:int,name:string}> */
function cascadingLocationBarangays(int $municipalityId): array
{
    static $byMunicipality = null;
    if ($byMunicipality === null) {
        $byMunicipality = [];
        $path = cascadingLocationDataPath('ph/barangays.json');
        if (is_file($path)) {
            $rows = json_decode((string) file_get_contents($path), true);
            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $municipality = (int) ($row['muncity_id'] ?? 0);
                    $name = trim((string) ($row['description'] ?? ''));
                    $id = (int) ($row['barangay_id'] ?? 0);
                    if ($municipality <= 0 || $id <= 0 || $name === '') {
                        continue;
                    }
                    $byMunicipality[$municipality][] = ['id' => $id, 'name' => $name];
                }
                foreach ($byMunicipality as &$list) {
                    usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
                }
                unset($list);
            }
        }
    }

    return $byMunicipality[$municipalityId] ?? [];
}
