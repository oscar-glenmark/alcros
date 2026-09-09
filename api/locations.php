<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/cascading_location.php';

try {
    requireStaffLogin();
    requirePageAccess('records.php');

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        apiError('Method not allowed.', 405);
    }

    $level = strtolower(trim((string) ($_GET['level'] ?? '')));
    switch ($level) {
        case 'countries':
            apiJsonResponse(['items' => cascadingLocationCountries()]);
            break;
        case 'provinces':
            $country = strtoupper(trim((string) ($_GET['country'] ?? 'PH')));
            if ($country !== 'PH') {
                apiJsonResponse(['items' => []]);
            }
            apiJsonResponse(['items' => cascadingLocationProvinces()]);
            break;
        case 'municipalities':
            $provinceId = (int) ($_GET['province_id'] ?? 0);
            if ($provinceId <= 0) {
                apiError('province_id is required.', 422);
            }
            apiJsonResponse(['items' => cascadingLocationMunicipalities($provinceId)]);
            break;
        case 'barangays':
            $municipalityId = (int) ($_GET['municipality_id'] ?? 0);
            if ($municipalityId <= 0) {
                apiError('municipality_id is required.', 422);
            }
            apiJsonResponse(['items' => cascadingLocationBarangays($municipalityId)]);
            break;
        default:
            apiError('Unknown level.', 422);
    }
} catch (Throwable $e) {
    apiError('Location lookup failed. Refresh the page and try again.', 500);
}
