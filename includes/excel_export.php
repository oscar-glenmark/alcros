<?php

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function alcrosExcelBootstrap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $loaded = true;
}

function alcrosExcelNewSpreadsheet(string $title = 'ALCROS Export'): Spreadsheet
{
    alcrosExcelBootstrap();
    $spreadsheet = new Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('ALCROS')
        ->setTitle($title)
        ->setSubject($title);

    return $spreadsheet;
}

function alcrosExcelSendDownload(Spreadsheet $spreadsheet, string $filename): never
{
    alcrosExcelBootstrap();
    $filename = preg_replace('/[^\w\.\-]+/u', '_', $filename) ?: 'export.xlsx';
    if (!str_ends_with(strtolower($filename), '.xlsx')) {
        $filename .= '.xlsx';
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function alcrosExcelColumnLetter(int $columnIndex): string
{
    return Coordinate::stringFromColumnIndex(max(1, $columnIndex));
}

function alcrosExcelSetCell(Worksheet $sheet, int $columnIndex, int $row, mixed $value): void
{
    $sheet->setCellValue(alcrosExcelColumnLetter($columnIndex) . $row, $value);
}

function alcrosExcelWriteMetaBlock(Worksheet $sheet, array $lines, int &$row): void
{
    foreach ($lines as $line) {
        if (is_string($line)) {
            $sheet->setCellValue("A{$row}", $line);
            $sheet->mergeCells("A{$row}:F{$row}");
            $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
            $row++;
            continue;
        }

        $label = (string) ($line[0] ?? '');
        $value = (string) ($line[1] ?? '');
        $sheet->setCellValue("A{$row}", $label);
        $sheet->setCellValue("B{$row}", $value);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true);
        $row++;
    }

    $row++;
}

function alcrosExcelWriteSectionHeading(Worksheet $sheet, string $title, int &$row, int $colCount): void
{
    $endCol = alcrosExcelColumnLetter($colCount);
    $range = "A{$row}:{$endCol}{$row}";
    $sheet->setCellValue("A{$row}", $title);
    $sheet->mergeCells($range);
    $sheet->getStyle($range)->applyFromArray([
        'font' => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0F172A']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E2E8F0']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
    ]);
    $sheet->getRowDimension($row)->setRowHeight(20);
    $row++;
}

function alcrosExcelCellTextLength(mixed $value): int
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return 0;
    }

    $max = 0;
    foreach (preg_split('/\R/u', $text) ?: [] as $line) {
        $max = max($max, mb_strlen($line, 'UTF-8'));
    }

    return $max;
}

function alcrosExcelFitColumns(Worksheet $sheet, int $headerRow, int $lastRow, int $colCount): void
{
    for ($col = 1; $col <= $colCount; $col++) {
        $maxLen = 0;
        for ($row = $headerRow; $row <= $lastRow; $row++) {
            $cell = $sheet->getCell(alcrosExcelColumnLetter($col) . $row);
            $maxLen = max($maxLen, alcrosExcelCellTextLength($cell->getFormattedValue() ?? $cell->getValue()));
        }

        $width = min(72.0, max(10.0, ($maxLen * 1.12) + 2.5));
        $column = $sheet->getColumnDimensionByColumn($col);
        $column->setAutoSize(false);
        $column->setWidth($width);
    }
}

function alcrosExcelApplyTableStyles(
    Worksheet $sheet,
    int $headerRow,
    int $lastRow,
    int $colCount,
    array $columnFormats = []
): void {
    if ($lastRow < $headerRow || $colCount < 1) {
        return;
    }

    $endCol = alcrosExcelColumnLetter($colCount);
    $headerRange = "A{$headerRow}:{$endCol}{$headerRow}";
    $tableRange = "A{$headerRow}:{$endCol}{$lastRow}";

    alcrosExcelFitColumns($sheet, $headerRow, $lastRow, $colCount);

    $sheet->getStyle($tableRange)->applyFromArray([
        'borders' => [
            'allBorders' => [
                'borderStyle' => Border::BORDER_THIN,
                'color' => ['rgb' => 'CBD5E1'],
            ],
        ],
    ]);

    $sheet->getStyle($headerRange)->applyFromArray([
        'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E40AF']],
        'alignment' => [
            'horizontal' => Alignment::HORIZONTAL_LEFT,
            'vertical' => Alignment::VERTICAL_CENTER,
            'wrapText' => false,
        ],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(22);

    if ($lastRow > $headerRow) {
        $sheet->getStyle('A' . ($headerRow + 1) . ":{$endCol}{$lastRow}")->applyFromArray([
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_TOP,
                'wrapText' => true,
            ],
        ]);
    }

    foreach ($columnFormats as $colIndex => $format) {
        if ($lastRow <= $headerRow) {
            break;
        }
        $colLetter = alcrosExcelColumnLetter((int) $colIndex);
        $range = "{$colLetter}" . ($headerRow + 1) . ":{$colLetter}{$lastRow}";
        $excelFormat = match ($format) {
            'date'     => NumberFormat::FORMAT_DATE_YYYYMMDD,
            'datetime' => NumberFormat::FORMAT_DATE_DATETIME_BETTER,
            'number'   => NumberFormat::FORMAT_NUMBER,
            default    => NumberFormat::FORMAT_TEXT,
        };
        $sheet->getStyle($range)->getNumberFormat()->setFormatCode($excelFormat);
    }

    $sheet->freezePane('A' . ($headerRow + 1));
    $sheet->setAutoFilter($tableRange);
}

function alcrosExcelWriteTable(
    Worksheet $sheet,
    array $headers,
    array $rows,
    int &$row,
    array $options = []
): int {
    $colCount = max(1, count($headers));
    $headerRow = $row;

    if (($options['section_title'] ?? '') !== '') {
        alcrosExcelWriteSectionHeading($sheet, (string) $options['section_title'], $row, $colCount);
        $headerRow = $row;
    }

    if (($options['section_note'] ?? '') !== '') {
        $endCol = alcrosExcelColumnLetter($colCount);
        $sheet->setCellValue("A{$row}", (string) $options['section_note']);
        $sheet->mergeCells("A{$row}:{$endCol}{$row}");
        $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setRGB('64748B');
        $sheet->getStyle("A{$row}")->getAlignment()->setWrapText(true);
        $row++;
        $headerRow = $row;
    }

    foreach ($headers as $index => $header) {
        alcrosExcelSetCell($sheet, $index + 1, $row, $header);
    }
    $row++;

    $emptyMessage = (string) ($options['empty_message'] ?? '');
    if ($rows === [] && $emptyMessage !== '') {
        $sheet->setCellValue("A{$row}", $emptyMessage);
        $sheet->mergeCells('A' . $row . ':' . alcrosExcelColumnLetter($colCount) . $row);
        $row++;
        alcrosExcelApplyTableStyles($sheet, $headerRow, $row - 1, $colCount, $options['column_formats'] ?? []);
        $row++;

        return $headerRow;
    }

    foreach ($rows as $record) {
        if (!is_array($record)) {
            $row++;
            continue;
        }

        if (array_is_list($record)) {
            foreach ($record as $index => $value) {
                alcrosExcelSetCell($sheet, $index + 1, $row, $value);
            }
        } else {
            foreach ($headers as $index => $header) {
                alcrosExcelSetCell($sheet, $index + 1, $row, $record[$header] ?? $record[$index] ?? '');
            }
        }
        $row++;
    }

    alcrosExcelApplyTableStyles($sheet, $headerRow, $row - 1, $colCount, $options['column_formats'] ?? []);
    $row++;

    return $headerRow;
}

function alcrosExcelWriteKeyValueTable(Worksheet $sheet, array $pairs, int &$row, string $sectionTitle = ''): void
{
    $rows = [];
    foreach ($pairs as $label => $value) {
        $rows[] = [$label, $value];
    }

    alcrosExcelWriteTable(
        $sheet,
        ['Description', 'Count'],
        $rows,
        $row,
        [
            'section_title' => $sectionTitle,
            'column_formats' => [2 => 'number'],
        ]
    );
}
