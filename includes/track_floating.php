<?php
/** Floating track panel — include on public citizen pages. */
$trackSite = $trackSite ?? getSiteSettings();
$initialTrackCode = strtoupper(trim($_GET['track'] ?? $_GET['code'] ?? ''));
?>
<div id="track-floating-root" class="hidden" aria-hidden="true"
     data-initial-code="<?= htmlspecialchars($initialTrackCode, ENT_QUOTES, 'UTF-8') ?>"
     data-office-hours="<?= htmlspecialchars($trackSite['hours'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
     data-office-phone="<?= htmlspecialchars($trackSite['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
    <div id="track-floating-backdrop" class="fixed inset-0 bg-slate-900/45 backdrop-blur-sm z-[60]"></div>
    <div id="track-floating-panel" class="fixed z-[70] left-1/2 top-20 -translate-x-1/2 w-[calc(100%-2rem)] max-w-lg" role="dialog" aria-modal="true" aria-labelledby="track-floating-title">
        <div class="track-floating-card">
            <div class="track-floating-card__header">
                <div class="track-floating-card__heading">
                    <div class="track-floating-card__icon" aria-hidden="true">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="track-floating-card__eyebrow">Live status</p>
                        <h2 id="track-floating-title" class="track-floating-card__title">Track Your Request</h2>
                    </div>
                </div>
                <button type="button" id="track-floating-close" class="track-floating-card__close" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <div class="track-floating-card__body">
                <p class="track-floating-card__hint">Enter your document request or appointment code to see the latest status from the LCRO.</p>
                <form id="track-floating-form" class="track-floating-form">
                    <div class="track-floating-form__field">
                        <div class="track-floating-form__icon" aria-hidden="true">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                        </div>
                        <input type="text" id="track-floating-input" autocomplete="off" spellcheck="false"
                            placeholder="e.g. ALR-XXXXXXXX or APT-XXXXXX"
                            class="track-floating-form__input">
                    </div>
                    <button type="submit" id="track-floating-submit" class="track-floating-form__submit">
                        Track
                    </button>
                </form>

                <div id="track-floating-loading" class="hidden py-2" aria-hidden="true"></div>

                <div id="track-floating-error" class="hidden rounded-xl p-4 bg-red-50 border border-red-100 text-red-600 text-sm text-center"></div>

                <div id="track-floating-result" class="hidden"></div>
            </div>
        </div>
    </div>
</div>
<?php
require_once __DIR__ . '/scripts.php';
echo actionCoreStyles();
echo publicStylesheet('track-floating');
?>
