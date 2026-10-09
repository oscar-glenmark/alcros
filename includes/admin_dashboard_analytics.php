<?php
/**
 * Administrator office analytics (read-only charts).
 *
 * Expects $adminAnalytics from fetchAnalyticsDashboard().
 * Optional: $activities (array) for system activity on dashboard.
 * Optional: $adminAnalyticsContext — 'dashboard' (default) or 'report'.
 */
if (!isset($adminAnalytics) || !is_array($adminAnalytics)) {
    return;
}
$a = $adminAnalytics;
$adminAnalyticsContext = $adminAnalyticsContext ?? 'dashboard';
$showDashboardAnalyticsHeader = $adminAnalyticsContext === 'dashboard';
$showSystemActivityFeed = $adminAnalyticsContext === 'dashboard';
$recordsUrl = buildAuthUrl('records.php');
$printsDetailsUrl = buildAuthUrl('report.php', ['section' => 'prints', 'range' => 'month']);
$intakeQueueDetailsUrl = buildAuthUrl('report.php', ['section' => 'queue', 'range' => 'today']);
$growth = $a['recordsGrowth'] ?? ['pct' => 0, 'up' => true];
$trend = $a['recordTypeTrend'] ?? ['max' => 0];
$thisMo = $a['thisMonthTypes'] ?? ['birth' => 0, 'death' => 0, 'marriage' => 0];
$lastMo = $a['lastMonthTypes'] ?? ['birth' => 0, 'death' => 0, 'marriage' => 0];
$activities = $activities ?? [];
?>
<section class="dash-admin-analytics" aria-labelledby="dash-admin-analytics-heading">
    <?php if ($showDashboardAnalyticsHeader): ?>
    <div class="dash-admin-analytics__head">
        <div>
            <p class="dash-admin-analytics__kicker">Office analytics</p>
            <h2 id="dash-admin-analytics-heading" class="dash-admin-analytics__title">Comprehensive view</h2>
        </div>
        <a href="<?= htmlspecialchars(buildAuthUrl('report.php', ['section' => 'analytics'])) ?>" class="dash-admin-analytics__reports-link">
            Full reports
            <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i>
        </a>
    </div>
    <?php else: ?>
    <h2 id="dash-admin-analytics-heading" class="sr-only">Office analytics</h2>
    <?php endif; ?>

    <?php if ($a['pendingCount'] > 0 || $a['readyCount'] > 0 || $a['queueWaiting'] > 0): ?>
    <div class="dash-admin-attention">
        <span class="dash-admin-attention__label">Needs attention</span>
        <?php if ($a['pendingCount'] > 0): ?>
        <a href="<?= htmlspecialchars(buildStaffOperationalUrl('manage_request.php', ['status' => 'pending'])) ?>" class="dash-admin-attention__pill"><?= (int) $a['pendingCount'] ?> pending</a>
        <?php endif; ?>
        <?php if ($a['readyCount'] > 0): ?>
        <a href="<?= htmlspecialchars(buildStaffOperationalUrl('manage_request.php', ['status' => 'ready'])) ?>" class="dash-admin-attention__pill"><?= (int) $a['readyCount'] ?> ready</a>
        <?php endif; ?>
        <?php if ($a['queueWaiting'] > 0): ?>
        <a href="<?= htmlspecialchars(buildStaffOperationalUrl('live-queue.php')) ?>" class="dash-admin-attention__pill"><?= (int) $a['queueWaiting'] ?> in queue</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="dash-admin-featured analytics-chart-card">
        <div class="dash-admin-featured__head">
            <div class="dash-admin-featured__metrics">
                <a href="<?= htmlspecialchars($recordsUrl) ?>" class="dash-admin-featured__stat">
                    <p class="dash-admin-featured__stat-label">Civil records on file</p>
                    <div class="dash-admin-featured__stat-row">
                        <span class="dash-admin-featured__stat-value"><?= number_format((int) $a['recordsTotal']) ?></span>
                        <?php if ((int) $a['recordsTotal'] > 0): ?>
                        <span class="dash-admin-featured__badge dash-admin-featured__badge--<?= !empty($growth['up']) ? 'up' : 'down' ?>">
                            <?= !empty($growth['up']) ? '↑' : '↓' ?> <?= (int) ($growth['pct'] ?? 0) ?>%
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="dash-admin-featured__stat-hint">Total records · growth since 6-month period start</p>
                </a>
                <div class="dash-admin-micro-trend" aria-label="New entries this month">
                    <p class="dash-admin-micro-trend__label">Micro-trend</p>
                    <ul class="dash-admin-micro-trend__list">
                        <li><span class="dash-admin-micro-trend__dot dash-admin-micro-trend__dot--birth"></span> <?= (int) $thisMo['birth'] ?> births</li>
                        <li><span class="dash-admin-micro-trend__dot dash-admin-micro-trend__dot--death"></span> <?= (int) $thisMo['death'] ?> deaths</li>
                        <li><span class="dash-admin-micro-trend__dot dash-admin-micro-trend__dot--marriage"></span> <?= (int) $thisMo['marriage'] ?> marriages</li>
                    </ul>
                    <p class="dash-admin-micro-trend__note">Added <?= date('F Y') ?></p>
                </div>
            </div>
            <a href="<?= htmlspecialchars($recordsUrl) ?>" class="dash-admin-featured__details">View details</a>
        </div>
        <?php if (($trend['max'] ?? 0) === 0 && (int) $a['recordsTotal'] === 0): ?>
        <div class="analytics-empty">No civil records yet.</div>
        <?php elseif (($trend['max'] ?? 0) === 0): ?>
        <div class="analytics-empty">No new registry entries in the last 6 months.</div>
        <?php else: ?>
        <div class="chart-box chart-box--featured"><canvas id="chartRecordsTrend"></canvas></div>
        <?php endif; ?>
    </div>

    <?php if ($adminAnalyticsContext !== 'report'): ?>
    <div class="dash-admin-split">
        <div class="analytics-chart-card dash-admin-split__panel">
            <div class="analytics-chart-head dash-admin-chart-head--split">
                <div>
                    <h3>Certificates printed</h3>
                    <p>Certifications and bond certificates · today vs this month</p>
                </div>
                <a href="<?= htmlspecialchars($printsDetailsUrl) ?>" class="dash-admin-featured__details shrink-0">View details</a>
            </div>
            <?php if ($a['certTotal'] === 0 && $a['certificateTotal'] === 0): ?>
            <div class="analytics-empty">No print jobs logged yet.</div>
            <?php else: ?>
            <div class="chart-box chart-box--compact"><canvas id="chartPrintVolume"></canvas></div>
            <?php endif; ?>
        </div>
        <div class="analytics-chart-card dash-admin-split__panel">
            <div class="analytics-chart-head dash-admin-chart-head--split">
                <div>
                    <h3>Daily intake &amp; queue status</h3>
                    <p>Citizen intake (<?= (int) $a['todayRequests'] ?> online, <?= (int) $a['walkInToday'] ?> walk-in) · queue today</p>
                </div>
                <a href="<?= htmlspecialchars($intakeQueueDetailsUrl) ?>" class="dash-admin-featured__details shrink-0">View details</a>
            </div>
            <?php if ($a['todayIntake'] === 0 && $a['queueWaiting'] === 0 && $a['queueServing'] === 0 && $a['queueServed'] === 0): ?>
            <div class="analytics-empty">No intake or queue activity today.</div>
            <?php else: ?>
            <div class="chart-box chart-box--compact"><canvas id="chartDailyIntakeQueue"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($adminAnalyticsContext === 'report'):
        $queueWaitChart = $a['queueWaitChart'] ?? [];
        $queueWaitDetailsUrl = buildAuthUrl('report.php', ['section' => 'queue', 'range' => 'week']);
    ?>
    <div class="dash-admin-queue-wait">
        <div class="dash-admin-queue-wait__head">
            <div>
                <p class="dash-admin-analytics__kicker">Queue performance</p>
                <h3 class="dash-admin-queue-wait__title">Wait before first call</h3>
                <p class="dash-admin-queue-wait__sub">Charts use the last 7 days · per-ticket breakdown on the queue report</p>
            </div>
            <a href="<?= htmlspecialchars($queueWaitDetailsUrl) ?>" class="dash-admin-featured__details">View details</a>
        </div>
    <div class="dash-admin-split">
        <div class="analytics-chart-card dash-admin-split__panel">
            <div class="analytics-chart-head">
                <h3>Queue wait by line</h3>
                <p>Average minutes before first call · called tickets only</p>
            </div>
            <?php if (empty($queueWaitChart['hasData'])): ?>
            <div class="analytics-empty">No called queue tickets in the last 7 days.</div>
            <?php else: ?>
            <div class="chart-box chart-box--compact"><canvas id="chartQueueWaitByPurpose"></canvas></div>
            <?php endif; ?>
        </div>
        <div class="analytics-chart-card dash-admin-split__panel">
            <div class="analytics-chart-head">
                <h3>Queue wait trend</h3>
                <p>Daily average wait · last 7 days</p>
            </div>
            <?php if (empty($queueWaitChart['daily'])): ?>
            <div class="analytics-empty"><?= !empty($queueWaitChart['hasData']) ? 'Need more than one day with called tickets for a trend line.' : 'No data yet.' ?></div>
            <?php else: ?>
            <div class="chart-box chart-box--compact"><canvas id="chartQueueWaitDaily"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
    </div>

    <?php
        $requestsChart = $a['requestsReportChart'] ?? [];
        $requestsDetailsUrl = buildAuthUrl('report.php', ['section' => 'requests', 'range' => 'month']);
    ?>
    <div class="dash-admin-queue-wait">
        <div class="dash-admin-queue-wait__head">
            <div>
                <p class="dash-admin-analytics__kicker">Citizen requests</p>
                <h3 class="dash-admin-queue-wait__title">Document requests</h3>
                <p class="dash-admin-queue-wait__sub"><?= number_format((int) ($requestsChart['total'] ?? $a['totalRequests'] ?? 0)) ?> total · last 6 months of submissions</p>
            </div>
            <a href="<?= htmlspecialchars($requestsDetailsUrl) ?>" class="dash-admin-featured__details">View details</a>
        </div>
        <div class="dash-admin-split">
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>Submissions by month</h3>
                    <p>Online document requests · last 6 months</p>
                </div>
                <?php if (empty($requestsChart['hasData']) || empty($requestsChart['monthly']['totals']) || max($requestsChart['monthly']['totals']) === 0): ?>
                <div class="analytics-empty">No document requests yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--compact"><canvas id="chartAnalyticsRequestsMonthly"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>By document type</h3>
                    <p>All requests on file</p>
                </div>
                <?php if (empty($requestsChart['byType']['labels'])): ?>
                <div class="analytics-empty">No type breakdown yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--donut"><canvas id="chartAnalyticsRequestsByType"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
        $apptChart = $a['appointmentsReportChart'] ?? [];
        $appointmentsDetailsUrl = buildAuthUrl('report.php', ['section' => 'appointments', 'range' => 'month']);
    ?>
    <div class="dash-admin-queue-wait">
        <div class="dash-admin-queue-wait__head">
            <div>
                <p class="dash-admin-analytics__kicker">Scheduling</p>
                <h3 class="dash-admin-queue-wait__title">Appointments</h3>
                <p class="dash-admin-queue-wait__sub"><?= number_format((int) ($apptChart['total'] ?? $a['apptTotal'] ?? 0)) ?> total · visits by appointment date</p>
            </div>
            <a href="<?= htmlspecialchars($appointmentsDetailsUrl) ?>" class="dash-admin-featured__details">View details</a>
        </div>
        <div class="dash-admin-split">
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>Visits by month</h3>
                    <p>Scheduled appointment dates · last 6 months</p>
                </div>
                <?php if (empty($apptChart['hasData']) || empty($apptChart['monthly']['totals']) || max($apptChart['monthly']['totals']) === 0): ?>
                <div class="analytics-empty">No appointments yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--compact"><canvas id="chartAnalyticsAppointmentsMonthly"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>By status</h3>
                    <p>All appointments on file</p>
                </div>
                <?php if (empty($apptChart['byStatus']['labels'])): ?>
                <div class="analytics-empty">No status breakdown yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--donut"><canvas id="chartAnalyticsAppointmentsByStatus"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php
        $printsChart = $a['printsReportChart'] ?? [];
    ?>
    <div class="dash-admin-queue-wait">
        <div class="dash-admin-queue-wait__head">
            <div>
                <p class="dash-admin-analytics__kicker">Registry printing</p>
                <h3 class="dash-admin-queue-wait__title">Print volume</h3>
                <p class="dash-admin-queue-wait__sub">
                    <?= number_format((int) ($printsChart['total'] ?? ((int) ($a['certTotal'] ?? 0) + (int) ($a['certificateTotal'] ?? 0)))) ?> completed production jobs · last 6 months of print activity
                </p>
            </div>
            <a href="<?= htmlspecialchars($printsDetailsUrl) ?>" class="dash-admin-featured__details">View details</a>
        </div>
        <div class="dash-admin-split">
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>Print jobs by month</h3>
                    <p>Certifications and certificates · last 6 months</p>
                </div>
                <?php if (empty($printsChart['hasData']) || empty($printsChart['monthly']['totals']) || max($printsChart['monthly']['totals']) === 0): ?>
                <div class="analytics-empty">No print jobs logged yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--compact"><canvas id="chartAnalyticsPrintsMonthly"></canvas></div>
                <?php endif; ?>
            </div>
            <div class="analytics-chart-card dash-admin-split__panel">
                <div class="analytics-chart-head">
                    <h3>By document kind</h3>
                    <p>All completed production jobs on file</p>
                </div>
                <?php if (empty($printsChart['byKind']['labels'])): ?>
                <div class="analytics-empty">No print breakdown yet.</div>
                <?php else: ?>
                <div class="chart-box chart-box--donut"><canvas id="chartAnalyticsPrintsByKind"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($showSystemActivityFeed):
        $adminCalendarToday = $adminCalendarToday ?? alcrosTodayDate();
    ?>
    <div class="dash-admin-bottom">
        <div class="dash-admin-activity analytics-chart-card dash-admin-bottom__panel">
            <div class="dash-admin-activity__head">
                <div>
                    <h3 class="dash-admin-activity__title">System activity</h3>
                    <p class="dash-admin-activity__sub">Latest actions across the registry office</p>
                </div>
                <a href="<?= htmlspecialchars(buildAuthUrl('activity-log.php')) ?>" class="dash-admin-activity__link">Full log</a>
            </div>
            <?php if ($activities === []): ?>
            <div class="analytics-empty">No activity recorded yet.</div>
            <?php else: ?>
            <ul id="activity-feed-list" class="dash-admin-activity__list">
                <?php foreach ($activities as $act): ?>
                <?php
                $actStaffName = (string) ($act['staff_display_name'] ?? ($act['staff_id'] ?? 'System'));
                $actStaffId = trim((string) ($act['staff_id'] ?? ''));
                ?>
                <li class="dash-admin-activity__item">
                    <span class="dash-admin-activity__avatar" aria-hidden="true">
                        <?= $act['staff_avatar_html'] ?? renderStaffAvatar($act['staff_photo_path'] ?? null, $actStaffName, 'w-9 h-9 text-xs') ?>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="dash-admin-activity__action"><?= htmlspecialchars($act['action']) ?></p>
                        <?php if (!empty($act['details'])): ?>
                        <p class="dash-admin-activity__details"><?= htmlspecialchars($act['details']) ?></p>
                        <?php endif; ?>
                        <p class="dash-admin-activity__meta">
                            <?= htmlspecialchars($actStaffName) ?><?= ($actStaffId !== '' && $actStaffName !== $actStaffId) ? ' · ' . htmlspecialchars($actStaffId) : '' ?> · <?= formatTimeAgo($act['created_at']) ?>
                        </p>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="dash-admin-calendar analytics-chart-card dash-admin-bottom__panel">
            <div class="dash-admin-activity__head">
                <div>
                    <h3 class="dash-admin-activity__title">Calendar</h3>
                    <p class="dash-admin-activity__sub"><?= htmlspecialchars(date('l, F j, Y', strtotime($adminCalendarToday))) ?></p>
                </div>
            </div>
            <div class="dash-schedule-calendar" id="dashAdminCalendar" data-today="<?= htmlspecialchars($adminCalendarToday) ?>">
                <div class="dash-cal-head">
                    <button type="button" id="dashAdminCalPrev" class="dash-cal-nav" aria-label="Previous month">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </button>
                    <p id="dashAdminCalMonthLabel" class="dash-cal-month"></p>
                    <button type="button" id="dashAdminCalNext" class="dash-cal-nav" aria-label="Next month">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="dash-cal-weekdays">
                    <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
                </div>
                <div id="dashAdminCalGrid" class="dash-cal-grid" role="grid" aria-label="Calendar"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</section>
