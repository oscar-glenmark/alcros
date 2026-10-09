<?php
/** @var array<int, array<string, mixed>> $records */
/** @var array{type: string, q: string, sort: string, dir: string, page: int} $filters */
/** @var int $totalRecords */
/** @var int $totalPages */
/** @var int $page */
/** @var string $sort */
/** @var string $dir */
/** @var array<string, string> $typeBadgeClass */
?>
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden records-list-panel" id="recordsListPanel">
    <?php if ($records === []): ?>
    <div class="p-16 text-center">
        <div class="bg-gray-50 p-4 rounded-xl w-fit mx-auto mb-4 text-gray-200"><?= lucideSvg('book-open', 'w-10 h-10') ?></div>
        <p class="text-sm font-bold text-slate-800 mb-1">No records found</p>
        <p class="text-gray-400 text-xs">Try adjusting your filters or add a new entry.</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full min-w-[720px]">
        <thead class="bg-gray-50/50 border-b border-gray-100">
            <tr>
                <th class="p-4 text-left table-head"><a href="<?= htmlspecialchars(recordsListSortUrl($filters, 'name')) ?>" class="hover:text-blue-600 records-list-nav">Record Name <?= $sort === 'name' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                <th class="p-4 text-left table-head"><a href="<?= htmlspecialchars(recordsListSortUrl($filters, 'type')) ?>" class="hover:text-blue-600 records-list-nav">Type <?= $sort === 'type' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                <th class="p-4 text-left table-head"><a href="<?= htmlspecialchars(recordsListSortUrl($filters, 'date')) ?>" class="hover:text-blue-600 records-list-nav">Key Date <?= $sort === 'date' ? ($dir === 'asc' ? '↑' : '↓') : '' ?></a></th>
                <th class="p-4 text-left table-head">Details</th>
                <th class="p-4 text-right table-head">Action</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100" id="recordsTableBody">
            <?php foreach ($records as $r):
                $badge = $typeBadgeClass[$r['record_type']] ?? 'bg-gray-100 text-gray-600';
                $keyDate = $r['record_type'] === 'birth' ? $r['birth_date'] : $r['event_date'];
                if (!$keyDate) {
                    $keyDate = $r['birth_date'] ?: $r['event_date'];
                }
                $parents = array_filter([$r['father_name'] ? 'Father: ' . $r['father_name'] : '', $r['mother_name'] ? 'Mother: ' . $r['mother_name'] : '']);
            ?>
            <tr class="hover:bg-gray-50/50 transition-colors records-table-row">
                <td class="p-4">
                    <button type="button" class="view-record-btn text-left w-full" data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="flex items-center space-x-3">
                            <div class="w-8 h-8 bg-blue-100 rounded flex items-center justify-center text-blue-600 font-bold text-xs"><?= htmlspecialchars(recordsListRecordInitial(civilRecordDisplayName($r))) ?></div>
                            <div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-sm font-bold text-slate-800 hover:text-blue-600"><?= htmlspecialchars(civilRecordDisplayName($r)) ?></span>
                                    <?php $displayRegistry = civilRecordRegistryNumber($r); ?>
                                    <?php if ($displayRegistry): ?>
                                    <span class="text-[9px] bg-gray-100 px-1.5 py-0.5 rounded text-gray-500 font-bold">#<?= htmlspecialchars($displayRegistry) ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($r['book_number']) || !empty($r['page_number'])): ?>
                                    <span class="text-[9px] bg-amber-50 px-1.5 py-0.5 rounded text-amber-700 font-bold">Bk <?= htmlspecialchars((string) ($r['book_number'] ?? '—')) ?> · Pg <?= htmlspecialchars((string) ($r['page_number'] ?? '—')) ?></span>
                                    <?php endif; ?>
                                </div>
                                <p class="text-[10px] text-gray-400 font-medium">ID: <?= (int) $r['id'] ?> · Added <?= formatRecordDate(substr($r['created_at'], 0, 10)) ?></p>
                            </div>
                        </div>
                    </button>
                </td>
                <td class="p-4"><span class="text-[9px] font-black <?= $badge ?> px-2 py-0.5 rounded uppercase"><?= htmlspecialchars($r['record_type']) ?></span></td>
                <td class="p-4 text-[10px] text-gray-500 font-medium"><?= formatRecordDate($keyDate) ?></td>
                <td class="p-4 text-[10px] text-gray-400 font-medium max-w-[180px] truncate" title="<?= htmlspecialchars(implode(' · ', $parents) ?: ($r['place'] ?? '')) ?>">
                    <?= htmlspecialchars(implode(' · ', $parents) ?: ($r['place'] ?? '—')) ?>
                </td>
                <td class="p-4 text-right">
                    <div class="manage-row-actions" onclick="event.stopPropagation()">
                        <div class="manage-print-menu">
                            <button type="button"
                                    class="manage-row-action manage-row-action--labeled manage-row-action--print manage-print-trigger"
                                    title="Print options"
                                    aria-label="Print options for <?= htmlspecialchars(civilRecordDisplayName($r)) ?>"
                                    aria-haspopup="true"
                                    aria-expanded="false">
                                <?= lucideSvg('printer', 'w-3.5 h-3.5') ?>
                                <span class="manage-row-action__label">PRINT</span>
                                <?= lucideSvg('chevron-down', 'w-3 h-3 manage-print-trigger__chevron') ?>
                            </button>
                            <div class="manage-print-dropdown hidden" role="menu">
                                <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['record_id' => (int) $r['id']])) ?>"
                                   role="menuitem">Local Certificate</a>
                                <a href="<?= htmlspecialchars(buildAuthUrl('print_certificate.php', ['record_id' => (int) $r['id'], 'kind' => 'certification'])) ?>"
                                   role="menuitem">Certification</a>
                            </div>
                        </div>
                        <button type="button" class="view-record-btn manage-row-action" title="View" aria-label="View record"
                                data-record="<?= htmlspecialchars(json_encode($r, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>">
                            <?= lucideSvg('eye', 'w-4 h-4') ?>
                        </button>
                        <a href="<?= buildRecordsUrlFromFilters($filters, ['edit' => $r['id']]) ?>" class="manage-row-action" title="Edit" aria-label="Edit record"><?= lucideSvg('edit-3', 'w-4 h-4') ?></a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <div class="p-4 border-t border-gray-100 flex items-center justify-between">
        <p class="text-[10px] font-bold text-gray-400 uppercase">Page <?= $page ?> of <?= $totalPages ?> · Total: <?= $totalRecords ?></p>
        <div class="flex space-x-2">
            <?php if ($page > 1): ?>
            <a href="<?= htmlspecialchars(buildRecordsUrlFromFilters($filters, ['page' => $page - 1])) ?>" class="p-1 border border-gray-200 rounded text-gray-400 hover:bg-gray-50 records-list-nav"><?= lucideSvg('chevron-left', 'w-4 h-4') ?></a>
            <?php else: ?>
            <span class="p-1 border border-gray-100 rounded text-gray-200"><?= lucideSvg('chevron-left', 'w-4 h-4') ?></span>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="<?= htmlspecialchars(buildRecordsUrlFromFilters($filters, ['page' => $page + 1])) ?>" class="p-1 border border-gray-200 rounded text-gray-400 hover:bg-gray-50 records-list-nav"><?= lucideSvg('chevron-right', 'w-4 h-4') ?></a>
            <?php else: ?>
            <span class="p-1 border border-gray-100 rounded text-gray-200"><?= lucideSvg('chevron-right', 'w-4 h-4') ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>
