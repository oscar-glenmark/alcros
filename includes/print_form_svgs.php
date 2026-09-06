<?php
/**
 * Municipal Forms 102, 97, 103 — HTML/SVG on white bond paper.
 * Front pages embed the straightened reference scan for exact layout match.
 */

function printFormAccent(string $certificateType): string
{
    return match ($certificateType) {
        'birth'    => '#1a5632',
        'marriage' => '#8b1a1a',
        'death'    => '#1a3a6b',
        default    => '#333333',
    };
}

function printFormSvgStyles(string $accent): string
{
    return <<<CSS
      .sheet { fill: #fff; }
      .border { fill: #fff; stroke: {$accent}; stroke-width: 0.4; }
      .box { fill: none; stroke: {$accent}; stroke-width: 0.25; }
      .divider { stroke: {$accent}; stroke-width: 0.2; fill: none; }
      .title { fill: {$accent}; font-family: Arial, Helvetica, sans-serif; font-size: 3.6px; font-weight: 700; }
      .subtitle { fill: #222; font-family: Arial, Helvetica, sans-serif; font-size: 1.9px; }
      .label { fill: {$accent}; font-family: Arial, Helvetica, sans-serif; font-size: 1.7px; font-weight: 700; }
      .sublabel { fill: #444; font-family: Arial, Helvetica, sans-serif; font-size: 1.4px; }
      .hint { fill: #555; font-family: Arial, Helvetica, sans-serif; font-size: 1.25px; }
      .section { fill: {$accent}; font-family: Arial, Helvetica, sans-serif; font-size: 2.1px; font-weight: 700; }
      .num { fill: {$accent}; font-family: Arial, Helvetica, sans-serif; font-size: 1.6px; font-weight: 700; }
      .footer { fill: {$accent}; font-family: Arial, Helvetica, sans-serif; font-size: 1.5px; font-weight: 700; }
      .form-image { image-rendering: auto; }
CSS;
}

function printFormSvgWrap(string $accent, string $body): string
{
    $paper = printDefaultPaperSize();
    $w = $paper['paper_width_mm'];
    $h = $paper['paper_height_mm'];
    $innerH = round($h - 8, 2);
    $styles = printFormSvgStyles($accent);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 {$w} {$h}" width="{$w}mm" height="{$h}mm" class="print-form-svg">
<style>{$styles}</style>
<rect class="sheet" x="0" y="0" width="{$w}" height="{$h}"/>
<rect class="border" x="4" y="4" width="207.9" height="{$innerH}"/>
{$body}
</svg>
SVG;
}

function printFormScanAsset(string $certificateType, string $pageSide): ?string
{
    $relative = 'assets/print/forms/' . $certificateType . '-' . $pageSide . '.png';
    $full = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    if (!is_file($full)) {
        return null;
    }

    return $relative . '?v=' . filemtime($full);
}

function printFormSvgFromScan(string $certificateType, string $pageSide): ?string
{
    $scan = printFormScanAsset($certificateType, $pageSide);
    if ($scan === null) {
        return null;
    }

    $paper = printDefaultPaperSize();
    $w = $paper['paper_width_mm'];
    $h = $paper['paper_height_mm'];
    $accent = printFormAccent($certificateType);
    $styles = printFormSvgStyles($accent);
    $escaped = htmlspecialchars($scan, ENT_QUOTES, 'UTF-8');

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 {$w} {$h}" width="{$w}mm" height="{$h}mm" class="print-form-svg print-form-svg--scan">
<style>{$styles}</style>
<rect class="sheet" x="0" y="0" width="{$w}" height="{$h}"/>
<image class="form-image" xlink:href="{$escaped}" href="{$escaped}" x="0" y="0" width="{$w}" height="{$h}" preserveAspectRatio="none"/>
</svg>
SVG;
}

function printSvgRect(float $x, float $y, float $w, float $h): string
{
    return sprintf('<rect class="box" x="%.2f" y="%.2f" width="%.2f" height="%.2f"/>', $x, $y, $w, $h);
}

function printSvgLine(float $x1, float $y1, float $x2, float $y2): string
{
    return sprintf('<line class="divider" x1="%.2f" y1="%.2f" x2="%.2f" y2="%.2f"/>', $x1, $y1, $x2, $y2);
}

function printSvgVertSectionSpaced(string $text, float $yTop, float $yBottom, float $x = 7.2): string
{
    $chars = preg_split('//u', preg_replace('/\s+/', '', strtoupper($text)), -1, PREG_SPLIT_NO_EMPTY);
    if ($chars === false || $chars === []) {
        return '';
    }

    $height = max($yBottom - $yTop, 8);
    $step = min(3.4, $height / max(count($chars), 1));
    $startY = $yTop + 2.5;
    $html = '';
    foreach ($chars as $i => $ch) {
        $html .= sprintf(
            '<text class="section" x="%.1f" y="%.1f" text-anchor="middle">%s</text>',
            $x,
            $startY + ($i * $step),
            htmlspecialchars($ch, ENT_QUOTES, 'UTF-8')
        );
    }

    return $html;
}

function printSvgHeader(string $formNo, string $title): string
{
    return <<<HTML
<text class="subtitle" x="8" y="9">Municipal Form No. {$formNo} (Revised August 2016)</text>
<text class="subtitle" x="118" y="9">(To be accomplished in quadruplicate using black ink)</text>
<text class="title" x="108" y="14" text-anchor="middle">Republic of the Philippines</text>
<text class="title" x="108" y="18.5" text-anchor="middle">OFFICE OF THE CIVIL REGISTRAR GENERAL</text>
<text class="title" x="108" y="23.5" text-anchor="middle">{$title}</text>
<text class="label" x="8" y="29.5">Province</text>
<line class="divider" x1="28" y1="30.8" x2="118" y2="30.8"/>
<text class="label" x="8" y="35.5">City/Municipality</text>
<line class="divider" x1="38" y1="36.8" x2="118" y2="36.8"/>
<text class="label" x="148" y="29.5">Registry No.</text>
<rect class="box" x="168" y="26.5" width="38" height="8"/>
HTML;
}

function printSvgSectionBand(float $y, float $h): string
{
    return printSvgRect(12, $y, 195.9, $h) . printSvgLine(12, $y, 207.9, $y) . printSvgLine(12, $y + $h, 207.9, $y + $h);
}

function printSvgNameCells(string $num, string $label, float $y, float $x = 14, float $w = 192): string
{
    $x1 = $x + 56;
    $x2 = $x + 124;
    $html = sprintf('<text class="num" x="8" y="%.1f">%s.</text>', $y + 3.5, htmlspecialchars($num, ENT_QUOTES, 'UTF-8'));
    $html .= sprintf('<text class="label" x="%.1f" y="%.1f">%s</text>', $x + 0.8, $y + 0.8, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
    $html .= printSvgLine($x, $y + 5.2, $x + $w, $y + 5.2);
    $html .= printSvgLine($x1, $y + 5.2, $x1, $y + 5.5);
    $html .= printSvgLine($x2, $y + 5.2, $x2, $y + 5.5);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(First)</text>', $x + 28, $y + 5.0);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(Middle)</text>', $x + 90, $y + 5.0);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(Last)</text>', $x + 158, $y + 5.0);

    return $html;
}

function printSvgDateCells(string $num, string $label, float $y, float $x, float $w): string
{
    $html = sprintf('<text class="num" x="8" y="%.1f">%s.</text>', $y + 3.5, htmlspecialchars($num, ENT_QUOTES, 'UTF-8'));
    $html .= sprintf('<text class="label" x="%.1f" y="%.1f">%s</text>', $x + 0.8, $y + 0.8, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));
    $html .= printSvgLine($x, $y + 5.2, $x + $w, $y + 5.2);
    $dx = $x + ($w * 0.25);
    $mx = $x + ($w * 0.55);
    $html .= printSvgLine($dx, $y + 5.2, $dx, $y + 5.5);
    $html .= printSvgLine($mx, $y + 5.2, $mx, $y + 5.5);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(Day)</text>', $x + ($w * 0.12), $y + 5.0);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(Month)</text>', $x + ($w * 0.4), $y + 5.0);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f" text-anchor="middle">(Year)</text>', $x + ($w * 0.78), $y + 5.0);

    return $html;
}

function printSvgCheckbox(float $x, float $y, string $label): string
{
    $html = printSvgRect($x, $y, 2.2, 2.2);
    $html .= sprintf('<text class="hint" x="%.1f" y="%.1f">%s</text>', $x + 3, $y + 1.8, htmlspecialchars($label, ENT_QUOTES, 'UTF-8'));

    return $html;
}

function printSvgCertBlock(string $num, string $title, float $x, float $y, float $w, float $h, array $lines): string
{
    $html = printSvgRect($x, $y, $w, $h);
    $html .= sprintf('<text class="num" x="%.1f" y="%.1f">%s.</text>', $x + 1, $y + 2.2, htmlspecialchars($num, ENT_QUOTES, 'UTF-8'));
    $html .= sprintf('<text class="label" x="%.1f" y="%.1f">%s</text>', $x + 6, $y + 2.2, htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
    $lineY = $y + 5;
    foreach ($lines as $line) {
        $html .= sprintf('<text class="sublabel" x="%.1f" y="%.1f">%s</text>', $x + 1.5, $lineY, htmlspecialchars($line, ENT_QUOTES, 'UTF-8'));
        $lineY += 2.8;
        $html .= printSvgLine($x + 1.5, $lineY, $x + $w - 1.5, $lineY);
        $lineY += 2.2;
    }

    return $html;
}

function printSvgRuledSection(string $title, float $y, int $lines, float $lineGap = 6.0, ?string $subtitle = null): string
{
    $html = sprintf('<text class="label" x="8" y="%.1f">%s</text>', $y, htmlspecialchars($title, ENT_QUOTES, 'UTF-8'));
    if ($subtitle !== null) {
        $html .= sprintf('<text class="sublabel" x="8" y="%.1f">%s</text>', $y + 3.5, htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'));
        $y += 4;
    }
    $startY = $y + 5;
    for ($i = 0; $i < $lines; $i++) {
        $ly = $startY + ($i * $lineGap);
        $html .= printSvgLine(8, $ly, 206, $ly);
    }

    return $html;
}

function printFormSvgBirthFront(): string
{
    $embedded = printFormSvgFromScan('birth', 'front');
    if ($embedded !== null) {
        return $embedded;
    }

    $g = printFormAccent('birth');
    $body = printSvgHeader('102', 'CERTIFICATE OF LIVE BIRTH');
    $body .= printSvgSectionBand(38, 32);
    $body .= printSvgVertSectionSpaced('CHILD', 38, 70);
    $body .= printSvgNameCells('1', 'NAME', 39.5);
    $body .= printSvgRect(14, 46.5, 62, 5.5);
    $body .= printSvgDateCells('2', 'SEX (Male/Female)', 46.5, 14, 62);
    $body .= printSvgDateCells('3', 'DATE OF BIRTH', 46.5, 78, 128);
    $body .= printSvgRect(14, 52.5, 192, 5.5);
    $body .= '<text class="label" x="15" y="53.3">4. PLACE OF BIRTH</text>';
    $body .= '<text class="hint" x="15" y="57.2">(Name of Hospital/Clinic/Institution/House No., St., Barangay) (City/Municipality) (Province)</text>';

    return printFormSvgWrap($g, $body);
}

function printFormSvgBirthBack(): string
{
    $embedded = printFormSvgFromScan('birth', 'back');
    if ($embedded !== null) {
        return $embedded;
    }

    $g = printFormAccent('birth');
    $body = '<text class="title" x="108" y="14" text-anchor="middle">Municipal Form No. 102 — Back</text>';
    $body .= printSvgRuledSection('AFFIDAVIT OF ACKNOWLEDGMENT/ADMISSION OF PATERNITY', 22, 6, 6.0, '(For births before 3 August 1988 / on or after 3 August 1988)');
    $body .= printSvgRuledSection('AFFIDAVIT FOR DELAYED REGISTRATION OF BIRTH', 92, 8, 6.0, '(Hospital/clinic administrator, father, mother, guardian, or person if 18+)');

    return printFormSvgWrap($g, $body);
}

function printFormSvgMarriageFront(): string
{
    $embedded = printFormSvgFromScan('marriage', 'front');
    if ($embedded !== null) {
        return $embedded;
    }

    $r = printFormAccent('marriage');
    $body = printSvgHeader('97', 'CERTIFICATE OF MARRIAGE');

    return printFormSvgWrap($r, $body);
}

function printFormSvgMarriageBack(): string
{
    $embedded = printFormSvgFromScan('marriage', 'back');
    if ($embedded !== null) {
        return $embedded;
    }

    $r = printFormAccent('marriage');
    $body = '<text class="title" x="108" y="14" text-anchor="middle">Municipal Form No. 97 — Back</text>';
    $body .= printSvgRuledSection('20b. WITNESSES (Print Name and Sign)', 22, 3);
    $body .= printSvgRuledSection('AFFIDAVIT FOR DELAYED REGISTRATION OF MARRIAGE', 96, 8);

    return printFormSvgWrap($r, $body);
}

function printFormSvgDeathFront(): string
{
    $embedded = printFormSvgFromScan('death', 'front');
    if ($embedded !== null) {
        return $embedded;
    }

    $b = printFormAccent('death');
    $body = printSvgHeader('103', 'CERTIFICATE OF DEATH');

    return printFormSvgWrap($b, $body);
}

function printFormSvgDeathBack(): string
{
    $embedded = printFormSvgFromScan('death', 'back');
    if ($embedded !== null) {
        return $embedded;
    }

    $b = printFormAccent('death');
    $body = '<text class="title" x="108" y="14" text-anchor="middle">Municipal Form No. 103 — Back</text>';
    $body .= printSvgRuledSection('AFFIDAVIT FOR DELAYED REGISTRATION OF DEATH', 104, 8);

    return printFormSvgWrap($b, $body);
}

function printFormSvgCatalog(): array
{
    return [
        'birth-front'     => 'printFormSvgBirthFront',
        'birth-back'      => 'printFormSvgBirthBack',
        'marriage-front'  => 'printFormSvgMarriageFront',
        'marriage-back'   => 'printFormSvgMarriageBack',
        'death-front'     => 'printFormSvgDeathFront',
        'death-back'      => 'printFormSvgDeathBack',
    ];
}

function printFormReferenceSvgMarkup(string $certificateType, string $pageSide): string
{
    $key = $certificateType . '-' . $pageSide;
    $catalog = printFormSvgCatalog();

    return isset($catalog[$key]) ? $catalog[$key]() : '';
}

function regeneratePrintFormReferenceFiles(?string $targetDir = null): array
{
    $dir = $targetDir ?? dirname(__DIR__) . '/assets/print/forms';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $written = [];
    foreach (printFormSvgCatalog() as $name => $fn) {
        $path = $dir . DIRECTORY_SEPARATOR . $name . '.svg';
        file_put_contents($path, $fn());
        $written[] = $path;
    }

    return $written;
}

function ensurePrintFormReferenceFiles(PDO $pdo): void
{
    $version = '6';
    if (getSetting('print_form_svg_version', '') === $version) {
        return;
    }

    regeneratePrintFormReferenceFiles();
    syncPrintTemplateReferenceImages($pdo);
    setSetting('print_form_svg_version', $version);
}

function syncPrintTemplateReferenceImages(PDO $pdo): void
{
    foreach (['birth', 'marriage', 'death'] as $type) {
        foreach (['front', 'back'] as $side) {
            $path = printFormReferenceImage($type, $side);
            $pdo->prepare(
                'UPDATE print_templates SET reference_image = ?, updated_at = NOW()
                 WHERE certificate_type = ? AND page_side = ?'
            )->execute([$path, $type, $side]);
        }
    }
}
