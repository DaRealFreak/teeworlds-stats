// Import Chart directly (same instance app.ts registers) instead of relying on the
// window.Chart global, which app.ts only sets after its imports have evaluated.
import { Chart, type ChartConfiguration, type ChartItem, type TooltipItem } from 'chart.js';
import humanizeDuration from 'humanize-duration';

// Chart.js 4: global defaults are under Chart.defaults (not Chart.defaults.global)
Chart.defaults.color = '#75787c';

// one gradient stop's rgba channels; the map key is its percentage (0 and 100 required)
type GradientStop = [number, number, number, number];

// the subset of a dataset chartColors() mutates; the live Chart.js types over-constrain
// these color fields (per-chart-type unions), so we narrow to what this method touches
interface ColorableDataset {
    data: number[];
    backgroundColor?: string | string[];
    borderColor?: string | string[];
}

// Defined synchronously at module load so it is available before the page's DOMContentLoaded
// handlers run. (A ready-wrapper here would race the inline view scripts, which is what broke
// `ChartHelper` on /general.)
class ChartHelper {
    static lineChart(chartSelector: ChartItem, chartLabels: string[], chartData: number[]): Chart {
        return new Chart(chartSelector, {
            type: 'line',
            options: {
                plugins: {
                    legend: {
                        labels: {
                            color: '#777',
                            font: { size: 12 },
                        },
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            title: function (tooltipItems: TooltipItem<'line'>[]) {
                                return tooltipItems[0]?.label ?? '';
                            },
                            label: function (tooltipItem: TooltipItem<'line'>) {
                                return 'Possibility: ' + (tooltipItem.raw as number) + '%';
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        display: true,
                        grid: {
                            color: 'transparent',
                        },
                    },
                    y: {
                        min: 0,
                        max: 100,
                        display: true,
                        grid: {
                            color: 'transparent',
                        },
                    },
                },
            },
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Weekday Online Probability',
                        fill: true,
                        tension: 0.2,
                        backgroundColor: 'rgba(134, 77, 217, 0.88)',
                        borderColor: 'rgba(134, 77, 217, 0.88)',
                        borderCapStyle: 'butt',
                        borderDash: [],
                        borderDashOffset: 0.0,
                        borderJoinStyle: 'miter',
                        borderWidth: 1,
                        pointBorderColor: 'rgba(134, 77, 217, 0.88)',
                        pointBackgroundColor: '#fff',
                        pointBorderWidth: 1,
                        pointHoverRadius: 5,
                        pointHoverBackgroundColor: 'rgba(134, 77, 217, 0.88)',
                        pointHoverBorderColor: 'rgba(134, 77, 217, 0.88)',
                        pointHoverBorderWidth: 2,
                        pointRadius: 1,
                        pointHitRadius: 10,
                        data: chartData,
                        spanGaps: false,
                    },
                ],
            },
        });
    }

    static pieChart(chartSelector: ChartItem, chartLabels: string[], chartData: number[]): Chart {
        return new Chart(chartSelector, {
            type: 'pie',
            options: {
                plugins: {
                    legend: {
                        display: true,
                        // right keeps long mod labels (e.g. "DDraceNetwork") clear of the pie on 1080p
                        position: 'right',
                        // the chart splits its 260px width evenly, so the default 40px colour
                        // swatch leaves too little room for the label and it clips at the edge;
                        // a small swatch frees that space for the full mod name
                        labels: {
                            boxWidth: 12,
                            boxHeight: 12,
                        },
                    },
                    tooltip: {
                        callbacks: {
                            title: function (tooltipItems: TooltipItem<'pie'>[]) {
                                return tooltipItems[0]?.label ?? '';
                            },
                            label: function (tooltipItem: TooltipItem<'pie'>) {
                                const data = (tooltipItem.chart.data.datasets[0]?.data ?? []) as number[];
                                const total = data.reduce((a, b) => a + b, 0);
                                const raw = tooltipItem.raw as number;
                                const percent = Math.round((raw / total) * 10000) / 100;
                                return percent + '% (' + humanizeDuration(raw * 60 * 1000) + ')';
                            },
                        },
                    },
                },
            },
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        data: chartData,
                        borderWidth: 0,
                        backgroundColor: ['#723ac3', '#864DD9', '#9762e6'],
                        hoverBackgroundColor: '#4313a0',
                    },
                ],
            },
        });
    }

    static radarChart(chartSelector: ChartItem, chartLabels: string[], chartData: number[], chartMax: number): void {
        new Chart(chartSelector, {
            type: 'radar',
            options: {
                // the radial scale reserves exactly each point label's width, so the
                // 3- and 9-o'clock labels (e.g. "RelayGor race") sit flush against the
                // canvas edge and clip — this margin keeps them off the edge
                layout: {
                    padding: { left: 14, right: 14, top: 4, bottom: 4 },
                },
                scales: {
                    r: {
                        max: chartMax,
                        grid: {
                            color: '#3f4145',
                        },
                        ticks: {
                            display: false,
                            callback: function (value: number | string) {
                                return humanizeDuration((value as number) * 60 * 100);
                            },
                        },
                        pointLabels: {
                            font: { size: 12 },
                        },
                        beginAtZero: true,
                    },
                },
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            title: function (tooltipItems: TooltipItem<'radar'>[]) {
                                return tooltipItems[0]?.label ?? '';
                            },
                            label: function (tooltipItem: TooltipItem<'radar'>) {
                                const data = (tooltipItem.chart.data.datasets[0]?.data ?? []) as number[];
                                const raw = tooltipItem.raw as number;
                                const percent = Math.round((raw / data.reduce((a, b) => a + b, 0)) * 10000) / 100;
                                return percent + '% (' + humanizeDuration(raw * 60 * 1000) + ')';
                            },
                        },
                    },
                },
            },
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        label: 'Played mods',
                        backgroundColor: 'rgba(113, 39, 172, 0.4)',
                        borderWidth: 2,
                        borderColor: '#7127AC',
                        pointBackgroundColor: '#7127AC',
                        pointBorderColor: '#fff',
                        pointHoverBackgroundColor: '#fff',
                        pointHoverBorderColor: '#7127AC',
                        data: chartData,
                    },
                ],
            },
        });
    }

    static countryDoughnut(chartSelector: ChartItem, chartLabels: string[], chartData: number[]): Chart {
        // distinct hues so neighbouring slices stay readable; the ranked list
        // next to the chart carries the always-visible labels and flags
        const palette = ['#DB6574', '#864DD9', '#3FB0C6', '#E9A23B', '#5BC264', '#CF53F9', '#4F86E0', '#E95F71'];
        const colors = chartData.map((_, i) => palette[i % palette.length]!);

        return new Chart(chartSelector, {
            type: 'doughnut',
            options: {
                cutout: '62%',
                plugins: {
                    legend: {
                        display: false,
                    },
                    tooltip: {
                        callbacks: {
                            title: function (tooltipItems: TooltipItem<'doughnut'>[]) {
                                return tooltipItems[0]?.label ?? '';
                            },
                            label: function (tooltipItem: TooltipItem<'doughnut'>) {
                                const data = (tooltipItem.chart.data.datasets[0]?.data ?? []) as number[];
                                const total = data.reduce((a, b) => a + b, 0);
                                const raw = tooltipItem.raw as number;
                                const percent = total ? Math.round((raw / total) * 10000) / 100 : 0;
                                return percent + '% (' + raw.toLocaleString() + ' tees)';
                            },
                        },
                    },
                },
            },
            data: {
                labels: chartLabels,
                datasets: [
                    {
                        data: chartData,
                        borderWidth: 2,
                        borderColor: '#2d3035',
                        backgroundColor: colors,
                    },
                ],
            },
        });
    }

    static chartColors(chart: Chart, gradient: Record<number, GradientStop>): void {
        // Gradients: keys are percentages, values are rgba channels. As many stops as you like,
        // but 0% and 100% are required.

        // sorted gradient stop percentages
        const gradientKeys = Object.keys(gradient)
            .map(Number)
            .sort((a, b) => a - b);

        // Find datasets and length (config is a per-type union; only the standard form carries `type`)
        const chartType = (chart.config as ChartConfiguration).type;
        const allDatasets = chart.config.data.datasets as unknown as ColorableDataset[];
        let setsCount = 0;
        switch (chartType) {
            case 'pie':
            case 'doughnut':
                setsCount = allDatasets[0]?.data.length ?? 0;
                break;
            case 'bar':
            case 'line':
                setsCount = allDatasets.length;
                break;
        }

        // Calculate colors
        const chartColors: string[] = [];
        for (let i = 0; i < setsCount; i++) {
            const gradientIndex = (i + 1) * (100 / (setsCount + 1)); // where to sample the gradient
            for (let j = 0; j < gradientKeys.length; j++) {
                const gradientKey = gradientKeys[j]!;
                if (gradientIndex === gradientKey) {
                    // Exact match with a stop — take that color
                    chartColors[i] = 'rgba(' + gradient[gradientKey]!.toString() + ')';
                    break;
                } else if (gradientIndex < gradientKey) {
                    // Somewhere between this stop and the previous one
                    const prevKey = gradientKeys[j - 1]!;
                    const part = (gradientIndex - prevKey) / (gradientKey - prevKey);
                    const color: number[] = [];
                    const from = gradient[prevKey]!;
                    const to = gradient[gradientKey]!;
                    for (let k = 0; k < 4; k++) {
                        // interpolate each of Red, Green, Blue and Alpha
                        color[k] = from[k]! - (from[k]! - to[k]!) * part;
                        if (k < 3) color[k] = Math.round(color[k]!);
                    }
                    chartColors[i] = 'rgba(' + color.toString() + ')';
                    break;
                }
            }
        }

        // Copy colors to the chart
        for (let i = 0; i < setsCount; i++) {
            switch (chartType) {
                case 'pie':
                case 'doughnut': {
                    const dataset = allDatasets[0]!;
                    if (!Array.isArray(dataset.backgroundColor)) dataset.backgroundColor = [];
                    dataset.backgroundColor[i] = chartColors[i]!;
                    if (!Array.isArray(dataset.borderColor)) dataset.borderColor = [];
                    dataset.borderColor[i] = 'rgba(255,255,255,1)';
                    break;
                }
                case 'bar':
                    allDatasets[i]!.backgroundColor = chartColors[i]!;
                    allDatasets[i]!.borderColor = 'rgba(255,255,255,0)';
                    break;
                case 'line':
                    allDatasets[i]!.borderColor = chartColors[i]!;
                    allDatasets[i]!.backgroundColor = 'rgba(255,255,255,0)';
                    break;
            }
        }

        // Update the chart to show the new colors
        chart.update();
    }
}

window.ChartHelper = ChartHelper;

export { ChartHelper };
