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

    if (document.getElementById('chartMonths') && data.months) {
        var monthDatasets = [];
        if (Array.isArray(data.months.online) && Array.isArray(data.months.walkIn)) {
            monthDatasets = [
                {
                    label: 'Online requests',
                    data: data.months.online,
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
                },
                {
                    label: 'Walk-in queue',
                    data: data.months.walkIn,
                    borderColor: '#f97316',
                    backgroundColor: 'rgba(249, 115, 22, 0.08)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 4,
                    pointBackgroundColor: '#f97316',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2
                }
            ];
        } else {
            monthDatasets = [{
                data: data.months.counts || [],
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
            }];
        }

        trackChart(new Chart(document.getElementById('chartMonths'), {
            type: 'line',
            data: {
                labels: data.months.labels,
                datasets: monthDatasets
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: Object.assign({}, sharedPlugins, {
                    legend: monthDatasets.length > 1 ? {
                        position: 'bottom',
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 10 },
                            color: '#64748b'
                        }
                    } : { display: false }
                }),
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: axisStyle.grid,
                        ticks: { padding: 6, color: '#94a3b8', font: { size: 10 }, precision: 0, maxTicksLimit: 5 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 10 } }
                    }
                }
            }
        }));
    }

    if (document.getElementById('chartIntakeChannels') && data.intakeChannels) {
        trackChart(new Chart(document.getElementById('chartIntakeChannels'), {
            type: 'doughnut',
            data: {
                labels: data.intakeChannels.labels,
                datasets: [{
                    data: data.intakeChannels.counts,
                    backgroundColor: data.intakeChannels.colors,
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
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 10 },
                            color: '#64748b'
                        }
                    },
                    tooltip: sharedPlugins.tooltip
                }
            }
        }));
    }

    if (document.getElementById('chartPipeline') && data.pipeline) {
        trackChart(new Chart(document.getElementById('chartPipeline'), {
            type: 'bar',
            data: {
                labels: data.pipeline.labels,
                datasets: [{
                    data: data.pipeline.counts,
                    backgroundColor: data.pipeline.colors,
                    borderRadius: 6,
                    barThickness: 18
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
                        ticks: { padding: 6, color: '#94a3b8', font: { size: 10 }, precision: 0, maxTicksLimit: 5 }
                    },
                    y: {
                        grid: { display: false },
                        ticks: { color: '#475569', font: { size: 11, weight: '600' } }
                    }
                }
            }
        }));
    }

    if (document.getElementById('chartAppointments') && data.appointments) {
        trackChart(new Chart(document.getElementById('chartAppointments'), {
            type: 'doughnut',
            data: {
                labels: data.appointments.labels,
                datasets: [{
                    data: data.appointments.counts,
                    backgroundColor: data.appointments.colors,
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
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 10 },
                            color: '#64748b'
                        }
                    },
                    tooltip: sharedPlugins.tooltip
                }
            }
        }));
    }

    if (document.getElementById('chartRecordsType') && data.records && data.records.types) {
        trackChart(new Chart(document.getElementById('chartRecordsType'), {
            type: 'doughnut',
            data: {
                labels: data.records.types.labels,
                datasets: [{
                    data: data.records.types.counts,
                    backgroundColor: data.records.types.colors,
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
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 10 },
                            color: '#64748b'
                        }
                    },
                    tooltip: sharedPlugins.tooltip
                }
            }
        }));
    }

    if (document.getElementById('chartCertTypes') && data.certifications && data.certifications.types) {
        trackChart(new Chart(document.getElementById('chartCertTypes'), {
            type: 'doughnut',
            data: {
                labels: data.certifications.types.labels,
                datasets: [{
                    data: data.certifications.types.counts,
                    backgroundColor: data.certifications.types.colors,
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
                        labels: {
                            boxWidth: 8,
                            boxHeight: 8,
                            padding: 14,
                            font: { size: 10 },
                            color: '#64748b'
                        }
                    },
                    tooltip: sharedPlugins.tooltip
                }
            }
        }));
    }

    if (document.getElementById('chartCertMonths') && data.certifications && data.certifications.months) {
        trackChart(new Chart(document.getElementById('chartCertMonths'), {
            type: 'bar',
            data: {
                labels: data.certifications.months.labels,
                datasets: [{
                    data: data.certifications.months.counts,
                    backgroundColor: 'rgba(124, 58, 237, 0.85)',
                    borderRadius: 6,
                    barThickness: 22,
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
                        ticks: { padding: 6, color: '#94a3b8', font: { size: 10 }, precision: 0, maxTicksLimit: 5 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 10 } }
                    }
                }
            }
        }));
    }

    if (document.getElementById('chartRecordsMonths') && data.records && data.records.months) {
        trackChart(new Chart(document.getElementById('chartRecordsMonths'), {
            type: 'bar',
            data: {
                labels: data.records.months.labels,
                datasets: [{
                    data: data.records.months.counts,
                    backgroundColor: 'rgba(236, 72, 153, 0.85)',
                    borderRadius: 6,
                    barThickness: 22,
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
                        ticks: { padding: 6, color: '#94a3b8', font: { size: 10 }, precision: 0, maxTicksLimit: 5 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#94a3b8', font: { size: 10 } }
                    }
                }
            }
        }));
    }

})();
