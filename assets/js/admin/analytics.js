(function () {
    'use strict';

    var data = (window.AlcrosPage && AlcrosPage.readConfig('analytics-config')) || {};
    if (typeof Chart === 'undefined') return;

    Chart.defaults.font.family = 'Inter, sans-serif';
    Chart.defaults.color = '#64748b';

    var doughnutOpts = {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12 } }
        }
    };

    if (document.getElementById('chartPipeline') && data.pipeline) {
        new Chart(document.getElementById('chartPipeline'), {
            type: 'doughnut',
            data: {
                labels: data.pipeline.labels,
                datasets: [{ data: data.pipeline.counts, backgroundColor: data.pipeline.colors, borderWidth: 0 }]
            },
            options: doughnutOpts
        });
    }

    if (document.getElementById('chartMonths') && data.months) {
        new Chart(document.getElementById('chartMonths'), {
            type: 'bar',
            data: {
                labels: data.months.labels,
                datasets: [{ data: data.months.counts, backgroundColor: '#3b82f6', borderRadius: 6, maxBarThickness: 40 }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    if (document.getElementById('chartAppointments') && data.appointments) {
        new Chart(document.getElementById('chartAppointments'), {
            type: 'doughnut',
            data: {
                labels: data.appointments.labels,
                datasets: [{ data: data.appointments.counts, backgroundColor: data.appointments.colors, borderWidth: 0 }]
            },
            options: doughnutOpts
        });
    }
})();
