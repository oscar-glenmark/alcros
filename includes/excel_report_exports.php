<?php

require_once __DIR__ . '/excel_export.php';

function exportQuarterlyCivilRecordsXlsx(array $report): void
{
    $year = (int) $report['year'];
    $spreadsheet = alcrosExcelNewSpreadsheet('ALCROS Civil Records Quarterly ' . $year);
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Quarterly ' . $year);

    $row = 1;
    alcrosExcelWriteMetaBlock($sheet, [
        'ALCROS Civil Records — Quarterly Registration Report',
        ['Office', $report['office_name']],
        ['Calendar year', (string) $year],
        ['Generated on', $report['generated_at']],
        ['Note', 'Counts use registration date, or event/entry date when registration date is not set.'],
    ], $row);

    $rows = [];
    foreach ($report['quarters'] as $quarter) {
        $rows[] = [
            $quarter['label'],
            $quarter['birth'],
            $quarter['death'],
            $quarter['marriage'],
            $quarter['total'],
        ];
    }
    $rows[] = [
        'Year total',
        $report['year_totals']['birth'],
        $report['year_totals']['death'],
        $report['year_totals']['marriage'],
        $report['year_totals']['total'],
    ];

    alcrosExcelWriteTable(
        $sheet,
        ['Quarter', 'Birth', 'Death', 'Marriage', 'Quarter total'],
        $rows,
        $row,
        [
            'section_title' => 'Quarterly registration counts',
            'column_formats' => [
                2 => 'number',
                3 => 'number',
                4 => 'number',
                5 => 'number',
            ],
        ]
    );

    alcrosExcelSendDownload($spreadsheet, 'ALCROS_Civil_Records_Quarterly_' . $year . '.xlsx');
}

function exportOperationalReportXlsx(array $report, string $type): void
{
    $from = $report['from'];
    $to = $report['to'];
    $periodLabel = reportRangeLabel('custom', $from, $to);
    $filename = 'ALCROS_Operational_Report_' . $from . ($from !== $to ? '_to_' . $to : '') . '.xlsx';

    $spreadsheet = alcrosExcelNewSpreadsheet('ALCROS Operational Report');
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Report');

    $row = 1;
    if ($type === 'full' || $type === 'summary') {
        alcrosExcelWriteMetaBlock($sheet, [
            'ALCROS Operational Report',
            ['Report title', 'Daily operations summary for the Local Civil Registry office'],
            ['System name', $report['site_name']],
            ['Office', $report['office_name']],
            ['Reporting period', $periodLabel],
            ['Report generated on', formatReportDateTime($report['generated_at'])],
        ], $row);

        $summaryRows = [];
        foreach (reportSummaryMetricLabels() as $key => $label) {
            if (!array_key_exists($key, $report['summary'])) {
                continue;
            }
            $summaryRows[] = [$label, $report['summary'][$key]];
        }

        alcrosExcelWriteTable(
            $sheet,
            ['Description', 'Count'],
            $summaryRows,
            $row,
            [
                'section_title' => 'Summary',
                'section_note' => 'Headline numbers for the reporting period. Items marked “right now” show the current live count, not just the selected dates.',
                'column_formats' => [2 => 'number'],
            ]
        );
    }

    if ($type === 'full' || $type === 'requests') {
        $statusRows = [];
        foreach ($report['requests_by_status'] as $status => $count) {
            $statusRows[] = [requestStatusLabel($status), $count];
        }
        alcrosExcelWriteTable(
            $sheet,
            ['Request status', 'Number of requests'],
            $statusRows,
            $row,
            [
                'section_title' => 'Document requests — status summary',
                'empty_message' => 'No document requests were submitted during this period.',
                'column_formats' => [2 => 'number'],
            ]
        );

        $typeRows = [];
        foreach ($report['requests_by_type'] as $docType => $count) {
            $typeRows[] = [documentTypeLabel($docType), $count];
        }
        alcrosExcelWriteTable(
            $sheet,
            ['Document type', 'Number of requests'],
            $typeRows,
            $row,
            [
                'section_title' => 'Document requests — document type summary',
                'empty_message' => 'No document types to show for this period.',
                'column_formats' => [2 => 'number'],
            ]
        );

        $requestRows = array_map(static fn ($item) => [
            $item['tracking_code'],
            personNameFromRow($item),
            documentTypeLabel($item['document_type']),
            requestStatusLabel($item['status']),
            formatReportDateTime($item['submitted_at']),
            formatReportDateTime($item['updated_at']),
        ], $report['requests']);

        alcrosExcelWriteTable(
            $sheet,
            ['Tracking code', 'Citizen full name', 'Document requested', 'Current status', 'Date submitted', 'Last status update'],
            $requestRows,
            $row,
            [
                'section_title' => 'Document requests — full list',
                'section_note' => 'One row per online document request submitted in the reporting period.',
                'empty_message' => 'No document requests were submitted during this period.',
                'column_formats' => [5 => 'datetime', 6 => 'datetime'],
            ]
        );
    }

    if ($type === 'full' || $type === 'appointments') {
        $apptStatusRows = [];
        foreach ($report['appointments_by_status'] as $status => $count) {
            $apptStatusRows[] = [appointmentStatusLabel($status), $count];
        }
        alcrosExcelWriteTable(
            $sheet,
            ['Appointment status', 'Number of appointments'],
            $apptStatusRows,
            $row,
            [
                'section_title' => 'Appointments — status summary',
                'empty_message' => 'No appointments were scheduled during this period.',
                'column_formats' => [2 => 'number'],
            ]
        );

        $appointmentRows = array_map(static fn ($item) => [
            $item['appointment_code'],
            personNameFromRow($item),
            appointmentServiceLabel($item['service_type']),
            formatRecordDate($item['appointment_date']),
            formatReportTime($item['appointment_time']),
            appointmentStatusLabel($item['status']),
            appointmentSourceLabel((string) ($item['source'] ?? '')),
            formatReportDateTime($item['created_at']),
        ], $report['appointments']);

        alcrosExcelWriteTable(
            $sheet,
            ['Appointment code', 'Citizen full name', 'Service or document', 'Visit date', 'Visit time', 'Status', 'Appointment type', 'Date booked online'],
            $appointmentRows,
            $row,
            [
                'section_title' => 'Appointments — full list',
                'empty_message' => 'No appointments were scheduled during this period.',
                'column_formats' => [4 => 'date', 8 => 'datetime'],
            ]
        );
    }

    if ($type === 'full' || $type === 'queue') {
        $purposeRows = [];
        foreach ($report['queue_by_purpose'] as $purpose => $count) {
            $purposeRows[] = [queuePurposeLabel($purpose), $count];
        }
        alcrosExcelWriteTable(
            $sheet,
            ['Queue purpose', 'Number of tickets'],
            $purposeRows,
            $row,
            [
                'section_title' => 'Queue — purpose summary',
                'empty_message' => 'No queue tickets were created during this period.',
                'column_formats' => [2 => 'number'],
            ]
        );

        $queueRows = array_map(static fn ($item) => [
            $item['ticket_number'],
            queuePurposeLabel((string) $item['purpose']),
            queueStatusLabel((string) $item['status']),
            personNameFromRow($item) ?: 'Not provided',
            $item['reference_code'] ?: 'None',
            $item['window_number'] ? 'Window ' . $item['window_number'] : 'Not assigned',
            formatReportDateTime($item['created_at']),
            formatReportDateTime($item['called_at'] ?? null),
        ], $report['queue_tickets']);

        alcrosExcelWriteTable(
            $sheet,
            ['Ticket number', 'Purpose of visit', 'Ticket status', 'Citizen name (if provided)', 'Reference code (tracking / appointment)', 'Service window / table', 'Ticket issued on', 'Called to window on'],
            $queueRows,
            $row,
            [
                'section_title' => 'Queue tickets — full list',
                'empty_message' => 'No queue tickets were created during this period.',
                'column_formats' => [7 => 'datetime', 8 => 'datetime'],
            ]
        );
    }

    if ($type === 'full' || $type === 'activity') {
        $activityRows = array_map(static fn ($item) => [
            $item['staff_id'] ?: 'System',
            $item['action'],
            $item['details'] ?: 'No extra details',
            formatReportDateTime($item['created_at']),
        ], $report['activities']);

        alcrosExcelWriteTable(
            $sheet,
            ['Staff account ID', 'Action performed', 'Additional details', 'Date and time'],
            $activityRows,
            $row,
            [
                'section_title' => 'Staff activity log',
                'section_note' => 'Actions recorded from staff accounts during the reporting period (latest 500 entries).',
                'empty_message' => 'No staff activity was logged during this period.',
                'column_formats' => [4 => 'datetime'],
            ]
        );
    }

    alcrosExcelSendDownload($spreadsheet, $filename);
}
