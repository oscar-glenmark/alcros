<?php
/** Floating track panel — include on public citizen pages. */
$trackSite = $trackSite ?? getSiteSettings();
$initialTrackCode = strtoupper(trim($_GET['track'] ?? $_GET['code'] ?? ''));
?>
<div id="track-floating-root" class="hidden" aria-hidden="true"
     data-initial-code="<?= htmlspecialchars($initialTrackCode, ENT_QUOTES, 'UTF-8') ?>"
     data-office-hours="<?= htmlspecialchars($trackSite['hours'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
     data-office-phone="<?= htmlspecialchars($trackSite['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <div id="track-floating-backdrop" class="fixed inset-0 bg-slate-900/40 backdrop-blur-[2px] z-[60]"></div>
    <div id="track-floating-panel" class="fixed z-[70] left-1/2 top-20 -translate-x-1/2 w-[calc(100%-2rem)] max-w-lg" role="dialog" aria-modal="true" aria-labelledby="track-floating-title">
        <div class="bg-white rounded-2xl shadow-2xl border border-slate-200/80 overflow-hidden">
            <div class="px-4 sm:px-5 py-4 border-b border-slate-100 flex items-center justify-between gap-3 bg-gradient-to-r from-blue-50 to-sky-50">
                <div class="min-w-0">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-blue-600 mb-0.5">Live status</p>
                    <h2 id="track-floating-title" class="text-base font-black text-slate-900 truncate">Track Your Request</h2>
                </div>
                <button type="button" id="track-floating-close" class="shrink-0 p-2 rounded-xl text-slate-400 hover:text-slate-600 hover:bg-white/80 transition" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="p-4 sm:p-5">
                <form id="track-floating-form" class="flex gap-2 mb-4">
                    <div class="relative flex-1 min-w-0">
                        <div class="absolute inset-y-0 left-3 flex items-center pointer-events-none text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        </div>
                        <input type="text" id="track-floating-input" autocomplete="off" spellcheck="false"
                            placeholder="e.g. ALR-XXXXXXXX or APT-XXXXXX"
                            class="w-full bg-slate-50 border border-slate-200 rounded-xl py-3 pl-10 pr-3 text-sm font-semibold tracking-wide text-slate-700 uppercase placeholder:normal-case placeholder:font-medium placeholder:tracking-normal focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500">
                    </div>
                    <button type="submit" id="track-floating-submit" class="shrink-0 bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-xl text-sm font-bold transition shadow-sm">
                        Track
                    </button>
                </form>

                <div id="track-floating-loading" class="hidden text-center py-8 text-sm text-slate-400">
                    <svg class="animate-spin h-6 w-6 mx-auto mb-2 text-blue-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    Looking up your code…
                </div>

                <div id="track-floating-error" class="hidden rounded-xl p-4 bg-red-50 border border-red-100 text-red-600 text-sm text-center"></div>

                <div id="track-floating-result" class="hidden"></div>
            </div>
        </div>
    </div>
</div>
