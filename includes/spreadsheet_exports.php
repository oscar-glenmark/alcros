<?php

require_once __DIR__ . '/excel_export.php';

function exportOperationalReportXlsx(
    PDO $pdo,
    array $report,
    array $recordsReport,
    int $reportYear,
    array $sections,
    string $recordsType,
    string $rangeLabel
): void {
    $purposeLabels = ['walk_in' => 'Walk-in', 'appointment' => 'Appointment', 'document_claim' => 'Document claim'];
    $summary = $report['summary'];

    $workbook = alcrosExcelNewSpreadsheet('ALCROS Operational Report');
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Operational report');

    $row = 1;
    alcrosExcelWriteMetaBlock($sheet, [
        'ALCROS Operational Report',
        ['Office', (string) ($report['office_name'] ?? '')],
        ['Site', (string) ($report['site_name'] ?? '')],
        ['Period', $rangeLabel],
        ['Generated', formatReportDateTime($report['generated_at'] ?? null)],
    ], $row);

    if (in_array('overview', $sections, true)) {
        $overviewRows = [
            ['Requests Submitted', (int) ($summary['requests_submitted'] ?? 0)],
            ['Requests Completed', (int) ($summary['requests_completed'] ?? 0)],
            ['Appointments Scheduled', (int) ($summary['appointments_scheduled'] ?? 0)],
            ['Appointments Completed', (int) ($summary['appointments_completed'] ?? 0)],
            ['Queue Served', (int) ($summary['queue_served'] ?? 0)],
            ['Queue Waiting', (int) ($summary['queue_waiting'] ?? 0)],
            ['Queue No-show', (int) ($summary['queue_skipped'] ?? 0)],
            ['Pending Requests (live)', (int) ($summary['pending_requests'] ?? 0)],
            ['Ready for Pickup (live)', (int) ($summary['ready_for_pickup'] ?? 0)],
            ['Total Civil Records (live)', (int) ($summary['total_records'] ?? 0)],
            ['Registered in ' . $reportYear, (int) ($recordsReport['year_totals']['total'] ?? 0)],
            ['Certifications Printed', (int) ($summary['certifications_printed'] ?? 0)],
            ['Certificates Printed', (int) ($summary['certificates_printed'] ?? 0)],
        ];

        alcrosExcelWriteTable($sheet, ['Metric', 'Value'], $overviewRows, $row, [
            'section_title'  => 'Overview',
            'column_formats' => [2 => 'number'],
        ]);
    }

    if (in_array('requests', $sections, true)) {
        $requestRows = [];
        foreach ($report['requests'] as $reqRow) {
            $requestRows[] = [
                $reqRow['tracking_code'] ?? '',
                personNameFromRow($reqRow),
                documentTypeLabel($reqRow['document_type'] ?? ''),
                requestStatusLabel($reqRow['status'] ?? ''),
                formatDateDisplay(substr((string) ($reqRow['submitted_at'] ?? ''), 0, 10)),
            ];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Tracking Code', 'Citizen', 'Document Type', 'Status', 'Submitted'],
            $requestRows,
            $row,
            [
                'section_title' => 'Document requests',
                'empty_message' => 'No document requests in this period.',
            ]
        );
    }

    if (in_array('appointments', $sections, true)) {
        $appointmentRows = [];
        foreach ($report['appointments'] as $apptRow) {
            $time = $apptRow['appointment_time'] ?? '';
            $timeLabel = $time !== '' ? date('g:i A', strtotime((string) $time)) : '';

            $appointmentRows[] = [
                $apptRow['appointment_code'] ?? '',
                personNameFromRow($apptRow),
                appointmentServiceLabel($apptRow['service_type'] ?? ''),
                formatDateDisplay($apptRow['appointment_date'] ?? ''),
                $timeLabel,
                appointmentStatusLabel($apptRow['status'] ?? ''),
            ];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Code', 'Citizen', 'Service', 'Date', 'Time', 'Status'],
            $appointmentRows,
            $row,
            [
                'section_title' => 'Appointments',
                'empty_message' => 'No appointments in this period.',
            ]
        );
    }

    if (in_array('prints', $sections, true)) {
        $printRows = [];
        foreach ($report['print_jobs'] ?? [] as $printRow) {
            $printRows[] = [
                formatReportDateTime($printRow['printed_at'] ?? null),
                printJobDocumentKindLabel((string) ($printRow['document_kind'] ?? '')),
                civilRecordTypeLabel((string) ($printRow['certificate_type'] ?? '')),
                (int) ($printRow['copies'] ?? 1),
                printJobSourceSummary($printRow),
                $printRow['printed_by'] ?? '',
            ];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Printed At', 'Document Kind', 'Record Type', 'Copies', 'Source', 'Printed By'],
            $printRows,
            $row,
            [
                'section_title'  => 'Print jobs',
                'empty_message'  => 'No print jobs in this period.',
                'column_formats' => [4 => 'number'],
            ]
        );
    }

    if (in_array('queue', $sections, true)) {
        $waitSummary = $report['queue_wait_summary'] ?? [];
        if (!empty($waitSummary['called_count'])) {
            $waitRows = [
                [
                    'Tickets called',
                    (int) ($waitSummary['called_count'] ?? 0),
                    'Average wait',
                    formatQueueWaitDuration($waitSummary['avg_seconds'] ?? null),
                ],
            ];
            foreach ($waitSummary['by_purpose'] ?? [] as $purposeKey => $purposeStats) {
                $waitRows[] = [
                    $purposeLabels[$purposeKey] ?? $purposeKey,
                    'Called: ' . (int) ($purposeStats['called_count'] ?? 0),
                    'Avg wait',
                    formatQueueWaitDuration($purposeStats['avg_seconds'] ?? null),
                ];
            }

            alcrosExcelWriteTable(
                $sheet,
                ['Metric', 'Value', 'Detail', 'Duration'],
                $waitRows,
                $row,
                ['section_title' => 'Queue wait before first call (average)']
            );
        }

        $queueRows = [];
        foreach ($report['queue_tickets'] as $queueRow) {
            $purpose = (string) ($queueRow['purpose'] ?? '');
            $firstCalled = $queueRow['first_called_at'] ?? $queueRow['called_at'] ?? null;

            $queueRows[] = [
                $queueRow['ticket_number'] ?? '',
                $purposeLabels[$purpose] ?? $purpose,
                $queueRow['status'] ?? '',
                personNameFromRow($queueRow),
                formatReportDateTime($queueRow['created_at'] ?? null),
                formatReportDateTime($firstCalled),
                $queueRow['wait_label'] ?? formatQueueWaitDuration($queueRow['wait_seconds'] ?? null),
            ];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Ticket', 'Purpose', 'Status', 'Citizen', 'Issued', 'First called', 'Wait before call'],
            $queueRows,
            $row,
            [
                'section_title' => 'Queue tickets',
                'empty_message' => 'No queue tickets in this period.',
            ]
        );
    }

    if (in_array('records', $sections, true)) {
        $quarterRows = [];
        foreach ($recordsReport['quarters'] as $quarter) {
            $quarterRows[] = [
                $quarter['label'] ?? '',
                (int) ($quarter['birth'] ?? 0),
                (int) ($quarter['death'] ?? 0),
                (int) ($quarter['marriage'] ?? 0),
                (int) ($quarter['total'] ?? 0),
            ];
        }

        $quarterRows[] = [
            $reportYear . ' total',
            (int) ($recordsReport['year_totals']['birth'] ?? 0),
            (int) ($recordsReport['year_totals']['death'] ?? 0),
            (int) ($recordsReport['year_totals']['marriage'] ?? 0),
            (int) ($recordsReport['year_totals']['total'] ?? 0),
        ];

        $priorYear = (int) ($recordsReport['prior_year'] ?? ($reportYear - 1));
        $priorTotals = $recordsReport['prior_year_totals'] ?? null;
        if (is_array($priorTotals)) {
            $quarterRows[] = [
                $priorYear . ' total (comparison)',
                (int) ($priorTotals['birth'] ?? 0),
                (int) ($priorTotals['death'] ?? 0),
                (int) ($priorTotals['marriage'] ?? 0),
                (int) ($priorTotals['total'] ?? 0),
            ];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Quarter', 'Birth', 'Death', 'Marriage', 'Quarter Total'],
            $quarterRows,
            $row,
            [
                'section_title'  => 'Civil records quarterly summary (' . $reportYear . ')',
                'column_formats' => [2 => 'number', 3 => 'number', 4 => 'number', 5 => 'number'],
            ]
        );

        spreadsheetExportCivilRecordsDetailSection($pdo, $sheet, $row, $reportYear, $recordsType);
    }

    alcrosExcelSendDownload($workbook, 'alcros_report_' . date('Y-m-d') . '.xlsx');
}

function spreadsheetExportCivilRecordsDetailSection(
    PDO $pdo,
    AlcrosXlsxSheet $sheet,
    int &$row,
    int $year,
    string $recordsType
): void {
    require_once __DIR__ . '/civil_record_schema.php';
    require_once __DIR__ . '/printing.php';

    $validTypes = ['birth', 'death', 'marriage'];
    $types = $recordsType === 'all'
        ? $validTypes
        : (in_array($recordsType, $validTypes, true) ? [$recordsType] : $validTypes);

    alcrosExcelWriteSectionHeading(
        $sheet,
        'Civil records detail (' . $year . ')',
        $row,
        6
    );
    $sheet->setCell(1, $row, 'Record filter: ' . ($recordsType === 'all' ? 'All types' : civilRecordTypeLabel($recordsType)));
    $sheet->mergeCells(1, $row, 6, $row);
    $sheet->applyStyle(1, $row, 6, $row, ['italic' => true, 'color' => '64748B', 'wrap' => true]);
    $row += 2;

    $chunkSize = 2500;

    foreach ($types as $type) {
        [$typeWhere, $typeParams] = reportRecordsExportWhere($year, $recordsType === 'all' ? $type : $recordsType);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM ' . reportRecordsExportFromClause() . " WHERE $typeWhere");
        $countStmt->execute($typeParams);
        $typeCount = (int) $countStmt->fetchColumn();

        $headers = civilRecordCsvColumns($type);
        $tableRows = [];

        if ($typeCount > 0) {
            $afterId = 0;
            while (true) {
                $chunk = reportExportRecordsFetchChunk($pdo, $typeWhere, $typeParams, $afterId, $chunkSize);
                if ($chunk === []) {
                    break;
                }

                $chunk = hydrateCivilRecordRows($pdo, $chunk);
                foreach ($chunk as $record) {
                    $tableRows[] = reportCivilRecordExportRowValues($record, $type);
                }

                $afterId = (int) ($chunk[array_key_last($chunk)]['id'] ?? $afterId);
                unset($chunk);
            }
        }

        alcrosExcelWriteTable($sheet, $headers, $tableRows, $row, [
            'section_title' => strtoupper(civilRecordTypeLabel($type)) . ' records (' . $typeCount . ')',
            'empty_message' => $typeCount === 0 ? 'No records for this type and year.' : '',
        ]);
    }
}

function exportCivilRecordsXlsx(PDO $pdo, array $filters): void
{
    require_once __DIR__ . '/civil_record_schema.php';
    require_once __DIR__ . '/printing.php';

    $validTypes = ['birth', 'death', 'marriage'];
    $exportType = $filters['type'] ?? 'all';
    [$where, $params] = buildRecordsExportWhere($exportType);
    $types = ($exportType !== 'all' && in_array($exportType, $validTypes, true))
        ? [$exportType]
        : $validTypes;
    $chunkSize = recordsExportCsvChunkSize();
    $typeCounts = exportCivilRecordsTypeCounts($pdo, $where, $params, $types);
    $totalRecords = array_sum($typeCounts);

    $workbook = alcrosExcelNewSpreadsheet('ALCROS Civil Records Export');
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Civil records');

    $row = 1;
    alcrosExcelWriteMetaBlock($sheet, [
        'ALCROS Civil Records Export',
        ['Generated on', date('Y-m-d g:i A')],
        ['Total records', (string) $totalRecords],
        ['Scope', $exportType === 'all' ? 'All record types' : civilRecordTypeLabel($exportType)],
    ], $row);

    foreach ($types as $type) {
        $typeCount = $typeCounts[$type] ?? 0;
        $headers = civilRecordCsvColumns($type);
        $tableRows = [];

        if ($typeCount > 0) {
            [$typeWhere, $typeParams] = exportCivilRecordsTypeWhere($where, $params, $type);
            $afterId = 0;

            while (true) {
                $chunk = exportCivilRecordsFetchChunk($pdo, $typeWhere, $typeParams, $afterId, $chunkSize);
                if ($chunk === []) {
                    break;
                }

                $chunk = hydrateCivilRecordRows($pdo, $chunk);
                foreach ($chunk as $record) {
                    $tableRows[] = civilRecordExportRowValues($record, $type);
                }

                $afterId = (int) ($chunk[array_key_last($chunk)]['id'] ?? $afterId);
                unset($chunk);
            }
        }

        $sectionTitle = count($types) > 1
            ? strtoupper(civilRecordTypeLabel($type)) . ' records (' . $typeCount . ')'
            : civilRecordTypeLabel($type) . ' records';

        alcrosExcelWriteTable($sheet, $headers, $tableRows, $row, [
            'section_title' => $sectionTitle,
            'empty_message' => $typeCount === 0 ? 'No records for this type.' : '',
        ]);
    }

    $filename = 'alcros_civil_records_'
        . ($exportType !== 'all' ? $exportType . '_' : 'all_')
        . date('Y-m-d') . '.xlsx';

    alcrosExcelSendDownload($workbook, $filename);
}

/** @param list<array<string, mixed>> $exportRows */
function exportActivityLogXlsx(array $exportRows, string $rangeLabel, string $search, string $staffFilter, int $exportLimit): void
{
    $tableRows = [];
    foreach ($exportRows as $logRow) {
        $staffName = personNameFromRow($logRow);
        if ($staffName === '' && !empty($logRow['staff_id'])) {
            $staffName = (string) $logRow['staff_id'];
        }
        if ($staffName === '') {
            $staffName = 'System';
        }

        $tableRows[] = [
            $logRow['created_at'] ?? '',
            $logRow['staff_id'] ?? '',
            $staffName,
            $logRow['action'] ?? '',
            $logRow['details'] ?? '',
        ];
    }

    $workbook = alcrosExcelNewSpreadsheet('ALCROS Activity Log');
    $sheet = $workbook->getActiveSheet();
    $sheet->setTitle('Activity log');

    $meta = [
        'ALCROS Activity Log',
        ['Office', getSetting('office_name', 'Local Civil Registrar Office')],
        ['Range', $rangeLabel],
    ];
    if ($search !== '') {
        $meta[] = ['Search', $search];
    }
    if ($staffFilter !== '') {
        $meta[] = ['Staff filter', $staffFilter];
    }
    $meta[] = ['Exported', date('Y-m-d H:i:s')];
    $meta[] = ['Row limit', (string) $exportLimit];

    $row = 1;
    alcrosExcelWriteMetaBlock($sheet, $meta, $row);

    alcrosExcelWriteTable(
        $sheet,
        ['Created at', 'Staff ID', 'Staff name', 'Action', 'Details'],
        $tableRows,
        $row,
        [
            'section_title' => 'Activity entries',
            'empty_message' => 'No activity log entries match the current filters.',
        ]
    );

    alcrosExcelSendDownload($workbook, 'alcros_activity_log_' . date('Y-m-d') . '.xlsx');
}
