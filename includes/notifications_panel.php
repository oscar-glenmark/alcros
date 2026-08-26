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
    'listClass'     => 'max-h-96 overflow-y-auto',
    'showFooter'    => false,
    'toolbarPrefix' => '',
], $notifPanel ?? []);

$prefix = $notifPanel['toolbarPrefix'];
$isPage = ($notifPanel['context'] === 'page');
$headerBg = $isPage ? 'bg-slate-50' : 'bg-white';
$listClass = trim('alcros-notif-list ' . $notifPanel['listClass']);
?>
<div class="alcros-notif-panel" data-notif-context="<?= htmlspecialchars($notifPanel['context']) ?>">
    <div class="px-4 py-3 border-b border-gray-50 flex justify-between items-center <?= $headerBg ?> gap-2">
        <?php if ($isPage): ?>
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-slate-800">All alerts</p>
            <p class="text-[11px] text-slate-500 mt-0.5">Pending requests, queue, and appointments</p>
        </div>
        <?php else: ?>
        <p class="text-xs font-bold uppercase tracking-wider text-slate-800">Notifications</p>
        <?php endif; ?>

        <div class="flex items-center gap-3 shrink-0">
            <button
                type="button"
                id="notif-<?= $prefix ?>clear-all"
                class="alcros-notif-clear text-[10px] font-bold text-slate-400 hover:text-red-500 uppercase tracking-wide"
            >
                Clear all
            </button>
            <button
                type="button"
                id="notif-<?= $prefix ?>mark-read"
                class="alcros-notif-mark-read text-[10px] font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wide"
            >
                Mark all read
            </button>
        </div>
    </div>

    <div
        id="<?= htmlspecialchars($notifPanel['listId']) ?>"
        class="<?= htmlspecialchars($listClass) ?> bg-white"
        data-notif-empty="Loading notifications..."
    >
        <p class="text-gray-300 text-xs italic p-8 text-center">Loading notifications...</p>
    </div>

    <?php if ($notifPanel['showFooter']): ?>
    <div class="px-4 py-2.5 border-t border-gray-50 bg-gray-50">
        <a
            id="notif-view-all"
            href="<?= htmlspecialchars(buildAuthUrl('notifications.php')) ?>"
            class="text-[10px] font-bold text-blue-600 hover:text-blue-700 uppercase tracking-wide"
        >
            View all notifications
        </a>
    </div>
    <?php endif; ?>
</div>
