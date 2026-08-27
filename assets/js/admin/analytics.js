(function () {
    'use strict';

    var data = (window.AlcrosPage && AlcrosPage.readConfig('analytics-config')) || {};
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.font.size = 11;
    Chart.defaults.color = '#64748b';

    var sharedPlugins = {
        legend: { display: false },
        tooltip: {
            backgroundColor: '#0f172a',
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
        grid: { color: '#f1f5f9', drawBorder: false },
        ticks: { padding: 6, color: '#94a3b8', font: { size: 10 } }
    };

    if (document.getElementById('chartMonths') && data.months) {
        new Chart(document.getElementById('chartMonths'), {
            type: 'line',
            data: {
                labels: data.months.labels,
                datasets: [{
                    data: data.months.counts,
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
        });
    }

    if (document.getElementById('chartPipeline') && data.pipeline) {
        new Chart(document.getElementById('chartPipeline'), {
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
        });
    }

    if (document.getElementById('chartAppointments') && data.appointments) {
        new Chart(document.getElementById('chartAppointments'), {
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
        });
    }
})();
