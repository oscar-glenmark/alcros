<?php
/**
 * Shared notification list UI (header dropdown + notifications page).
 *
 * Set $notifPanel before including:
 *   context       — 'dropdown' | 'page'
 *   listId        — DOM id for the list container
 *   listClass     — extra Tailwind classes for the list
 *   showFooter    — show "View all" footer (dropdown only)
 *   toolbarPrefix — id prefix for toolbar buttons ('' or 'page-')
 */
$notifPanel = array_merge([
    'context'       => 'dropdown',
    'listId'        => 'notif-list',
    'listClass'     => '',
    'showFooter'    => false,
    'toolbarPrefix' => '',
], $notifPanel ?? []);

$prefix = $notifPanel['toolbarPrefix'];
$isPage = ($notifPanel['context'] === 'page');
$panelMod = $isPage ? 'alcros-notif-panel--page' : 'alcros-notif-panel--dropdown';
$listClass = trim('alcros-notif-list ' . $notifPanel['listClass']);
$notifIsAdmin = function_exists('isAdmin') && isAdmin();
$notifHelpText = $notifIsAdmin
    ? 'System and maintenance alerts only (email/SMS, cron, configuration). Citizen queue and requests are notified to Staff accounts.'
    : 'Citizen operations: pending requests, queue, and appointments. Tap an item to open the page where you can take action.';
$notifInboxLabel = function_exists('staffPortalInboxLabel') ? staffPortalInboxLabel() : 'Notifications';
$notifPageHeading = $notifIsAdmin ? 'Inbox — system alerts' : 'Inbox — citizen alerts';
$notifEmptyLoading = $notifIsAdmin ? 'Loading alerts…' : 'Loading notifications…';
$notifViewAllLabel = $notifIsAdmin ? 'Open alerts page' : 'Open notifications page';
$notifEmptyIdle = $notifIsAdmin ? 'No alerts right now' : 'No notifications right now';
$notifEmptyHint = $notifIsAdmin
    ? 'When something needs attention (cron, email, or config), it will show up here.'
    : 'New requests, queue activity, and appointments will appear here automatically.';
$notifClearPrompt = $notifIsAdmin ? 'Clear all alerts from this list?' : 'Clear all notifications from this list?';
$notifBrandIcon = $notifIsAdmin ? 'settings' : 'bell';
?>
<div
    class="alcros-notif-panel <?= htmlspecialchars($panelMod) ?>"
    data-notif-context="<?= htmlspecialchars($notifPanel['context']) ?>"
    data-notif-clear-prompt="<?= htmlspecialchars($notifClearPrompt) ?>"
>
    <header class="alcros-notif-panel__head">
        <div class="alcros-notif-panel__brand">
            <div class="alcros-notif-panel__brand-icon" aria-hidden="true">
                <?= function_exists('lucideSvg') ? lucideSvg($notifBrandIcon) : '' ?>
            </div>
            <div class="min-w-0">
                <h2 class="alcros-notif-panel__title">
                    <?= htmlspecialchars($isPage ? $notifPageHeading : $notifInboxLabel) ?>
                </h2>
                <p class="alcros-notif-panel__subtitle">
                    <?= htmlspecialchars($isPage ? $notifHelpText : ($notifIsAdmin ? 'Maintenance & system health' : 'Tap an alert to open the right screen')) ?>
                </p>
                <?php if ($isPage): ?>
                <div class="alcros-notif-panel__meta">
                    <span class="alcros-notif-panel__meta-pill alcros-notif-panel__meta-pill--live">Live sync</span>
                    <span class="alcros-notif-panel__meta-pill">
                        <span class="alcros-notif-panel__meta-count" data-notif-unread-count>0</span> unread
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="alcros-notif-panel__toolbar">
            <button
                type="button"
                id="notif-<?= $prefix ?>mark-read"
                class="alcros-notif-panel__btn alcros-notif-panel__btn--primary alcros-notif-mark-read"
            >
                Mark all read
            </button>
            <button
                type="button"
                id="notif-<?= $prefix ?>clear-all"
                class="alcros-notif-panel__btn alcros-notif-panel__btn--ghost alcros-notif-clear"
            >
                Clear all
            </button>
        </div>
    </header>

    <div
        id="<?= htmlspecialchars($notifPanel['listId']) ?>"
        class="<?= htmlspecialchars($listClass) ?>"
        role="list"
        aria-live="polite"
        aria-relevant="additions"
        data-notif-empty="<?= htmlspecialchars($notifEmptyLoading) ?>"
        data-notif-empty-idle="<?= htmlspecialchars($notifEmptyIdle) ?>"
        data-notif-empty-hint="<?= htmlspecialchars($notifEmptyHint) ?>"
    >
        <p class="text-gray-300 text-xs italic p-8 text-center"><?= htmlspecialchars($notifEmptyLoading) ?></p>
    </div>

    <?php if ($notifPanel['showFooter']): ?>
    <footer class="alcros-notif-panel__foot">
        <a
            id="notif-view-all"
            href="<?= htmlspecialchars(buildAuthUrl('notifications.php')) ?>"
            class="alcros-notif-panel__view-all"
        >
            <?= htmlspecialchars($notifViewAllLabel) ?>
            <?= function_exists('lucideSvg') ? lucideSvg('arrow-right') : '' ?>
        </a>
    </footer>
    <?php endif; ?>
</div>
