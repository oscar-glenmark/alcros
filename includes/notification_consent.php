<?php
/**
 * First-visit Gmail notification consent modal (shown once per browser via localStorage).
 * Appears after the Privacy & Safety agreement so citizens can opt in to status emails.
 */
require_once __DIR__ . '/scripts.php';
$notifySite = $site ?? getSiteSettings();
?>
<div id="alcros-notify-overlay" class="hidden fixed inset-0 z-[9998] flex items-center justify-center p-4 bg-slate-900/70 backdrop-blur-sm" role="dialog" aria-modal="true" aria-labelledby="alcros-notify-title">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col overflow-hidden border border-gray-100">
        <div class="px-6 pt-6 pb-4 border-b border-gray-100">
            <div class="flex items-center gap-3 mb-2">
                <div class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl flex items-center justify-center shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </div>
                <div>
                    <h2 id="alcros-notify-title" class="text-lg font-extrabold text-slate-900">Notification preferences</h2>
                    <p class="text-[11px] text-gray-500"><?= htmlspecialchars($notifySite['name']) ?> — <?= htmlspecialchars($notifySite['office']) ?></p>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 overflow-y-auto text-[12px] text-gray-600 leading-relaxed space-y-3 flex-1">
            <p>Choose how you would like to receive updates about your civil registry request or appointment. You can still use ALCROS if you opt out.</p>
            <ul class="list-disc pl-5 space-y-1.5">
                <li>Gmail updates when you submit a request, when staff confirm your visit, and when your status changes.</li>
                <li>Email reminders at 5 hours, 3 hours, and 1 hour before a confirmed visit or appointment, plus an extra heads-up if your visit is within the next hour.</li>
                <li>Optional SMS text updates to your cellphone when a request is accepted, ready for pickup, or 3 hours before a visit.</li>
            </ul>
            <p class="text-[11px] text-gray-500">Please visit the LGU office during business hours once notified. Bring your tracking code and a valid ID.</p>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 space-y-3">
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" id="alcros-notify-checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-semibold text-slate-800">Send Gmail confirmation, status updates, visit reminders (5h, 3h, and 1h before your visit), and a heads-up if your visit is within the next hour.</span>
            </label>
            <label class="flex items-start gap-2.5 cursor-pointer">
                <input type="checkbox" id="alcros-notify-sms-checkbox" class="mt-0.5 rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                <span class="text-xs font-semibold text-slate-800">Send SMS text updates to my cellphone (requires office Semaphore setup).</span>
            </label>
            <div class="flex flex-col sm:flex-row gap-2">
                <button type="button" id="alcros-notify-decline" class="flex-1 border border-gray-200 hover:bg-gray-50 text-slate-600 rounded-xl py-3 text-sm font-bold transition">
                    No thanks
                </button>
                <button type="button" id="alcros-notify-accept" disabled class="flex-1 bg-blue-600 hover:bg-blue-700 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-xl py-3 text-sm font-bold transition">
                    Save preferences
                </button>
            </div>
        </div>
    </div>
</div>
<script src="<?= htmlspecialchars(jsAsset('public/notification-consent.js')) ?>"></script>
