<?php

require_once __DIR__ . '/xlsx_writer.php';

function alcrosExcelNewSpreadsheet(string $title = 'ALCROS Export'): AlcrosXlsxWorkbook
{
    $workbook = new AlcrosXlsxWorkbook($title);
    $workbook->setProperties($title, 'ALCROS');

    return $workbook;
}

function alcrosExcelSendDownload(AlcrosXlsxWorkbook $spreadsheet, string $filename): never
{
    $filename = preg_replace('/[^\w\.\-]+/u', '_', $filename) ?: 'export.xlsx';
    if (!str_ends_with(strtolower($filename), '.xlsx')) {
        $filename .= '.xlsx';
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'alcros_xlsx_dl_');
    if ($tempFile === false) {
        throw new RuntimeException('Could not create a temporary Excel download file.');
    }

    try {
        $spreadsheet->writeToFile($tempFile);
        $size = filesize($tempFile);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        readfile($tempFile);
    } finally {
        if (is_file($tempFile)) {
            @unlink($tempFile);
        }
    }

    exit;
}

function alcrosExcelWriteMetaBlock(AlcrosXlsxSheet $sheet, array $lines, int &$row): void
{
    foreach ($lines as $line) {
        if (is_string($line)) {
            $sheet->setCell(1, $row, $line);
            $sheet->mergeCells(1, $row, 6, $row);
            $sheet->applyStyle(1, $row, 1, $row, ['bold' => true, 'size' => 12]);
            $row++;
            continue;
        }

        $sheet->setCell(1, $row, (string) ($line[0] ?? ''));
        $sheet->setCell(2, $row, (string) ($line[1] ?? ''));
        $sheet->applyStyle(1, $row, 1, $row, ['bold' => true]);
        $row++;
    }

    $row++;
}

function alcrosExcelWriteSectionHeading(AlcrosXlsxSheet $sheet, string $title, int &$row, int $colCount): void
{
    $sheet->setCell(1, $row, $title);
    $sheet->mergeCells(1, $row, max(1, $colCount), $row);
    $sheet->applyStyle(1, $row, max(1, $colCount), $row, [
        'bold'   => true,
        'size'   => 11,
        'color'  => '0F172A',
        'fill'   => 'E2E8F0',
        'valign' => 'center',
    ]);
    $sheet->setRowHeight($row, 20);
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

function alcrosExcelFitColumns(AlcrosXlsxSheet $sheet, int $headerRow, int $lastRow, int $colCount): void
{
    for ($col = 1; $col <= $colCount; $col++) {
        $maxLen = 0;
        for ($row = $headerRow; $row <= $lastRow; $row++) {
            $maxLen = max($maxLen, alcrosExcelCellTextLength($sheet->getCellValue($col, $row)));
        }

        $sheet->setColumnWidth($col, min(72.0, max(10.0, ($maxLen * 1.12) + 2.5)));
    }
}

function alcrosExcelApplyTableStyles(
    AlcrosXlsxSheet $sheet,
    int $headerRow,
    int $lastRow,
    int $colCount,
    array $columnFormats = []
): void {
    if ($lastRow < $headerRow || $colCount < 1) {
        return;
    }

    alcrosExcelFitColumns($sheet, $headerRow, $lastRow, $colCount);

    $sheet->applyStyle(1, $headerRow, $colCount, $lastRow, ['border' => 'CBD5E1']);

    $sheet->applyStyle(1, $headerRow, $colCount, $headerRow, [
        'bold'   => true,
        'color'  => 'FFFFFF',
        'fill'   => '1E40AF',
        'halign' => 'left',
        'valign' => 'center',
    ]);
    $sheet->setRowHeight($headerRow, 22);

    if ($lastRow > $headerRow) {
        $sheet->applyStyle(1, $headerRow + 1, $colCount, $lastRow, [
            'halign' => 'left',
            'valign' => 'top',
            'wrap'   => true,
        ]);
    }

    foreach ($columnFormats as $colIndex => $format) {
        if ($lastRow <= $headerRow) {
            break;
        }

        $colIndex = (int) $colIndex;
        $format = (string) $format;
        $sheet->applyStyle($colIndex, $headerRow + 1, $colIndex, $lastRow, ['format' => $format]);

        if ($format === 'number') {
            $sheet->coerceNumeric($colIndex, $headerRow + 1, $lastRow);
        }
    }

    $sheet->freezeAbove($headerRow + 1);
    $sheet->setAutoFilter(1, $headerRow, $colCount, $lastRow);
}

function alcrosExcelWriteTable(
    AlcrosXlsxSheet $sheet,
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
        $sheet->setCell(1, $row, (string) $options['section_note']);
        $sheet->mergeCells(1, $row, $colCount, $row);
        $sheet->applyStyle(1, $row, $colCount, $row, [
            'italic' => true,
            'color'  => '64748B',
            'wrap'   => true,
        ]);
        $row++;
        $headerRow = $row;
    }

    foreach ($headers as $index => $header) {
        $sheet->setCell((int) $index + 1, $row, $header);
    }
    $row++;

    $emptyMessage = (string) ($options['empty_message'] ?? '');
    if ($rows === [] && $emptyMessage !== '') {
        $sheet->setCell(1, $row, $emptyMessage);
        $sheet->mergeCells(1, $row, $colCount, $row);
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
                $sheet->setCell((int) $index + 1, $row, $value);
            }
        } else {
            foreach ($headers as $index => $header) {
                $sheet->setCell((int) $index + 1, $row, $record[$header] ?? $record[$index] ?? '');
            }
        }
        $row++;
    }

    alcrosExcelApplyTableStyles($sheet, $headerRow, $row - 1, $colCount, $options['column_formats'] ?? []);
    $row++;

    return $headerRow;
}
