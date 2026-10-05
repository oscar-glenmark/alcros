(function () {
    'use strict';

    var data = (window.AlcrosPage && AlcrosPage.readConfig('analytics-config')) || {};
    if (typeof Chart === 'undefined') return;

    var charts = [];

    function chartPalette() {
        return { text: '#94a3b8', grid: '#f1f5f9', tooltip: '#0f172a' };
    }

    function applyChartTheme() {
        var palette = chartPalette();
        Chart.defaults.color = palette.text;
        charts.forEach(function (chart) {
            if (!chart || !chart.options) return;
            if (chart.options.scales) {
                Object.keys(chart.options.scales).forEach(function (axisKey) {
                    var axis = chart.options.scales[axisKey];
                    if (axis.grid) axis.grid.color = palette.grid;
                    if (axis.ticks) axis.ticks.color = palette.text;
                });
            }
            if (chart.options.plugins && chart.options.plugins.tooltip) {
                chart.options.plugins.tooltip.backgroundColor = palette.tooltip;
            }
            chart.update('none');
        });
    }

    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = chartPalette().text;

    var sharedPlugins = {
        legend: { display: false },
        tooltip: {
            backgroundColor: chartPalette().tooltip,
            titleFont: { size: 11, weight: '600' },
            bodyFont: { size: 11 },
            padding: 10,
            cornerRadius: 8,
            displayColors: true,
            boxWidth: 8,
            boxHeight: 8,
            boxPadding: 4
        }
    };

    var axisStyle = {
        grid: { color: chartPalette().grid, drawBorder: false },
        ticks: { padding: 6, color: chartPalette().text, font: { size: 10 } }
    };

    function trackChart(chart) {
        charts.push(chart);
        return chart;
    }

    if (document.getElementById('chartRecordsTrend') && data.recordTypeTrend) {
        var rt = data.recordTypeTrend;
        trackChart(new Chart(document.getElementById('chartRecordsTrend'), {
            type: 'line',
            data: {
                labels: rt.labels,
                datasets: [
                    {
                        label: 'Birth',
                        data: rt.birth,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.12)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        borderWidth: 2
                    },
                    {
                        label: 'Death',
                        data: rt.death,
                        borderColor: '#f97316',
                        backgroundColor: 'rgba(249, 115, 22, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        borderWidth: 2
                    },
                    {
                        label: 'Marriage',
                        data: rt.marriage,
                        borderColor: '#14b8a6',
                        backgroundColor: 'rgba(20, 184, 166, 0.1)',
                        fill: true,
                        tension: 0.4,
                        pointRadius: 2,
                        borderWidth: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: Object.assign({}, sharedPlugins, {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, boxHeight: 8, padding: 12, font: { size: 10 }, color: '#64748b' }
                    }
                }),
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: axisStyle.grid,
                        ticks: { precision: 0, maxTicksLimit: 5, font: { size: 10 } }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        }));
    }

    if (document.getElementById('chartPrintVolume') && data.printVolume) {
        var pv = data.printVolume;
        trackChart(new Chart(document.getElementById('chartPrintVolume'), {
            type: 'bar',
            data: {
                labels: ['Today', 'This month'],
                datasets: [
                    {
                        label: 'Certifications',
                        data: [pv.certificationToday, pv.certificationMonth],
                        backgroundColor: 'rgba(37, 99, 235, 0.88)',
                        borderRadius: 6,
                        barThickness: 28,
                        maxBarThickness: 36
                    },
                    {
                        label: 'Certificates',
                        data: [pv.certificateToday, pv.certificateMonth],
                        backgroundColor: 'rgba(20, 184, 166, 0.88)',
                        borderRadius: 6,
                        barThickness: 28,
                        maxBarThickness: 36
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: Object.assign({}, sharedPlugins, {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, boxHeight: 8, padding: 12, font: { size: 10 }, color: '#64748b' }
                    }
                }),
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: axisStyle.grid,
                        ticks: { precision: 0, maxTicksLimit: 5, font: { size: 10 } }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        }));
    }

    if (document.getElementById('chartDailyIntakeQueue') && data.dailyIntakeQueue) {
        var diq = data.dailyIntakeQueue;
        trackChart(new Chart(document.getElementById('chartDailyIntakeQueue'), {
            type: 'bar',
            data: {
                labels: diq.labels,
                datasets: [{
                    data: diq.counts,
                    backgroundColor: diq.colors,
                    borderRadius: 6,
                    barThickness: 14,
                    maxBarThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: sharedPlugins,
                scales: {
                    x: {
                        beginAtZero: true,
                        grid: axisStyle.grid,
                        ticks: { precision: 0, maxTicksLimit: 5, font: { size: 10 } }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#475569', font: { size: 10, weight: '600' } }
                    }
                }
            }
        }));
    }

    if (data.queueWait && data.queueWait.hasData) {
        var qw = data.queueWait;
        var queueWaitTooltip = {
            backgroundColor: chartPalette().tooltip,
            titleFont: { size: 11, weight: '600' },
            bodyFont: { size: 11 },
            padding: 10,
            cornerRadius: 8,
            callbacks: {
                label: function (ctx) {
                    var val = ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
                    return ' Avg wait: ' + val + ' min';
                }
            }
        };

        if (document.getElementById('chartQueueWaitByPurpose') && qw.byPurpose && qw.byPurpose.labels.length) {
            trackChart(new Chart(document.getElementById('chartQueueWaitByPurpose'), {
                type: 'bar',
                data: {
                    labels: qw.byPurpose.labels,
                    datasets: [{
                        data: qw.byPurpose.minutes,
                        backgroundColor: qw.byPurpose.colors,
                        borderRadius: 6,
                        barThickness: 32,
                        maxBarThickness: 48
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: Object.assign({}, sharedPlugins, { tooltip: queueWaitTooltip }),
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Minutes', color: '#94a3b8', font: { size: 10 } },
                            grid: axisStyle.grid,
                            ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#475569', font: { size: 10, weight: '600' } }
                        }
                    }
                }
            }));
        }

        if (document.getElementById('chartQueueWaitDaily') && qw.daily && qw.daily.labels.length) {
            trackChart(new Chart(document.getElementById('chartQueueWaitDaily'), {
                type: 'line',
                data: {
                    labels: qw.daily.labels,
                    datasets: [{
                        data: qw.daily.minutes,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37, 99, 235, 0.08)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.35,
                        pointRadius: 3,
                        pointHoverRadius: 4,
                        pointBackgroundColor: '#2563eb',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: Object.assign({}, sharedPlugins, { tooltip: queueWaitTooltip }),
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Minutes', color: '#94a3b8', font: { size: 10 } },
                            grid: axisStyle.grid,
                            ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                        },
                        x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                    }
                }
            }));
        }
    }

    function initMonthlyBar(canvasId, monthly, barColor) {
        var el = document.getElementById(canvasId);
        if (!el || !monthly || !monthly.labels || !monthly.labels.length) {
            return;
        }
        var totals = monthly.totals || monthly.counts || [];
        trackChart(new Chart(el, {
            type: 'bar',
            data: {
                labels: monthly.labels,
                datasets: [{
                    data: totals,
                    backgroundColor: barColor,
                    borderRadius: 6,
                    barThickness: 18,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: sharedPlugins,
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: axisStyle.grid,
                        ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        }));
    }

    function initDonut(canvasId, slice) {
        var el = document.getElementById(canvasId);
        if (!el || !slice || !slice.labels || !slice.labels.length) {
            return;
        }
        trackChart(new Chart(el, {
            type: 'doughnut',
            data: {
                labels: slice.labels,
                datasets: [{
                    data: slice.counts,
                    backgroundColor: slice.colors,
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '68%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, boxHeight: 8, padding: 12, font: { size: 10 }, color: '#64748b' }
                    },
                    tooltip: sharedPlugins.tooltip
                }
            }
        }));
    }

    if (data.requestsReport) {
        var rr = data.requestsReport;
        initMonthlyBar('chartAnalyticsRequestsMonthly', rr.monthly, 'rgba(37, 99, 235, 0.75)');
        initDonut('chartAnalyticsRequestsByType', rr.byType);
    }

    if (data.appointmentsReport) {
        var ar = data.appointmentsReport;
        initMonthlyBar('chartAnalyticsAppointmentsMonthly', ar.monthly, 'rgba(139, 92, 246, 0.78)');
        initDonut('chartAnalyticsAppointmentsByStatus', ar.byStatus);
    }

    if (data.printsReport) {
        var pr = data.printsReport;
        initMonthlyBar('chartAnalyticsPrintsMonthly', pr.monthly, 'rgba(8, 145, 178, 0.78)');
        initDonut('chartAnalyticsPrintsByKind', pr.byKind);
    }

})();
