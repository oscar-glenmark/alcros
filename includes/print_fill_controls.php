<?php

require_once __DIR__ . '/cascading_location.php';

/** Render a print fill-in control (plain text or cascading location for birth place / residence). */
function renderPrintFillFieldInput(array $fillField, array $options = []): void
{
    $fieldName = (string) ($fillField['field_name'] ?? '');
    $value = (string) ($fillField['value'] ?? '');
    $inputClass = trim((string) ($options['input_class'] ?? ''));
    $inputName = trim((string) ($options['name'] ?? ''));
    $lcroClass = trim((string) ($options['lcro_class'] ?? 'print-cert-fill-field--lcro'));
    $isLocation = cascadingLocationUsesField($fieldName);
    $isLcro = printIsLcroFooterField($fieldName);

    $classes = $inputClass;
    if ($isLocation) {
        $classes = cascadingLocationInputClass($classes);
    }
    if ($isLcro && $lcroClass !== '') {
        $classes = trim($classes . ' ' . $lcroClass);
    }
    ?>
    <input type="text"
           data-field-name="<?= htmlspecialchars($fieldName) ?>"
           value="<?= htmlspecialchars($value) ?>"
           class="<?= htmlspecialchars($classes) ?>"
           autocomplete="off"
           spellcheck="false"<?= $inputName !== '' ? ' name="' . htmlspecialchars($inputName) . '"' : '' ?><?= $isLocation ? ' placeholder="Philippines, Province, City/Municipality, Barangay"' : '' ?>>
    <?php
}
