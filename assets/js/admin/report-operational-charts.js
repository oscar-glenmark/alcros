(function () {
    'use strict';

    var cfg = (window.AlcrosPage && AlcrosPage.readConfig('operational-report-charts-config')) || {};
    if (typeof Chart === 'undefined') {
        return;
    }

    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#94a3b8';

    var axisGrid = { color: '#f1f5f9', drawBorder: false };
    var tooltip = {
        backgroundColor: '#0f172a',
        titleFont: { size: 11, weight: '600' },
        bodyFont: { size: 11 },
        padding: 10,
        cornerRadius: 8
    };

    function barChart(canvasId, labels, counts, color) {
        var el = document.getElementById(canvasId);
        if (!el || !labels || !labels.length) {
            return;
        }
        new Chart(el, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: counts,
                    backgroundColor: color,
                    borderRadius: 6,
                    barThickness: 18,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: tooltip },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: axisGrid,
                        ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                    },
                    x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                }
            }
        });
    }

    function donutChart(canvasId, slice) {
        var el = document.getElementById(canvasId);
        if (!el || !slice || !slice.labels || !slice.labels.length) {
            return;
        }
        new Chart(el, {
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
                    tooltip: tooltip
                }
            }
        });
    }

    var requests = cfg.requests;
    if (requests && requests.hasData) {
        if (requests.period) {
            barChart(
                'chartRequestsPeriodTrend',
                requests.period.labels,
                requests.period.counts,
                'rgba(37, 99, 235, 0.75)'
            );
        }
        donutChart('chartRequestsByStatus', requests.byStatus);
        donutChart('chartRequestsByType', requests.byType);
    }

    var appointments = cfg.appointments;
    if (appointments && appointments.hasData) {
        if (appointments.period) {
            barChart(
                'chartAppointmentsPeriodTrend',
                appointments.period.labels,
                appointments.period.counts,
                'rgba(139, 92, 246, 0.78)'
            );
        }
        donutChart('chartAppointmentsByStatus', appointments.byStatus);
    }

    var prints = cfg.prints;
    if (prints && prints.hasData) {
        var periodEl = document.getElementById('chartPrintsPeriodTrend');
        if (periodEl && prints.period && prints.period.labels && prints.period.labels.length) {
            new Chart(periodEl, {
                type: 'bar',
                data: {
                    labels: prints.period.labels,
                    datasets: [
                        {
                            label: 'Certifications',
                            data: prints.period.certification,
                            backgroundColor: 'rgba(37, 99, 235, 0.88)',
                            borderRadius: 6,
                            barThickness: 22,
                            maxBarThickness: 32
                        },
                        {
                            label: 'Certificates',
                            data: prints.period.certificate,
                            backgroundColor: 'rgba(20, 184, 166, 0.88)',
                            borderRadius: 6,
                            barThickness: 22,
                            maxBarThickness: 32
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 8, boxHeight: 8, padding: 12, font: { size: 10 }, color: '#64748b' }
                        },
                        tooltip: tooltip
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            stacked: true,
                            grid: axisGrid,
                            ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                        },
                        x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } }
                    }
                }
            });
        }
        donutChart('chartPrintsByKind', prints.byKind);
        donutChart('chartPrintsByType', prints.byType);
    }

    var queueWait = cfg.queueWait;
    if (queueWait && queueWait.hasData) {
        var waitTooltip = {
            backgroundColor: '#0f172a',
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

        var purposeEl = document.getElementById('chartQueueReportWaitByPurpose');
        if (purposeEl && queueWait.byPurpose && queueWait.byPurpose.labels && queueWait.byPurpose.labels.length) {
            new Chart(purposeEl, {
                type: 'bar',
                data: {
                    labels: queueWait.byPurpose.labels,
                    datasets: [{
                        data: queueWait.byPurpose.minutes,
                        backgroundColor: queueWait.byPurpose.colors,
                        borderRadius: 6,
                        barThickness: 32,
                        maxBarThickness: 48
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: waitTooltip },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Minutes', color: '#94a3b8', font: { size: 10 } },
                            grid: axisGrid,
                            ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                        },
                        x: { grid: { display: false }, ticks: { color: '#475569', font: { size: 10, weight: '600' } } }
                    }
                }
            });
        }

        var dailyEl = document.getElementById('chartQueueReportWaitDaily');
        if (dailyEl && queueWait.daily && queueWait.daily.labels && queueWait.daily.labels.length) {
            new Chart(dailyEl, {
                type: 'line',
                data: {
                    labels: queueWait.daily.labels,
                    datasets: [{
                        data: queueWait.daily.minutes,
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
                    plugins: { legend: { display: false }, tooltip: waitTooltip },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Minutes', color: '#94a3b8', font: { size: 10 } },
                            grid: axisGrid,
                            ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                        },
                        x: { grid: { display: false }, ticks: { font: { size: 10 } } }
                    }
                }
            });
        }
    }
})();
