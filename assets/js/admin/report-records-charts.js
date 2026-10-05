(function () {
    'use strict';

    var data = (window.AlcrosPage && AlcrosPage.readConfig('records-report-chart-config')) || {};
    if (typeof Chart === 'undefined' || !data.hasData) {
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

    var quarterlyEl = document.getElementById('chartRecordsQuarterly');
    if (quarterlyEl && data.quarterly) {
        var q = data.quarterly;
        new Chart(quarterlyEl, {
            type: 'bar',
            data: {
                labels: q.labels,
                datasets: [
                    {
                        label: 'Birth',
                        data: q.birth,
                        backgroundColor: 'rgba(37, 99, 235, 0.88)',
                        borderRadius: 4,
                        stack: 'records'
                    },
                    {
                        label: 'Death',
                        data: q.death,
                        backgroundColor: 'rgba(100, 116, 139, 0.88)',
                        borderRadius: 4,
                        stack: 'records'
                    },
                    {
                        label: 'Marriage',
                        data: q.marriage,
                        backgroundColor: 'rgba(236, 72, 153, 0.88)',
                        borderRadius: 4,
                        stack: 'records'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { boxWidth: 8, boxHeight: 8, padding: 12, font: { size: 10 }, color: '#64748b' }
                    },
                    tooltip: tooltip
                },
                scales: {
                    x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10, weight: '600' }, color: '#475569' } },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        grid: axisGrid,
                        ticks: { precision: 0, maxTicksLimit: 6, font: { size: 10 } }
                    }
                }
            }
        });
    }

    var typesEl = document.getElementById('chartRecordsReportTypes');
    if (typesEl && data.types) {
        new Chart(typesEl, {
            type: 'doughnut',
            data: {
                labels: data.types.labels,
                datasets: [{
                    data: data.types.counts,
                    backgroundColor: data.types.colors,
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

    var monthlyEl = document.getElementById('chartRecordsMonthly');
    if (monthlyEl && data.monthly) {
        new Chart(monthlyEl, {
            type: 'bar',
            data: {
                labels: data.monthly.labels,
                datasets: [{
                    label: 'Registrations',
                    data: data.monthly.totals,
                    backgroundColor: 'rgba(37, 99, 235, 0.75)',
                    borderRadius: 6,
                    barThickness: 18,
                    maxBarThickness: 28
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: tooltip
                },
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
})();
