<?php

/**
 * Dependency-free XLSX writer.
 *
 * Builds Office Open XML workbooks without a third-party spreadsheet library.
 * Uses ZipArchive when available; otherwise packs the archive in pure PHP.
 * Only the features
 * ALCROS exports need are implemented: text and number cells, fonts, solid
 * fills, thin borders, alignment, column widths, row heights, merged ranges,
 * and one frozen pane plus one autofilter per sheet.
 *
 * Style arrays accept: bold, italic, size, color, fill, wrap, halign, valign,
 * border, format ('general' | 'text' | 'number' | 'date' | 'datetime').
 */

final class AlcrosXlsxSheet
{
    private string $name = 'Sheet1';

    /** @var array<int, array<int, array{v: mixed, numeric: bool}>> row => col => cell */
    private array $cells = [];

    /** @var array<int, array<int, array<string, mixed>>> row => col => style */
    private array $cellStyles = [];

    /** @var list<string> */
    private array $merges = [];

    /** @var array<int, float> */
    private array $rowHeights = [];

    /** @var array<int, float> */
    private array $colWidths = [];

    private ?int $freezeRow = null;
    private ?string $autoFilter = null;
    private int $maxRow = 0;
    private int $maxCol = 0;

    public function __construct(string $name)
    {
        $this->setTitle($name);
    }

    public function setTitle(string $name): self
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], ' ', trim($name));
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '') {
            $name = 'Sheet';
        }
        // Excel rejects sheet names longer than 31 characters.
        $this->name = mb_substr($name, 0, 31, 'UTF-8');

        return $this;
    }

    public function getTitle(): string
    {
        return $this->name;
    }

    public function setCell(int $col, int $row, mixed $value): void
    {
        $col = max(1, $col);
        $row = max(1, $row);

        if (is_bool($value)) {
            $value = $value ? 'Yes' : 'No';
        } elseif ($value === null) {
            $value = '';
        }

        $numeric = is_int($value) || is_float($value);
        if (!$numeric && !is_string($value)) {
            $value = (string) $value;
        }

        $this->cells[$row][$col] = ['v' => $value, 'numeric' => $numeric];
        $this->maxRow = max($this->maxRow, $row);
        $this->maxCol = max($this->maxCol, $col);
    }

    public function getCellValue(int $col, int $row): mixed
    {
        return $this->cells[$row][$col]['v'] ?? null;
    }

    /** Turn numeric-looking text into real numbers so Excel can total them. */
    public function coerceNumeric(int $col, int $firstRow, int $lastRow): void
    {
        for ($row = $firstRow; $row <= $lastRow; $row++) {
            if (!isset($this->cells[$row][$col])) {
                continue;
            }
            $cell = $this->cells[$row][$col];
            if ($cell['numeric'] || !is_string($cell['v'])) {
                continue;
            }
            $text = trim($cell['v']);
            if ($text === '' || !is_numeric($text)) {
                continue;
            }
            $this->cells[$row][$col] = [
                'v' => $text + 0,
                'numeric' => true,
            ];
        }
    }

    public function mergeCells(int $firstCol, int $firstRow, int $lastCol, int $lastRow): void
    {
        if ($lastCol <= $firstCol && $lastRow <= $firstRow) {
            return;
        }

        $this->merges[] = AlcrosXlsxWorkbook::columnLetter($firstCol) . $firstRow
            . ':' . AlcrosXlsxWorkbook::columnLetter($lastCol) . $lastRow;
        $this->maxRow = max($this->maxRow, $lastRow);
        $this->maxCol = max($this->maxCol, $lastCol);
    }

    public function setRowHeight(int $row, float $height): void
    {
        $this->rowHeights[$row] = $height;
    }

    public function setColumnWidth(int $col, float $width): void
    {
        $this->colWidths[$col] = $width;
    }

    /** Merge style properties onto every cell in the range. */
    public function applyStyle(int $firstCol, int $firstRow, int $lastCol, int $lastRow, array $props): void
    {
        for ($row = $firstRow; $row <= $lastRow; $row++) {
            for ($col = $firstCol; $col <= $lastCol; $col++) {
                $current = $this->cellStyles[$row][$col] ?? [];
                $this->cellStyles[$row][$col] = array_merge($current, $props);
                $this->maxRow = max($this->maxRow, $row);
                $this->maxCol = max($this->maxCol, $col);
            }
        }
    }

    /** Freeze every row above $row. */
    public function freezeAbove(int $row): void
    {
        $this->freezeRow = max(1, $row);
    }

    public function setAutoFilter(int $firstCol, int $firstRow, int $lastCol, int $lastRow): void
    {
        $this->autoFilter = AlcrosXlsxWorkbook::columnLetter($firstCol) . $firstRow
            . ':' . AlcrosXlsxWorkbook::columnLetter($lastCol) . $lastRow;
    }

    public function buildXml(AlcrosXlsxStyleTable $styles): string
    {
        $lastRef = AlcrosXlsxWorkbook::columnLetter(max(1, $this->maxCol)) . max(1, $this->maxRow);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<dimension ref="A1:' . $lastRef . '"/>'
            . $this->buildSheetViewsXml()
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . $this->buildColsXml()
            . '<sheetData>';

        $rowNumbers = array_unique(array_merge(
            array_keys($this->cells),
            array_keys($this->cellStyles),
            array_keys($this->rowHeights)
        ));
        sort($rowNumbers);

        foreach ($rowNumbers as $row) {
            $xml .= $this->buildRowXml((int) $row, $styles);
        }

        $xml .= '</sheetData>';

        if ($this->autoFilter !== null) {
            $xml .= '<autoFilter ref="' . $this->autoFilter . '"/>';
        }

        if ($this->merges !== []) {
            $merges = array_values(array_unique($this->merges));
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $ref) {
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        return $xml . '</worksheet>';
    }

    private function buildSheetViewsXml(): string
    {
        $xml = '<sheetViews><sheetView workbookViewId="0">';
        if ($this->freezeRow !== null && $this->freezeRow > 1) {
            $split = $this->freezeRow - 1;
            $xml .= '<pane ySplit="' . $split . '" topLeftCell="A' . $this->freezeRow . '"'
                . ' activePane="bottomLeft" state="frozen"/>'
                . '<selection pane="bottomLeft" activeCell="A' . $this->freezeRow . '"'
                . ' sqref="A' . $this->freezeRow . '"/>';
        }

        return $xml . '</sheetView></sheetViews>';
    }

    private function buildColsXml(): string
    {
        if ($this->colWidths === []) {
            return '';
        }

        ksort($this->colWidths);
        $xml = '<cols>';
        foreach ($this->colWidths as $col => $width) {
            $xml .= '<col min="' . $col . '" max="' . $col . '"'
                . ' width="' . round($width, 2) . '" customWidth="1"/>';
        }

        return $xml . '</cols>';
    }

    private function buildRowXml(int $row, AlcrosXlsxStyleTable $styles): string
    {
        $attrs = ' r="' . $row . '"';
        if (isset($this->rowHeights[$row])) {
            $attrs .= ' ht="' . round($this->rowHeights[$row], 2) . '" customHeight="1"';
        }

        $columns = array_unique(array_merge(
            array_keys($this->cells[$row] ?? []),
            array_keys($this->cellStyles[$row] ?? [])
        ));
        sort($columns);

        if ($columns === []) {
            return '<row' . $attrs . '/>';
        }

        $xml = '<row' . $attrs . '>';
        foreach ($columns as $col) {
            $xml .= $this->buildCellXml((int) $col, $row, $styles);
        }

        return $xml . '</row>';
    }

    private function buildCellXml(int $col, int $row, AlcrosXlsxStyleTable $styles): string
    {
        $ref = AlcrosXlsxWorkbook::columnLetter($col) . $row;
        $styleId = $styles->idFor($this->cellStyles[$row][$col] ?? []);
        $attrs = ' r="' . $ref . '"';
        if ($styleId > 0) {
            $attrs .= ' s="' . $styleId . '"';
        }

        $cell = $this->cells[$row][$col] ?? null;
        if ($cell === null) {
            return '<c' . $attrs . '/>';
        }

        if ($cell['numeric']) {
            $number = is_float($cell['v']) && !is_finite($cell['v']) ? 0 : $cell['v'];

            return '<c' . $attrs . '><v>' . AlcrosXlsxWorkbook::numberText($number) . '</v></c>';
        }

        $text = (string) $cell['v'];
        if ($text === '') {
            return '<c' . $attrs . '/>';
        }

        return '<c' . $attrs . ' t="inlineStr"><is><t xml:space="preserve">'
            . AlcrosXlsxWorkbook::xmlText($text) . '</t></is></c>';
    }
}

/** Collects unique cell formats and renders them as styles.xml. */
final class AlcrosXlsxStyleTable
{
    /** @var array<string, int> */
    private array $lookup = [];

    /** @var list<array<string, mixed>> */
    private array $styles = [];

    /** @var list<string> */
    private array $fonts = [];

    /** @var list<string> */
    private array $fills = [];

    /** @var list<string> */
    private array $borders = [];

    /** @var array<string, int> */
    private array $numberFormats = [];

    public function idFor(array $props): int
    {
        $props = $this->normalize($props);
        if ($props === []) {
            return 0;
        }

        $key = md5(serialize($props));
        if (!isset($this->lookup[$key])) {
            $this->styles[] = $props;
            $this->lookup[$key] = count($this->styles); // index 0 is the default format
        }

        return $this->lookup[$key];
    }

    public function buildXml(): string
    {
        // Excel requires fill 0 = none and fill 1 = gray125 before any custom fill.
        $this->fonts = [$this->fontXml([])];
        $this->fills = [
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
        ];
        $this->borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>'];
        $this->numberFormats = [];

        $cellXfs = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'];
        foreach ($this->styles as $props) {
            $cellXfs[] = $this->cellXfXml($props);
        }

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->numberFormats !== []) {
            $xml .= '<numFmts count="' . count($this->numberFormats) . '">';
            foreach ($this->numberFormats as $code => $id) {
                $xml .= '<numFmt numFmtId="' . $id . '" formatCode="'
                    . AlcrosXlsxWorkbook::xmlText((string) $code) . '"/>';
            }
            $xml .= '</numFmts>';
        }

        $xml .= '<fonts count="' . count($this->fonts) . '">' . implode('', $this->fonts) . '</fonts>'
            . '<fills count="' . count($this->fills) . '">' . implode('', $this->fills) . '</fills>'
            . '<borders count="' . count($this->borders) . '">' . implode('', $this->borders) . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($cellXfs) . '">' . implode('', $cellXfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';

        return $xml;
    }

    private function normalize(array $props): array
    {
        $clean = [];

        foreach (['bold', 'italic', 'wrap'] as $flag) {
            if (!empty($props[$flag])) {
                $clean[$flag] = true;
            }
        }

        if (isset($props['size']) && (float) $props['size'] > 0) {
            $clean['size'] = (float) $props['size'];
        }

        foreach (['color', 'fill', 'border'] as $colorKey) {
            $rgb = strtoupper(trim((string) ($props[$colorKey] ?? '')));
            if (preg_match('/^[0-9A-F]{6}$/', $rgb) === 1) {
                $clean[$colorKey] = $rgb;
            }
        }

        $halign = (string) ($props['halign'] ?? '');
        if (in_array($halign, ['left', 'center', 'right'], true)) {
            $clean['halign'] = $halign;
        }

        $valign = (string) ($props['valign'] ?? '');
        if (in_array($valign, ['top', 'center', 'bottom'], true)) {
            $clean['valign'] = $valign;
        }

        $format = (string) ($props['format'] ?? '');
        if ($format !== '' && $format !== 'general') {
            $clean['format'] = $format;
        }

        ksort($clean);

        return $clean;
    }

    private function cellXfXml(array $props): string
    {
        $fontId = $this->registerFont($props);
        $fillId = $this->registerFill($props);
        $borderId = $this->registerBorder($props);
        $numFmtId = $this->registerNumberFormat($props);

        $xf = '<xf numFmtId="' . $numFmtId . '" fontId="' . $fontId . '"'
            . ' fillId="' . $fillId . '" borderId="' . $borderId . '" xfId="0"';

        if ($fontId > 0) {
            $xf .= ' applyFont="1"';
        }
        if ($fillId > 1) {
            $xf .= ' applyFill="1"';
        }
        if ($borderId > 0) {
            $xf .= ' applyBorder="1"';
        }
        if ($numFmtId > 0) {
            $xf .= ' applyNumberFormat="1"';
        }

        $alignment = '';
        if (isset($props['halign'])) {
            $alignment .= ' horizontal="' . $props['halign'] . '"';
        }
        if (isset($props['valign'])) {
            $alignment .= ' vertical="' . $props['valign'] . '"';
        }
        if (!empty($props['wrap'])) {
            $alignment .= ' wrapText="1"';
        }

        if ($alignment === '') {
            return $xf . '/>';
        }

        return $xf . ' applyAlignment="1"><alignment' . $alignment . '/></xf>';
    }

    private function registerFont(array $props): int
    {
        $xml = $this->fontXml($props);
        $existing = array_search($xml, $this->fonts, true);
        if ($existing !== false) {
            return (int) $existing;
        }

        $this->fonts[] = $xml;

        return count($this->fonts) - 1;
    }

    private function fontXml(array $props): string
    {
        $xml = '<font>';
        if (!empty($props['bold'])) {
            $xml .= '<b/>';
        }
        if (!empty($props['italic'])) {
            $xml .= '<i/>';
        }
        $xml .= '<sz val="' . ($props['size'] ?? 11) . '"/>'
            . '<color rgb="FF' . ($props['color'] ?? '000000') . '"/>'
            . '<name val="Calibri"/><family val="2"/>';

        return $xml . '</font>';
    }

    private function registerFill(array $props): int
    {
        if (!isset($props['fill'])) {
            return 0;
        }

        $xml = '<fill><patternFill patternType="solid">'
            . '<fgColor rgb="FF' . $props['fill'] . '"/><bgColor indexed="64"/>'
            . '</patternFill></fill>';

        $existing = array_search($xml, $this->fills, true);
        if ($existing !== false) {
            return (int) $existing;
        }

        $this->fills[] = $xml;

        return count($this->fills) - 1;
    }

    private function registerBorder(array $props): int
    {
        if (!isset($props['border'])) {
            return 0;
        }

        $side = '<%s style="thin"><color rgb="FF' . $props['border'] . '"/></%s>';
        $xml = '<border>'
            . sprintf($side, 'left', 'left')
            . sprintf($side, 'right', 'right')
            . sprintf($side, 'top', 'top')
            . sprintf($side, 'bottom', 'bottom')
            . '<diagonal/></border>';

        $existing = array_search($xml, $this->borders, true);
        if ($existing !== false) {
            return (int) $existing;
        }

        $this->borders[] = $xml;

        return count($this->borders) - 1;
    }

    private function registerNumberFormat(array $props): int
    {
        $code = match ($props['format'] ?? 'general') {
            'text'     => '@',
            'number'   => '0',
            'date'     => 'yyyy-mm-dd',
            'datetime' => 'yyyy-mm-dd hh:mm',
            default    => '',
        };

        if ($code === '') {
            return 0;
        }

        if (!isset($this->numberFormats[$code])) {
            // Custom number format ids must start at 164.
            $this->numberFormats[$code] = 164 + count($this->numberFormats);
        }

        return $this->numberFormats[$code];
    }
}

final class AlcrosXlsxWorkbook
{
    /** @var list<AlcrosXlsxSheet> */
    private array $sheets = [];

    private int $activeSheet = 0;
    private string $title = 'ALCROS Export';
    private string $creator = 'ALCROS';

    public function __construct(string $title = 'ALCROS Export')
    {
        $this->title = $title;
        $this->sheets[] = new AlcrosXlsxSheet('Sheet1');
    }

    public function setProperties(string $title, string $creator = 'ALCROS'): self
    {
        $this->title = $title;
        $this->creator = $creator;

        return $this;
    }

    public function getActiveSheet(): AlcrosXlsxSheet
    {
        return $this->sheets[$this->activeSheet] ?? $this->sheets[0];
    }

    public function createSheet(?int $index = null): AlcrosXlsxSheet
    {
        $sheet = new AlcrosXlsxSheet('Sheet' . (count($this->sheets) + 1));
        if ($index === null || $index >= count($this->sheets)) {
            $this->sheets[] = $sheet;
        } else {
            array_splice($this->sheets, max(0, $index), 0, [$sheet]);
        }

        return $sheet;
    }

    public function getSheetCount(): int
    {
        return count($this->sheets);
    }

    public function setActiveSheetIndex(int $index): self
    {
        if (isset($this->sheets[$index])) {
            $this->activeSheet = $index;
        }

        return $this;
    }

    public static function columnLetter(int $index): string
    {
        $index = max(1, $index);
        $letters = '';
        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    public static function xmlText(string $value): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }
        // Strip control characters that are illegal in XML 1.0.
        $value = preg_replace('/[\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}]/u', '', $value) ?? $value;

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function numberText(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') ?: '0';
    }

    /** Render the workbook as raw .xlsx bytes. */
    public function toBinary(): string
    {
        $this->ensureUniqueSheetNames();

        $styles = new AlcrosXlsxStyleTable();
        $sheetXml = [];
        foreach ($this->sheets as $sheet) {
            $sheetXml[] = $sheet->buildXml($styles);
        }

        $parts = [
            '[Content_Types].xml'      => $this->contentTypesXml(),
            '_rels/.rels'              => $this->rootRelsXml(),
            'docProps/core.xml'        => $this->corePropsXml(),
            'docProps/app.xml'         => $this->appPropsXml(),
            'xl/workbook.xml'          => $this->workbookXml(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelsXml(),
            // styles.xml must be built after every sheet has registered its formats.
            'xl/styles.xml'            => $styles->buildXml(),
        ];

        foreach ($sheetXml as $index => $xml) {
            $parts['xl/worksheets/sheet' . ($index + 1) . '.xml'] = $xml;
        }

        return $this->zipParts($parts);
    }

    private function ensureUniqueSheetNames(): void
    {
        $seen = [];
        foreach ($this->sheets as $sheet) {
            $name = $sheet->getTitle();
            $key = mb_strtolower($name, 'UTF-8');
            if (!isset($seen[$key])) {
                $seen[$key] = 1;
                continue;
            }

            $seen[$key]++;
            $suffix = ' (' . $seen[$key] . ')';
            $sheet->setTitle(mb_substr($name, 0, 31 - mb_strlen($suffix, 'UTF-8'), 'UTF-8') . $suffix);
        }
    }

    /** @param array<string, string> $parts */
    private function zipParts(array $parts): string
    {
        if (class_exists('ZipArchive')) {
            return self::zipPartsWithZipArchive($parts);
        }

        return AlcrosZipPacker::pack($parts);
    }

    /** @param array<string, string> $parts */
    private static function zipPartsWithZipArchive(array $parts): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'alcros_xlsx_');
        if ($tempFile === false) {
            throw new RuntimeException('Could not create a temporary file for the Excel export.');
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($tempFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the Excel archive.');
            }

            foreach ($parts as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            $zip->close();

            $binary = file_get_contents($tempFile);
            if ($binary === false) {
                throw new RuntimeException('Could not read the generated Excel file.');
            }

            return $binary;
        } finally {
            if (is_file($tempFile)) {
                @unlink($tempFile);
            }
        }
    }

    private function contentTypesXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($index + 1) . '.xml"'
                . ' ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function corePropsXml(): string
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $title = self::xmlText($this->title);
        $creator = self::xmlText($this->creator);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties'
            . ' xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/"'
            . ' xmlns:dcterms="http://purl.org/dc/terms/"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $title . '</dc:title>'
            . '<dc:subject>' . $title . '</dc:subject>'
            . '<dc:creator>' . $creator . '</dc:creator>'
            . '<cp:lastModifiedBy>' . $creator . '</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appPropsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"'
            . ' xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>ALCROS</Application>'
            . '</Properties>';
    }

    private function workbookXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView activeTab="' . $this->activeSheet . '"/></bookViews>'
            . '<sheets>';

        foreach ($this->sheets as $index => $sheet) {
            $xml .= '<sheet name="' . self::xmlText($sheet->getTitle()) . '"'
                . ' sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return $xml . '</sheets></workbook>';
    }

    private function workbookRelsXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach (array_keys($this->sheets) as $index) {
            $xml .= '<Relationship Id="rId' . ($index + 1) . '"'
                . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($index + 1) . '.xml"/>';
        }

        return $xml . '<Relationship Id="rId' . (count($this->sheets) + 1) . '"'
            . ' Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }
}

/**
 * Minimal ZIP writer for in-memory XLSX parts (no ZipArchive required).
 *
 * @internal
 */
final class AlcrosZipPacker
{
    /** @param array<string, string> $parts path => raw file contents */
    public static function pack(array $parts): string
    {
        if ($parts === []) {
            throw new RuntimeException('Cannot create an empty Excel archive.');
        }

        $local = '';
        $central = '';
        $offset = 0;
        $count = 0;
        [$dosTime, $dosDate] = self::dosDateTime();

        foreach ($parts as $name => $contents) {
            if (!is_string($name) || $name === '' || str_contains($name, '\\')) {
                throw new RuntimeException('Invalid archive entry name.');
            }
            if (!is_string($contents)) {
                throw new RuntimeException('Invalid archive entry contents.');
            }

            $nameBytes = $name;
            $flags = 0x0800; // UTF-8 file names
            $crc = crc32($contents) & 0xFFFFFFFF;
            $size = strlen($contents);

            $compressed = gzdeflate($contents, 6);
            if ($compressed === false || strlen($compressed) >= $size) {
                $method = 0;
                $payload = $contents;
            } else {
                $method = 8;
                $payload = $compressed;
            }

            $payloadSize = strlen($payload);

            $localHeader = pack(
                'VvvvvvVVVvv',
                0x04034b50,
                20,
                $flags,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $payloadSize,
                $size,
                strlen($nameBytes),
                0
            ) . $nameBytes;

            $local .= $localHeader . $payload;

            $central .= pack(
                'VvvvvvvVVVvvvvvVV',
                0x02014b50,
                20,
                20,
                $flags,
                $method,
                $dosTime,
                $dosDate,
                $crc,
                $payloadSize,
                $size,
                strlen($nameBytes),
                0,
                0,
                0,
                0,
                0,
                $offset
            ) . $nameBytes;

            $offset += strlen($localHeader) + $payloadSize;
            $count++;
        }

        $centralSize = strlen($central);

        return $local . $central . pack(
            'VvvvvVVv',
            0x06054b50,
            0,
            0,
            $count,
            $count,
            $centralSize,
            $offset,
            0
        );
    }

    /** @return array{0: int, 1: int} DOS time and date for the current moment. */
    private static function dosDateTime(): array
    {
        $parts = getdate();
        $time = (($parts['hours'] & 0x1F) << 11)
            | (($parts['minutes'] & 0x3F) << 5)
            | ((intdiv($parts['seconds'], 2)) & 0x1F);
        $year = max(1980, min(2107, $parts['year']));
        $date = ((($year - 1980) & 0x7F) << 9)
            | (($parts['mon'] & 0x0F) << 5)
            | ($parts['mday'] & 0x1F);

        return [$time, $date];
    }
}
