<?php
/**
 * Maintenance announcement modal for the citizen portal.
 */
if (!function_exists('isMaintenanceMode')) {
    require_once __DIR__ . '/helpers.php';
}
if (!isMaintenanceMode()) {
    return;
}

require_once __DIR__ . '/scripts.php';
$maintenanceSite = $site ?? getSiteSettings();
?>
<?= stylesheetTag('public/maintenance-announcement.css') ?>
<div id="alcros-maintenance-overlay" class="hidden fixed inset-0 z-[10000] flex items-center justify-center p-4 bg-slate-900/75 backdrop-blur-sm opacity-0" role="alertdialog" aria-modal="true" aria-labelledby="alcros-maintenance-title">
    <div id="alcros-maintenance-panel" class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden border border-amber-100 translate-y-4 scale-95 opacity-0">
        <div class="px-6 pt-6 pb-4 border-b border-amber-100 bg-amber-50">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 bg-amber-500 text-white rounded-xl flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div class="min-w-0">
                    <h2 id="alcros-maintenance-title" class="text-lg font-extrabold text-slate-900">System Maintenance</h2>
                    <p class="text-[11px] text-amber-800/80 truncate"><?= htmlspecialchars($maintenanceSite['name']) ?> — <?= htmlspecialchars($maintenanceSite['office']) ?></p>
                </div>
            </div>
        </div>
        <div class="px-6 py-5 overflow-y-auto text-sm text-slate-600 leading-relaxed space-y-3 flex-1">
            <p class="font-semibold text-slate-800">The citizen portal is currently under maintenance.</p>
            <p>Online document requests and appointment booking may be temporarily unavailable while we perform updates. You can still use <strong>Track Request</strong> to check the status of existing applications.</p>
            <div class="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3 text-xs text-slate-600 space-y-1.5">
                <p><span class="font-bold text-slate-500">Office hours:</span> <?= htmlspecialchars($maintenanceSite['hours']) ?></p>
                <p><span class="font-bold text-slate-500">Contact:</span> <?= htmlspecialchars($maintenanceSite['phone']) ?> · <?= htmlspecialchars($maintenanceSite['email']) ?></p>
            </div>
            <p class="text-xs text-slate-500">We apologize for the inconvenience and appreciate your patience.</p>
        </div>
        <div class="px-6 py-4 border-t border-slate-100 bg-white">
            <button type="button" id="alcros-maintenance-ack" class="w-full bg-amber-500 hover:bg-amber-600 text-white rounded-xl py-3 text-sm font-bold transition">
                I Understand
            </button>
        </div>
    </div>
</div>
<?= scriptTag('public/maintenance-announcement.js') ?>
