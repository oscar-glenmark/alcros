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

function alcrosExcelFaviconPath(): ?string
{
    $path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'favicon.png';

    return is_readable($path) ? $path : null;
}

function alcrosExcelMetaLogoHeightPx(int $firstMetaRow, int $spanEndRow, float $titleRowPt, float $detailRowPt): int
{
    if ($spanEndRow < $firstMetaRow) {
        return 0;
    }

    $totalPoints = 0.0;
    for ($metaRow = $firstMetaRow; $metaRow <= $spanEndRow; $metaRow++) {
        $totalPoints += $metaRow === $firstMetaRow ? $titleRowPt : $detailRowPt;
    }

    return (int) max(1, round($totalPoints * (96 / 72)));
}

function alcrosExcelPlaceFaviconLogo(
    AlcrosXlsxSheet $sheet,
    int $firstMetaRow,
    int $spanEndRow,
    float $titleRowPt,
    float $detailRowPt
): void {
    $path = alcrosExcelFaviconPath();
    if ($path === null) {
        return;
    }

    $info = @getimagesize($path);
    if ($info === false || ($info[2] ?? 0) !== IMAGETYPE_PNG) {
        return;
    }

    $sourceW = (int) ($info[0] ?? 0);
    $sourceH = (int) ($info[1] ?? 0);
    if ($sourceW < 1 || $sourceH < 1) {
        return;
    }

    $heightPx = alcrosExcelMetaLogoHeightPx($firstMetaRow, $spanEndRow, $titleRowPt, $detailRowPt);
    if ($heightPx < 1) {
        return;
    }

    $widthPx = (int) max(1, round($sourceW * ($heightPx / $sourceH)));

    $logoCol = 1;
    $gutterCol = 2;
    $logoColWidth = max(12.0, min(24.0, ($widthPx / 6.5) + 1));
    $sheet->setColumnWidth($logoCol, $logoColWidth);
    $sheet->setColumnWidth($gutterCol, max(5.0, min(8.0, ($widthPx / 18) + 4)));
    $sheet->addImageFromFile($logoCol, $firstMetaRow, $path, $widthPx, $heightPx);
}

function alcrosExcelWriteMetaBlock(AlcrosXlsxSheet $sheet, array $lines, int &$row): void
{
    $metaStartRow = $row;
    $labelCol = 3;
    $valueCol = 4;
    $valueMergeEndCol = 7;
    $titleRowPt = 22.0;
    $detailRowPt = 19.0;
    $periodRow = null;
    $generatedRow = null;

    foreach ($lines as $line) {
        if (is_string($line)) {
            $sheet->setCell($labelCol, $row, $line);
            $sheet->mergeCells($labelCol, $row, $valueMergeEndCol, $row);
            $sheet->applyStyle($labelCol, $row, $labelCol, $row, ['bold' => true, 'size' => 12]);
            $sheet->setRowHeight($row, $titleRowPt);
            $row++;
            continue;
        }

        $label = trim((string) ($line[0] ?? ''));
        if (strcasecmp($label, 'Period') === 0 || strcasecmp($label, 'Range') === 0) {
            $periodRow = $row;
        }
        if (strcasecmp($label, 'Generated') === 0 || strcasecmp($label, 'Generated on') === 0) {
            $generatedRow = $row;
        }

        $sheet->setCell($labelCol, $row, $label);
        $sheet->setCell($valueCol, $row, (string) ($line[1] ?? ''));
        $sheet->mergeCells($valueCol, $row, $valueMergeEndCol, $row);
        $sheet->applyStyle($labelCol, $row, $labelCol, $row, ['bold' => true, 'size' => 10]);
        $sheet->applyStyle($valueCol, $row, $valueCol, $row, ['size' => 10]);
        $sheet->setRowHeight($row, $detailRowPt);
        $row++;
    }

    $metaEndRow = $row - 1;
    if ($metaEndRow >= $metaStartRow) {
        $logoSpanEndRow = $periodRow ?? ($generatedRow !== null ? $generatedRow - 1 : $metaEndRow);
        if ($logoSpanEndRow < $metaStartRow) {
            $logoSpanEndRow = $metaEndRow;
        }
        alcrosExcelPlaceFaviconLogo($sheet, $metaStartRow, $logoSpanEndRow, $titleRowPt, $detailRowPt);
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

function alcrosExcelEstimateRowLineCount(AlcrosXlsxSheet $sheet, int $row, int $colCount): int
{
    $maxLines = 1;

    for ($col = 1; $col <= $colCount; $col++) {
        $text = trim((string) ($sheet->getCellValue($col, $row) ?? ''));
        if ($text === '') {
            continue;
        }

        $explicitLines = count(preg_split('/\R/u', $text) ?: []);
        $colWidth = $sheet->getColumnWidth($col) ?? 12.0;
        $charsPerLine = max(8, (int) floor($colWidth / 1.05));
        $wrappedLines = max(1, (int) ceil(mb_strlen($text, 'UTF-8') / $charsPerLine));

        $maxLines = max($maxLines, $explicitLines, $wrappedLines);
    }

    return $maxLines;
}

function alcrosExcelFitRowHeights(
    AlcrosXlsxSheet $sheet,
    int $headerRow,
    int $lastRow,
    int $colCount,
    float $lineHeight = 15.0
): void {
    if ($lastRow < $headerRow || $colCount < 1) {
        return;
    }

    for ($row = $headerRow; $row <= $lastRow; $row++) {
        $lines = alcrosExcelEstimateRowLineCount($sheet, $row, $colCount);
        $height = $row === $headerRow
            ? max(22.0, min(120.0, ($lines * $lineHeight) + 6.0))
            : max(15.0, min(120.0, ($lines * $lineHeight) + 4.0));
        $sheet->setRowHeight($row, $height);
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
        'wrap'   => true,
    ]);

    if ($lastRow > $headerRow) {
        $sheet->applyStyle(1, $headerRow + 1, $colCount, $lastRow, [
            'halign' => 'left',
            'valign' => 'top',
            'wrap'   => true,
        ]);

        for ($dataRow = $headerRow + 1; $dataRow <= $lastRow; $dataRow++) {
            if ((($dataRow - $headerRow) % 2) === 0) {
                $sheet->applyStyle(1, $dataRow, $colCount, $dataRow, ['fill' => 'F1F5F9']);
            }
        }
    }

    alcrosExcelFitRowHeights($sheet, $headerRow, $lastRow, $colCount);

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
    if ($lastRow > $headerRow) {
        $sheet->setAutoFilter(1, $headerRow, $colCount, $lastRow);
    }
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
