<?php
/**
 * Citizen email/SMS requirement content for standalone special-service appointments.
 */

require_once __DIR__ . '/appointment_service_requirements.php';

function appointmentServiceRequirementsSlugFromServiceType(string $serviceType): string
{
    $serviceType = trim($serviceType);
    if ($serviceType === '') {
        return '';
    }

    if ($serviceType === 'certified-true-copy') {
        return 'delayed-registration-birth';
    }

    $normalizedPsa = normalizePsaAppointmentServiceType($serviceType);
    if (isAllowedPsaAppointmentServiceType($normalizedPsa)) {
        return 'request-psa-documents';
    }

    foreach (getAppointmentServices() as $svc) {
        if ($serviceType === $svc['slug'] || strcasecmp($serviceType, $svc['label']) === 0) {
            return $svc['slug'];
        }
    }

    if (strcasecmp($serviceType, 'Certified True Copy') === 0) {
        return 'delayed-registration-birth';
    }

    return '';
}

function appointmentServiceRequirementsPublicUrl(string $slug): string
{
    $slug = trim($slug);
    if ($slug === '') {
        return '';
    }

    return rtrim(appBaseUrl(), '/') . '/services.php?requirements=' . rawurlencode($slug);
}

function isStandaloneSpecialServiceAppointmentRow(array $row): bool
{
    if (isDocumentRequestAppointment($row)) {
        return false;
    }

    return appointmentServiceRequirementsSlugFromServiceType((string) ($row['service_type'] ?? '')) !== '';
}

/** @return array<string, array{title: string, subtitle: string, scheduleUrl: string}> */
function appointmentServiceRequirementsModalMeta(): array
{
    $meta = [];
    foreach (getAppointmentServices() as $svc) {
        $req = getAppointmentServiceRequirements($svc['slug'], $svc['label']);
        $meta[$svc['slug']] = [
            'title'       => $req['title'],
            'subtitle'    => $req['subtitle'],
            'scheduleUrl' => 'book_appointment.php?service=' . rawurlencode($svc['slug']),
        ];
    }

    return $meta;
}

function formatAppointmentRequirementsPlainText(string $slug, ?string $serviceLabel = null): string
{
    $req = getAppointmentServiceRequirements($slug, $serviceLabel);
    $lines = ['Requirements to prepare:'];
    if ($req['subtitle'] !== '') {
        $lines[] = $req['subtitle'];
    }
    if ($req['lead'] !== '') {
        $lines[] = $req['lead'];
    }

    $n = 1;
    foreach ($req['items'] as $item) {
        $lines[] = $n . '. ' . $item['text'];
        if (!empty($item['sub'])) {
            foreach ($item['sub'] as $sub) {
                $lines[] = '   - ' . $sub;
            }
        }
        $n++;
    }
    foreach (appointmentServiceRequirementsNonFeeNotes($req) as $note) {
        $lines[] = $note;
    }

    $url = appointmentServiceRequirementsPublicUrl($slug);
    if ($url !== '') {
        $lines[] = 'View online: ' . $url;
    }

    return implode("\n", $lines);
}

function formatAppointmentRequirementsEmailHtml(string $slug, ?string $serviceLabel = null): string
{
    $req = getAppointmentServiceRequirements($slug, $serviceLabel);
    $html = '<div style="margin:20px 0 0;padding:16px 18px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;">'
        . '<p style="margin:0 0 10px;font-size:11px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#2563eb;">Documents to prepare</p>';

    if ($req['subtitle'] !== '') {
        $html .= '<p style="margin:0 0 8px;font-size:12px;font-weight:bold;color:#0f172a;">'
            . citizenEmailText($req['subtitle']) . '</p>';
    }
    if ($req['lead'] !== '') {
        $html .= '<p style="margin:0 0 12px;font-size:13px;line-height:1.55;color:#475569;">'
            . citizenEmailText($req['lead']) . '</p>';
    }

    $html .= '<ol style="margin:0;padding:0 0 0 20px;color:#334155;font-size:13px;line-height:1.55;">';
    foreach ($req['items'] as $item) {
        $html .= '<li style="margin-bottom:8px;">' . citizenEmailText($item['text']);
        if (!empty($item['sub'])) {
            $html .= '<ul style="margin:6px 0 0;padding-left:18px;color:#64748b;font-size:12px;">';
            foreach ($item['sub'] as $sub) {
                $html .= '<li style="margin-bottom:4px;">' . citizenEmailText($sub) . '</li>';
            }
            $html .= '</ul>';
        }
        $html .= '</li>';
    }
    $html .= '</ol>';

    $nonFeeNotes = appointmentServiceRequirementsNonFeeNotes($req);
    if ($nonFeeNotes !== []) {
        $html .= '<div style="margin-top:12px;padding:10px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;">';
        foreach ($nonFeeNotes as $note) {
            $html .= '<p style="margin:0;font-size:11px;font-weight:bold;color:#92400e;">' . citizenEmailText($note) . '</p>';
        }
        $html .= '</div>';
    }

    $url = appointmentServiceRequirementsPublicUrl($slug);
    if ($url !== '') {
        $html .= '<p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#475569;">'
            . '<a href="' . citizenEmailText($url) . '" style="color:#2563eb;font-weight:bold;">View requirements on the ALCROS website</a>'
            . '</p>';
    }

    $html .= '</div>';

    return $html;
}

function appendSpecialServiceRequirementsToCitizenMail(array $mail, array $row): array
{
    if (!isStandaloneSpecialServiceAppointmentRow($row)) {
        return $mail;
    }

    $slug = appointmentServiceRequirementsSlugFromServiceType((string) ($row['service_type'] ?? ''));
    if ($slug === '') {
        return $mail;
    }

    $label = appointmentServiceLabel((string) ($row['service_type'] ?? ''));
    $feesSummary = appointmentServiceRequirementsFeesSummary($slug, $label);
    if ($feesSummary !== '') {
        $mail['fees_summary'] = $feesSummary;
    }
    $mail['requirements_plain'] = formatAppointmentRequirementsPlainText($slug, $label);
    $mail['requirements_html'] = formatAppointmentRequirementsEmailHtml($slug, $label);

    return $mail;
}

function smsTailForStandaloneAppointment(array $row): string
{
    if (!isStandaloneSpecialServiceAppointmentRow($row)) {
        return 'Bring valid ID.';
    }

    // IPROG rejects many URLs (especially http:// localhost) as phishing; requirements stay in email.
    return 'Bring valid ID and required documents (see confirmation email).';
}
