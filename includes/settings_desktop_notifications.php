<?php
/**
 * Settings → Notifications (Staff only): browser desktop pop-ups.
 */
?>
<div id="staff-desktop-notify-settings" class="max-w-2xl">
    <h2 class="text-lg font-bold text-slate-900 mb-1 flex items-center gap-2">
        <i data-lucide="bell-ring" class="w-5 h-5 text-violet-600"></i> Desktop notifications
    </h2>
    <div class="rounded-xl border border-slate-100 bg-slate-50 p-4 sm:p-5 space-y-4 mt-4">
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" id="staffDesktopNotifyEnabled" class="mt-1 rounded border-slate-300 text-violet-600 focus:ring-violet-500">
            <span class="text-sm text-slate-700"><span class="font-semibold text-slate-900">Enable desktop pop-ups</span> on this computer</span>
        </label>
        <div class="flex flex-wrap gap-2">
            <button type="button" id="staffDesktopNotifyAllowBtn" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-xs font-bold bg-violet-600 text-white hover:bg-violet-700">
                Allow notifications
            </button>
        </div>
        <p id="staffDesktopNotifyStatus" class="text-xs hidden" role="status"></p>
        <p class="text-[10px] text-slate-400 leading-relaxed">Use Chrome or Edge. Allow notifications when prompted. Then submit a test request or book an appointment from another window to verify live alerts.</p>
    </div>
</div>
