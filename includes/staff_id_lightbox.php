<?php
/**
 * Full-size staff ID viewer (native dialog). Include once before </body> on staff pages with ID previews.
 */
function staffIdLightboxMarkup(): string
{
    return '<dialog id="alcrosStaffIdDialog" class="alcros-staff-id-dialog" aria-labelledby="alcrosStaffIdDialogTitle">'
        . '<div class="alcros-staff-id-dialog__panel">'
        . '<div class="alcros-staff-id-dialog__header">'
        . '<p id="alcrosStaffIdDialogTitle" class="alcros-staff-id-dialog__title">ID preview</p>'
        . '<button type="button" class="alcros-staff-id-dialog__close" data-staff-id-dialog-close aria-label="Close">&times;</button>'
        . '</div>'
        . '<div class="alcros-staff-id-dialog__body">'
        . '<img id="alcrosStaffIdDialogImg" class="alcros-staff-id-dialog__img hidden" alt="">'
        . '<iframe id="alcrosStaffIdDialogFrame" class="alcros-staff-id-dialog__frame hidden" title="ID document"></iframe>'
        . '</div>'
        . '</div>'
        . '</dialog>';
}
