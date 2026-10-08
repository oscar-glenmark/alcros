<?php
/**
 * Full-size staff ID viewer (native dialog). Include once before </body> on staff pages with ID previews.
 */
require_once __DIR__ . '/scripts.php';

function staffIdLightboxHeadStyles(): string
{
    return stylesheetTag('admin/staff-id-dialog.css');
}

function staffIdLightboxChevronSvg(bool $next): string
{
    $path = $next
        ? 'M 18 10 L 46 32 L 18 54'
        : 'M 46 10 L 18 32 L 46 54';

    return '<svg class="alcros-staff-id-dialog__chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="80" height="80" aria-hidden="true" focusable="false">'
        . '<path d="' . $path . '" fill="none" stroke="#ffffff" stroke-width="6" stroke-linecap="round" stroke-linejoin="round"/>'
        . '</svg>';
}

function staffIdLightboxMarkup(): string
{
    return '<dialog id="alcrosStaffIdDialog" class="alcros-staff-id-dialog" aria-labelledby="alcrosStaffIdDialogTitle">'
        . '<div class="alcros-staff-id-dialog__panel">'
        . '<div class="alcros-staff-id-dialog__header">'
        . '<p id="alcrosStaffIdDialogTitle" class="alcros-staff-id-dialog__title">ID preview</p>'
        . '<button type="button" class="alcros-staff-id-dialog__close" data-staff-id-dialog-close aria-label="Close">&times;</button>'
        . '</div>'
        . '<div class="alcros-staff-id-dialog__body">'
        . '<button type="button" class="alcros-staff-id-dialog__side-nav alcros-staff-id-dialog__side-nav--prev hidden" data-staff-id-nav="prev" aria-label="Previous photo">'
        . staffIdLightboxChevronSvg(false)
        . '</button>'
        . '<div class="alcros-staff-id-dialog__media">'
        . '<img id="alcrosStaffIdDialogImg" class="alcros-staff-id-dialog__img hidden" alt="">'
        . '<iframe id="alcrosStaffIdDialogFrame" class="alcros-staff-id-dialog__frame hidden" title="ID document"></iframe>'
        . '</div>'
        . '<button type="button" class="alcros-staff-id-dialog__side-nav alcros-staff-id-dialog__side-nav--next hidden" data-staff-id-nav="next" aria-label="Next photo">'
        . staffIdLightboxChevronSvg(true)
        . '</button>'
        . '</div>'
        . '</div>'
        . '</dialog>';
}
