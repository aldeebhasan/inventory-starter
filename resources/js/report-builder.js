import Chart from 'chart.js/auto';

// Series colours, readable on light and dark panels. The previous period is drawn muted and dashed.
const PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#db2777', '#7c3aed', '#0891b2', '#dc2626', '#65a30d'];
const MUTED = '#94a3b8';

const isDark = () => document.documentElement.classList.contains('dark');

function config(data) {
    const dark = isDark();
    const text = dark ? '#cbd5e1' : '#475569';
    const grid = dark ? 'rgba(148,163,184,0.15)' : 'rgba(100,116,139,0.15)';
    const round = ['pie', 'donut'].includes(data.type);
    const horizontal = data.type === 'horizontal_bar';
    const filled = data.type === 'area';

    const datasets = data.datasets.map((set, index) => {
        const colour = set.previous ? MUTED : PALETTE[index % PALETTE.length];

        return {
            label: set.label,
            data: set.data,
            backgroundColor: round
                ? data.labels.map((_, i) => PALETTE[i % PALETTE.length])
                : filled ? `${colour}33` : colour,
            borderColor: round ? (dark ? '#111827' : '#ffffff') : colour,
            borderWidth: round ? 2 : (['line', 'area'].includes(data.type) ? 2 : 0),
            borderDash: set.previous ? [6, 4] : [],
            borderRadius: round ? 0 : 4,
            fill: filled,
            tension: 0.3,
            pointRadius: data.labels.length > 40 ? 0 : 3,
        };
    });

    return {
        type: { horizontal_bar: 'bar', area: 'line', donut: 'doughnut' }[data.type] ?? data.type,
        data: { labels: data.labels, datasets },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: { duration: 300 },
            indexAxis: horizontal ? 'y' : 'x',
            interaction: { mode: round ? 'nearest' : 'index', intersect: false },
            plugins: {
                legend: { display: round || datasets.length > 1, position: 'bottom', labels: { color: text, boxWidth: 12 } },
                tooltip: { mode: round ? 'nearest' : 'index' },
            },
            scales: round ? {} : {
                x: { ticks: { color: text, maxRotation: 0, autoSkip: true }, grid: { color: horizontal ? grid : 'transparent' } },
                y: { ticks: { color: text }, grid: { color: horizontal ? 'transparent' : grid }, beginAtZero: true },
            },
            cutout: data.type === 'donut' ? '60%' : undefined,
        },
    };
}

function reportChart() {
    return {
        chart: null,

        init() {
            this.draw(this.$wire.chart);
            this.$wire.$watch('chart', (data) => this.draw(data));

            // Redraw in the new colours when the panel switches between light and dark.
            this.observer = new MutationObserver(() => this.draw(this.$wire.chart));
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        draw(data) {
            this.chart?.destroy();
            this.chart = null;

            if (data && this.$refs.canvas) {
                this.chart = new Chart(this.$refs.canvas, config(data));
            }
        },

        destroy() {
            this.observer?.disconnect();
            this.chart?.destroy();
        },
    };
}

// The bundle can load before or after Alpine starts.
if (window.Alpine) {
    window.Alpine.data('reportChart', reportChart);
} else {
    document.addEventListener('alpine:init', () => window.Alpine.data('reportChart', reportChart));
}
