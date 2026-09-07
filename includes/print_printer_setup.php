<?php

function printPrinterSetupStylesheet(): string
{
    if (!function_exists('stylesheetTag')) {
        require_once __DIR__ . '/scripts.php';
    }

    return stylesheetTag('admin/print-printer-setup.css');
}

/**
 * @param array{
 *   variant?: string,
 *   back_orientation_hint?: string|null,
 *   show_back_hint?: bool,
 *   extra_class?: string,
 *   show_paper_picker?: bool,
 *   default_width_mm?: float|null,
 *   default_height_mm?: float|null
 * } $options
 */
function renderPrintBuiltInPrinterSetup(array $options = []): void
{
    $variant = (string) ($options['variant'] ?? 'panel');
    $spec = printBuiltInPaperSpec();
    $showBackHint = !empty($options['show_back_hint']);
    $backHint = trim((string) ($options['back_orientation_hint'] ?? ''));
    $extraClass = trim((string) ($options['extra_class'] ?? ''));
    $showPaperPicker = array_key_exists('show_paper_picker', $options)
        ? !empty($options['show_paper_picker'])
        : ($variant === 'dialog');
    $defaultWidthMm = (float) ($options['default_width_mm'] ?? $spec['width_mm']);
    $defaultHeightMm = (float) ($options['default_height_mm'] ?? $spec['height_mm']);
    $defaultPreset = printPaperPresetKeyForSize($defaultWidthMm, $defaultHeightMm);

    $wrapClass = 'print-built-in-setup print-built-in-setup--' . $variant;
    if ($extraClass !== '') {
        $wrapClass .= ' ' . $extraClass;
    }
    ?>
    <div class="<?= htmlspecialchars($wrapClass) ?>" data-print-built-in-setup>
        <div class="print-built-in-setup__head">
            <span class="print-built-in-setup__badge">Built-in</span>
            <p class="print-built-in-setup__title"<?= $variant === 'dialog' ? ' id="printSetupTitle"' : '' ?>>Printer setup</p>
        </div>
        <?php if ($showPaperPicker): ?>
        <fieldset class="print-built-in-setup__picker" data-print-paper-picker>
            <legend class="print-built-in-setup__picker-legend">Paper size</legend>
            <label class="print-built-in-setup__picker-label" for="printPaperPreset">Preset</label>
            <select id="printPaperPreset" class="print-built-in-setup__picker-select" data-print-paper-preset>
                <?php foreach (printPaperPresets() as $key => $preset): ?>
                <option value="<?= htmlspecialchars($key) ?>"<?= $key === $defaultPreset ? ' selected' : '' ?>>
                    <?= htmlspecialchars($preset['label']) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <div class="print-built-in-setup__picker-dims" data-print-paper-dims>
                <label class="print-built-in-setup__picker-label" for="printPaperWidthMm">Width (mm)</label>
                <input type="number" id="printPaperWidthMm" class="print-built-in-setup__picker-input"
                       data-print-paper-width min="50" max="500" step="0.1"
                       value="<?= htmlspecialchars(number_format($defaultWidthMm, 1, '.', '')) ?>">
                <span class="print-built-in-setup__picker-times" aria-hidden="true">×</span>
                <label class="print-built-in-setup__picker-label" for="printPaperHeightMm">Height (mm)</label>
                <input type="number" id="printPaperHeightMm" class="print-built-in-setup__picker-input"
                       data-print-paper-height min="50" max="700" step="0.1"
                       value="<?= htmlspecialchars(number_format($defaultHeightMm, 1, '.', '')) ?>">
            </div>
            <p class="print-built-in-setup__picker-hint" data-print-paper-hint>
                In the browser print dialog, choose <strong data-print-printer-hint><?= htmlspecialchars(printPaperPresets()[$defaultPreset]['printer_hint'] ?? 'Legal') ?></strong>
                or match these exact dimensions.
            </p>
            <script type="application/json" data-print-paper-presets><?= htmlspecialchars(json_encode(printPaperPresets(), JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?></script>
        </fieldset>
        <?php else: ?>
        <div class="print-built-in-setup__paper" aria-label="Paper size">
            <span class="print-built-in-setup__paper-primary">
                <strong><?= htmlspecialchars($spec['name']) ?></strong>
                (8.5 × 14 in)
            </span>
            <span class="print-built-in-setup__paper-or">or</span>
            <span class="print-built-in-setup__paper-mm">
                <strong><?= htmlspecialchars(number_format($spec['width_mm'], 1, '.', '')) ?> × <?= htmlspecialchars(number_format($spec['height_mm'], 1, '.', '')) ?> mm</strong>
            </span>
        </div>
        <?php endif; ?>
        <ul class="print-built-in-setup__rules">
            <li>Scale <strong>100%</strong> — do not use “Fit to page”</li>
            <li>Margins <strong>None</strong></li>
            <?php if ($showPaperPicker): ?>
            <li>Match the paper size above in your printer dialog</li>
            <?php else: ?>
            <li>Not A4, Letter, or Postcard</li>
            <?php endif; ?>
            <li>Print dialog should show <strong>1 sheet</strong> per page</li>
        </ul>
        <?php if ($showBackHint && $backHint !== ''): ?>
        <p class="print-built-in-setup__back">
            Double-sided back reinsert:
            <strong><?= htmlspecialchars(str_replace('_', ' ', $backHint)) ?></strong>
        </p>
        <?php endif; ?>
    </div>
    <?php
}
