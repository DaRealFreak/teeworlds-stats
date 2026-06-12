// Import Chart directly (same instance app.js registers) instead of relying on the
// window.Chart global, which app.js only sets after its imports have evaluated.
import { Chart } from 'chart.js';

// Define ChartHelper synchronously at module load so it is available before the
// page's DOMContentLoaded handlers run. (A jQuery $(function(){}) ready wrapper here
// would race with the inline view scripts, which is what broke `ChartHelper` on /general.)
(function () {
    'use strict';

    // Chart.js 4: global defaults are under Chart.defaults (not Chart.defaults.global)
    Chart.defaults.color = '#75787c';

    window.ChartHelper = class ChartHelper {

        static lineChart(chartSelector, chartLabels, chartData) {
            return new Chart(chartSelector, {
                type: 'line',
                options: {
                    plugins: {
                        legend: {
                            labels: {
                                color: "#777",
                                font: { size: 12 }
                            },
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: function (tooltipItems) {
                                    return tooltipItems[0].label;
                                },
                                label: function (tooltipItem) {
                                    return 'Possibility: ' + tooltipItem.raw + '%';
                                },
                            },
                        },
                    },
                    scales: {
                        x: {
                            display: true,
                            grid: {
                                color: 'transparent'
                            }
                        },
                        y: {
                            min: 0,
                            max: 100,
                            display: true,
                            grid: {
                                color: 'transparent'
                            }
                        }
                    },
                },
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: "Weekday Online Probability",
                            fill: true,
                            tension: 0.2,
                            backgroundColor: "rgba(134, 77, 217, 0.88)",
                            borderColor: "rgba(134, 77, 217, 0.88)",
                            borderCapStyle: 'butt',
                            borderDash: [],
                            borderDashOffset: 0.0,
                            borderJoinStyle: 'miter',
                            borderWidth: 1,
                            pointBorderColor: "rgba(134, 77, 217, 0.88)",
                            pointBackgroundColor: "#fff",
                            pointBorderWidth: 1,
                            pointHoverRadius: 5,
                            pointHoverBackgroundColor: "rgba(134, 77, 217, 0.88)",
                            pointHoverBorderColor: "rgba(134, 77, 217, 0.88)",
                            pointHoverBorderWidth: 2,
                            pointRadius: 1,
                            pointHitRadius: 10,
                            data: chartData,
                            spanGaps: false
                        }
                    ]
                }
            });
        }

        static pieChart(chartSelector, chartLabels, chartData) {
            return new Chart(chartSelector, {
                type: 'pie',
                options: {
                    plugins: {
                        legend: {
                            display: true,
                            // right keeps long mod labels (e.g. "DDraceNetwork") clear of the pie on 1080p
                            position: "right"
                        },
                        tooltip: {
                            callbacks: {
                                title: function (tooltipItems) {
                                    return tooltipItems[0].label;
                                },
                                label: function (tooltipItem) {
                                    let dataset = tooltipItem.chart.data.datasets[0];
                                    let total = dataset.data.reduce(function (a, b) {
                                        return a + b;
                                    }, 0);
                                    let percent = Math.round((tooltipItem.raw / total) * 10000) / 100;
                                    return percent + '% (' + humanizeDuration(tooltipItem.raw * 60 * 1000) + ')';
                                },
                            },
                        },
                    }
                },
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            data: chartData,
                            borderWidth: 0,
                            backgroundColor: [
                                '#723ac3',
                                "#864DD9",
                                "#9762e6",
                            ],
                            hoverBackgroundColor: '#4313a0',
                        }]
                }
            });
        }

        static radarChart(chartSelector, chartLabels, chartData, chartMax) {
            new Chart(chartSelector, {
                type: 'radar',
                options: {
                    // the radial scale reserves exactly each point label's width, so the
                    // 3- and 9-o'clock labels (e.g. "RelayGor race") sit flush against the
                    // canvas edge and clip — this margin keeps them off the edge
                    layout: {
                        padding: { left: 14, right: 14, top: 4, bottom: 4 }
                    },
                    scales: {
                        r: {
                            max: chartMax,
                            grid: {
                                color: '#3f4145'
                            },
                            ticks: {
                                display: false,
                                callback: function (value) {
                                    return humanizeDuration(value * 60 * 100);
                                },
                            },
                            pointLabels: {
                                font: { size: 12 }
                            },
                            beginAtZero: true,
                        }
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: function (tooltipItems) {
                                    return tooltipItems[0].label;
                                },
                                label: function (tooltipItem) {
                                    let dataset = tooltipItem.chart.data.datasets[0];
                                    let percent = Math.round((tooltipItem.raw / dataset.data.reduce(function (a, b) {
                                        return a + b;
                                    }, 0)) * 10000) / 100;
                                    return percent + '% (' + humanizeDuration(tooltipItem.raw * 60 * 1000) + ')';
                                },
                            },
                        },
                    }
                },
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: "Played mods",
                            backgroundColor: "rgba(113, 39, 172, 0.4)",
                            borderWidth: 2,
                            borderColor: "#7127AC",
                            pointBackgroundColor: "#7127AC",
                            pointBorderColor: "#fff",
                            pointHoverBackgroundColor: "#fff",
                            pointHoverBorderColor: "#7127AC",
                            data: chartData
                        }
                    ]
                }
            });
        }

        static countryDoughnut(chartSelector, chartLabels, chartData) {
            // distinct hues so neighbouring slices stay readable; the ranked list
            // next to the chart carries the always-visible labels and flags
            let palette = ['#DB6574', '#864DD9', '#3FB0C6', '#E9A23B', '#5BC264', '#CF53F9', '#4F86E0', '#E95F71'];
            let colors = chartData.map(function (_, i) {
                return palette[i % palette.length];
            });

            return new Chart(chartSelector, {
                type: 'doughnut',
                options: {
                    cutout: '62%',
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                title: function (tooltipItems) {
                                    return tooltipItems[0].label;
                                },
                                label: function (tooltipItem) {
                                    let dataset = tooltipItem.chart.data.datasets[0];
                                    let total = dataset.data.reduce(function (a, b) {
                                        return a + b;
                                    }, 0);
                                    let percent = total ? Math.round((tooltipItem.raw / total) * 10000) / 100 : 0;
                                    return percent + '% (' + tooltipItem.raw.toLocaleString() + ' tees)';
                                },
                            },
                        }
                    }
                },
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            data: chartData,
                            borderWidth: 2,
                            borderColor: '#2d3035',
                            backgroundColor: colors,
                        }]
                }
            });
        }

        static chartColors(chart, gradient) {
            /*Gradients
              The keys are percentage and the values are the color in a rgba format.
              You can have as many "color stops" (%) as you like.
              0% and 100% is not optional.*/

            //Get a sorted array of the gradient keys
            let gradientKeys = Object.keys(gradient);
            gradientKeys.sort(function (a, b) {
                return +a - +b;
            });

            //Find datasets and length
            let chartType = chart.config.type;
            let datasets;
            let setsCount;
            switch (chartType) {
                case "pie":
                case "doughnut":
                    datasets = chart.config.data.datasets[0];
                    setsCount = datasets.data.length;
                    break;
                case "bar":
                case "line":
                    datasets = chart.config.data.datasets;
                    setsCount = datasets.length;
                    break;
            }

            //Calculate colors
            let chartColors = [];
            for (let i = 0; i < setsCount; i++) {
                let gradientIndex = (i + 1) * (100 / (setsCount + 1)); //Find where to get a color from the gradient
                for (let j = 0; j < gradientKeys.length; j++) {
                    let gradientKey = gradientKeys[j];
                    if (gradientIndex === +gradientKey) { //Exact match with a gradient key - just get that color
                        chartColors[i] = 'rgba(' + gradient[gradientKey].toString() + ')';
                        break;
                    } else if (gradientIndex < +gradientKey) { //It's somewhere between this gradient key and the previous
                        let prevKey = gradientKeys[j - 1];
                        let gradientPartIndex = (gradientIndex - prevKey) / (gradientKey - prevKey); //Calculate where
                        let color = [];
                        for (let k = 0; k < 4; k++) { //Loop through Red, Green, Blue and Alpha and calculate the correct color and opacity
                            color[k] = gradient[prevKey][k] - ((gradient[prevKey][k] - gradient[gradientKey][k]) * gradientPartIndex);
                            if (k < 3) color[k] = Math.round(color[k]);
                        }
                        chartColors[i] = 'rgba(' + color.toString() + ')';
                        break;
                    }
                }
            }

            //Copy colors to the chart
            for (let i = 0; i < setsCount; i++) {
                switch (chartType) {
                    case "pie":
                    case "doughnut":
                        if (!datasets.backgroundColor) datasets.backgroundColor = [];
                        datasets.backgroundColor[i] = chartColors[i];
                        if (!datasets.borderColor) datasets.borderColor = [];
                        datasets.borderColor[i] = "rgba(255,255,255,1)";
                        break;
                    case "bar":
                        datasets[i].backgroundColor = chartColors[i];
                        datasets[i].borderColor = "rgba(255,255,255,0)";
                        break;
                    case "line":
                        datasets[i].borderColor = chartColors[i];
                        datasets[i].backgroundColor = "rgba(255,255,255,0)";
                        break;
                }
            }

            //Update the chart to show the new colors
            chart.update();
        }
    };
})();
