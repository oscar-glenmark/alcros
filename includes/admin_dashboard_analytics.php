<?php
/**
 * Administrator dashboard — read-only office analytics (no staff operational panels).
 *
 * Expects $adminAnalytics from fetchAnalyticsDashboard().
 */
if (!isset($adminAnalytics) || !is_array($adminAnalytics)) {
    return;
}
$a = $adminAnalytics;
$reportsOverviewUrl = buildAuthUrl('report.php');
$recordsUrl = buildAuthUrl('records.php');
?>
<section class="dash-admin-analytics space-y-5" aria-labelledby="dash-admin-analytics-heading">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
        <div>
            <h2 id="dash-admin-analytics-heading" class="text-xs font-bold uppercase tracking-wider text-gray-400">Office analytics</h2>
        </div>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-blue-600 hover:text-blue-700 shrink-0">
            Full reports
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
    </div>

    <?php if ($a['pendingCount'] > 0 || $a['readyCount'] > 0 || $a['queueWaiting'] > 0): ?>
    <div class="dash-admin-pulse bg-white border border-slate-200 rounded-xl px-4 py-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
        <span class="font-semibold text-slate-800">Office pulse</span>
        <?php if ($a['pendingCount'] > 0): ?>
        <span class="text-xs text-slate-600"><strong class="text-amber-700"><?= (int) $a['pendingCount'] ?></strong> requests need review</span>
        <?php endif; ?>
        <?php if ($a['readyCount'] > 0): ?>
        <span class="text-xs text-slate-600"><strong class="text-emerald-700"><?= (int) $a['readyCount'] ?></strong> ready for pickup</span>
        <?php endif; ?>
        <?php if ($a['queueWaiting'] > 0): ?>
        <span class="text-xs text-slate-600"><strong class="text-blue-700"><?= (int) $a['queueWaiting'] ?></strong> waiting in queue today</span>
        <?php endif; ?>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="text-[10px] font-bold uppercase text-blue-600 hover:underline ml-auto">See in reports</a>
    </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-3">
        <a href="<?= htmlspecialchars($recordsUrl) ?>" class="dash-admin-kpi stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-orange-200 block">
            <p class="text-[10px] font-bold uppercase text-gray-400">Civil records on file</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int) $a['recordsTotal']) ?></p>
            <p class="text-[11px] text-gray-500 mt-0.5"><?= (int) $a['birthRecords'] ?> birth · <?= (int) $a['deathRecords'] ?> death · <?= (int) $a['marriageRecords'] ?> marriage</p>
        </a>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="dash-admin-kpi stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-violet-200 block">
            <p class="text-[10px] font-bold uppercase text-gray-400">Certifications printed</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int) $a['certTotal']) ?></p>
            <p class="text-[11px] text-gray-500 mt-0.5"><?= (int) $a['certToday'] ?> today · <?= (int) $a['certWeek'] ?> this week</p>
        </a>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="dash-admin-kpi stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-indigo-200 block">
            <p class="text-[10px] font-bold uppercase text-gray-400">Certificates printed</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int) $a['certificateTotal']) ?></p>
            <p class="text-[11px] text-gray-500 mt-0.5"><?= (int) $a['certificateToday'] ?> today · <?= (int) $a['certificateWeek'] ?> this week</p>
        </a>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="dash-admin-kpi stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-blue-200 block">
            <p class="text-[10px] font-bold uppercase text-gray-400">Citizen intake</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int) $a['totalIntake']) ?></p>
            <p class="text-[11px] text-gray-500 mt-0.5"><?= (int) $a['todayIntake'] ?> today · <?= (int) $a['totalRequests'] ?> online · <?= (int) $a['walkInTotal'] ?> walk-in</p>
        </a>
        <a href="<?= htmlspecialchars($reportsOverviewUrl) ?>" class="dash-admin-kpi stat-card bg-white rounded-xl border border-gray-100 p-4 hover:border-emerald-200 block">
            <p class="text-[10px] font-bold uppercase text-gray-400">Queue today</p>
            <p class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int) $a['queueServed']) ?></p>
            <p class="text-[11px] text-gray-500 mt-0.5"><?= (int) $a['queueWaiting'] ?> waiting · <?= (int) $a['queueServing'] ?> being served · <?= (int) $a['apptToday'] ?> appts today</p>
        </a>
    </div>

    <div class="analytics-charts">
        <div class="analytics-chart-card analytics-chart-card--featured">
            <div class="analytics-chart-head">
                <h2>Monthly intake</h2>
                <p>Online requests and walk-in queue · last 6 months</p>
            </div>
            <?php if ($a['maxMonth'] === 0): ?>
            <div class="analytics-empty">No request or walk-in data yet.</div>
            <?php else: ?>
            <div class="chart-box chart-box--tall"><canvas id="chartMonths"></canvas></div>
            <?php endif; ?>
        </div>

        <div class="analytics-chart-grid">
            <div class="analytics-chart-card">
                <div class="analytics-chart-head">
                    <h2>Registry composition</h2>
                    <p>Birth, death, and marriage records on file</p>
                </div>
                <?php if ($a['recordsTotal'] === 0): ?>
                <div class="analytics-empty">No civil records yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--compact"><canvas id="chartRecordsType"></canvas></div>
                <?php endif; ?>
            </div>

            <div class="analytics-chart-card">
                <div class="analytics-chart-head">
                    <h2>New registry entries</h2>
                    <p>Civil records added · last 6 months</p>
                </div>
                <?php if ($a['maxRecordMonth'] === 0): ?>
                <div class="analytics-empty">No new registry entries in this period.</div>
                <?php else: ?>
                <div class="chart-box chart-box--compact"><canvas id="chartRecordsMonths"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
