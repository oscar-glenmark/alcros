<?php

declare(strict_types=1);

function recordsListValidSorts(): array
{
    return [
        'name'    => 'cr.last_name, cr.first_name',
        'type'    => 'cr.record_type',
        'date'    => 'COALESCE(cr.event_date, cr.birth_date)',
        'created' => 'cr.created_at',
    ];
}

function recordsListPerPage(): int
{
    return 10;
}

/** @return array<string, string> */
function recordsListTypeBadgeClass(): array
{
    return [
        'birth'    => 'bg-blue-100 text-blue-600',
        'death'    => 'bg-gray-100 text-gray-600',
        'marriage' => 'bg-pink-100 text-pink-600',
    ];
}

function recordsListRecordInitial(string $name): string
{
    $name = trim($name);

    return $name === '' ? '?' : strtoupper(substr($name, 0, 1));
}

function recordsListNormalizeFilters(array $input): array
{
    $validTypes = ['birth', 'death', 'marriage'];
    $type = (string) ($input['type'] ?? 'all');
    if (!in_array($type, ['all', ...$validTypes], true)) {
        $type = 'all';
    }

    $validSorts = recordsListValidSorts();
    $sort = (string) ($input['sort'] ?? 'name');
    if (!isset($validSorts[$sort])) {
        $sort = 'name';
    }

    $dir = strtolower((string) ($input['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
    $page = max(1, (int) ($input['page'] ?? 1));
    $q = trim((string) ($input['q'] ?? ''));

    return [
        'type' => $type,
        'q'    => $q,
        'sort' => $sort,
        'dir'  => $dir,
        'page' => $page,
    ];
}

/** @return array{0: string, 1: array<int, mixed>} */
function buildRecordsWhere(array $filters): array
{
    ensureAdminSearchIndexes(getDB());

    $where  = 'cr.deleted_at IS NULL';
    $params = [];

    if ($filters['type'] !== 'all' && in_array($filters['type'], ['birth', 'death', 'marriage'], true)) {
        $where .= ' AND cr.record_type = ?';
        $params[] = $filters['type'];
    }

    $search = adminSearchNormalizeWhitespace($filters['q'] ?? '');
    if ($search === '') {
        return [$where, $params];
    }

    [$nameSearch, $dateSearch] = adminSearchSplitNameAndDate($search);
    if ($nameSearch === '' && $dateSearch === '') {
        $nameSearch = $search;
    }

    $term = '%' . $search . '%';
    $clauses = [];
    $searchParams = [];

    if ($nameSearch !== '') {
        [$nameClauses, $nameParams] = adminSearchNameLikeClauses(
            $nameSearch,
            'cr.first_name',
            'cr.middle_name',
            'cr.last_name'
        );
        $clauses = array_merge($clauses, $nameClauses);
        $searchParams = array_merge($searchParams, $nameParams);

        $nameTerm = '%' . $nameSearch . '%';
        $clauses[] = "EXISTS (
                SELECT 1 FROM marriage_record_details mrd
                WHERE mrd.civil_record_id = cr.id
                AND (
                    mrd.husband_name LIKE ?
                    OR mrd.wife_name LIKE ?
                    OR mrd.husband_father_name LIKE ?
                    OR mrd.husband_mother_maiden_name LIKE ?
                    OR mrd.wife_father_name LIKE ?
                    OR mrd.wife_mother_maiden_name LIKE ?
                    OR mrd.solemnized_by LIKE ?
                    OR mrd.witnesses LIKE ?
                )
            )";
        $searchParams = array_merge($searchParams, array_fill(0, 8, $nameTerm));
    }

    foreach ([
        'cr.registry_number LIKE ?',
        'cr.book_number LIKE ?',
        'cr.page_number LIKE ?',
        'cr.father_name LIKE ?',
        'cr.mother_name LIKE ?',
        'cr.place LIKE ?',
        'cr.notes LIKE ?',
        'CAST(cr.id AS CHAR) LIKE ?',
    ] as $clause) {
        $clauses[] = $clause;
        $searchParams[] = $term;
    }

    $clauses[] = "EXISTS (
            SELECT 1 FROM death_record_details drd
            WHERE drd.civil_record_id = cr.id
            AND drd.code_number LIKE ?
        )";
    $searchParams[] = $term;

    $dateQuery = $dateSearch !== '' ? $dateSearch : (adminSearchLooksLikeDate($search) ? $search : '');
    if ($dateQuery !== '') {
        [$dateClauses, $dateParams] = adminSearchDateClausesForColumns($dateQuery, [
            'cr.birth_date',
            'cr.event_date',
        ]);
        $clauses = array_merge($clauses, $dateClauses);
        $searchParams = array_merge($searchParams, $dateParams);

        [$deathRegSql, $deathRegParams] = adminSearchDateMatchExpr('drd.registration_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM death_record_details drd
                WHERE drd.civil_record_id = cr.id
                AND $deathRegSql
            )";
        $searchParams = array_merge($searchParams, $deathRegParams);

        [$birthRegSql, $birthRegParams] = adminSearchDateMatchExpr('brd.registration_date', $dateQuery);
        [$parentsDomSql, $parentsDomParams] = adminSearchDateMatchExpr('brd.parents_marriage_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM birth_record_details brd
                WHERE brd.civil_record_id = cr.id
                AND ($birthRegSql OR $parentsDomSql)
            )";
        $searchParams = array_merge($searchParams, $birthRegParams, $parentsDomParams);

        [$husbandDobSql, $husbandDobParams] = adminSearchDateMatchExpr('mrd.husband_birth_date', $dateQuery);
        [$wifeDobSql, $wifeDobParams] = adminSearchDateMatchExpr('mrd.wife_birth_date', $dateQuery);
        $clauses[] = "EXISTS (
                SELECT 1 FROM marriage_record_details mrd
                WHERE mrd.civil_record_id = cr.id
                AND ($husbandDobSql OR $wifeDobSql)
            )";
        $searchParams = array_merge($searchParams, $husbandDobParams, $wifeDobParams);
    }

    $where .= ' AND (' . implode(' OR ', $clauses) . ')';
    $params = array_merge($params, $searchParams);

    return [$where, $params];
}

function recordsUrlParams(array $filters, array $overrides = []): array
{
    $params = array_merge([
        'type' => $filters['type'] ?? 'all',
        'q'    => $filters['q'] ?? '',
        'sort' => $filters['sort'] ?? 'name',
        'dir'  => $filters['dir'] ?? 'asc',
        'page' => (int) ($filters['page'] ?? 1),
    ], $overrides);

    foreach (['type', 'q', 'sort', 'dir', 'page', 'edit'] as $key) {
        if ($key === 'type' && ($params['type'] ?? '') === 'all') {
            unset($params['type']);
        } elseif ($key === 'sort' && ($params['sort'] ?? '') === 'name') {
            unset($params['sort']);
        } elseif ($key === 'dir' && ($params['dir'] ?? '') === 'asc') {
            unset($params['dir']);
        } elseif ($key === 'page' && (int) ($params['page'] ?? 1) <= 1) {
            unset($params['page']);
        } elseif ($key === 'q' && ($params['q'] ?? '') === '') {
            unset($params['q']);
        } elseif ($key === 'edit' && empty($params['edit'])) {
            unset($params['edit']);
        }
    }

    return $params;
}

function buildRecordsUrlFromFilters(array $filters, array $overrides = []): string
{
    return buildAuthUrl('records.php', recordsUrlParams($filters, $overrides));
}

function recordsListSortUrl(array $filters, string $column): string
{
    $sort = $filters['sort'] ?? 'name';
    $dir = $filters['dir'] ?? 'asc';
    $nextDir = ($sort === $column && $dir === 'asc') ? 'desc' : 'asc';

    return buildRecordsUrlFromFilters($filters, [
        'sort' => $column,
        'dir'  => $nextDir,
        'page' => 1,
    ]);
}

/**
 * @return array{
 *     records: array<int, array<string, mixed>>,
 *     totalRecords: int,
 *     totalPages: int,
 *     page: int,
 *     filters: array{type: string, q: string, sort: string, dir: string, page: int}
 * }
 */
function recordsListFetch(PDO $pdo, array $filters): array
{
    $filters = recordsListNormalizeFilters($filters);
    $validSorts = recordsListValidSorts();
    $perPage = recordsListPerPage();
    $page = $filters['page'];
    $offset = ($page - 1) * $perPage;

    [$where, $params] = buildRecordsWhere($filters);
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM civil_records cr WHERE $where");
    $countStmt->execute($params);
    $totalRecords = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRecords / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
        $filters['page'] = $page;
        $offset = ($page - 1) * $perPage;
    }

    $orderCol = $validSorts[$filters['sort']];
    $sql = "SELECT cr.* FROM civil_records cr WHERE $where ORDER BY $orderCol {$filters['dir']} LIMIT $perPage OFFSET $offset";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = hydrateCivilRecordRows($pdo, $stmt->fetchAll(PDO::FETCH_ASSOC));

    return [
        'records'      => $records,
        'totalRecords' => $totalRecords,
        'totalPages'   => $totalPages,
        'page'         => $page,
        'filters'      => $filters,
    ];
}

function renderRecordsListPanelFromList(array $list): string
{
    $records = $list['records'];
    $totalRecords = $list['totalRecords'];
    $totalPages = $list['totalPages'];
    $page = $list['page'];
    $filters = $list['filters'];
    $sort = $filters['sort'];
    $dir = $filters['dir'];
    $typeBadgeClass = recordsListTypeBadgeClass();

    ob_start();
    include __DIR__ . '/partials/records_list_panel.php';

    return (string) ob_get_clean();
}

function renderRecordsListPanel(PDO $pdo, array $filters): string
{
    return renderRecordsListPanelFromList(recordsListFetch($pdo, $filters));
}

/**
 * @return array{html: string, url: string, filters: array<string, mixed>, totalRecords: int, totalPages: int, page: int}
 */
function recordsListAjaxPayload(PDO $pdo, array $filters): array
{
    $list = recordsListFetch($pdo, $filters);

    return [
        'html'          => renderRecordsListPanelFromList($list),
        'url'           => buildRecordsUrlFromFilters($list['filters']),
        'filters'       => $list['filters'],
        'totalRecords'  => $list['totalRecords'],
        'totalPages'    => $list['totalPages'],
        'page'          => $list['page'],
    ];
}
