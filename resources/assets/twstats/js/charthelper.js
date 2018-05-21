$(function () {
    'use strict';

    Chart.defaults.global.defaultFontColor = '#75787c';

    window.ChartHelper = class ChartHelper {

        static lineChart(chartSelector, chartLabels, chartData) {
            return new Chart(chartSelector, {
                type: 'line',
                options: {
                    legend: {
                        labels: {
                            fontColor: "#777",
                            fontSize: 12
                        },
                        display: false
                    },
                    scales: {
                        xAxes: [{
                            display: true,
                            gridLines: {
                                color: 'transparent'
                            }
                        }],
                        yAxes: [{
                            ticks: {
                                max: 100,
                                min: 0
                            },
                            display: true,
                            gridLines: {
                                color: 'transparent'
                            }
                        }]
                    },
                    tooltips: {
                        callbacks: {
                            title: function (tooltipItem, data) {
                                return data['labels'][tooltipItem[0].index];
                            },
                            label: function (tooltipItem, data) {
                                return 'Possibility: ' + data['datasets'][0]['data'][tooltipItem.index] + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: chartLabels,
                    datasets: [
                        {
                            label: "Weekday Online Probability",
                            fill: true,
                            lineTension: 0.2,
                            backgroundColor: "rgba(134, 77, 217, 0.88)",
                            borderColor: "rgba(134, 77, 217, 088)",
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
                    legend: {
                        display: true,
                        position: "left"
                    },
                    tooltips: {
                        callbacks: {
                            title: function (tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function (tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][Object.keys(dataset["_meta"])[0]]['total']) * 10000) / 100;
                                return percent + '% (' + humanizeDuration(dataset['data'][tooltipItem['index']] * 60 * 1000) + ')';
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
                    scale: {
                        gridLines: {
                            color: '#3f4145'
                        },
                        ticks: {
                            beginAtZero: true,
                            display: false,
                            userCallback: function (value, index, values) {
                                return humanizeDuration(value * 60 * 100);
                            },
                            max: chartMax
                        },
                        pointLabels: {
                            fontSize: 12
                        }
                    },
                    legend: {
                        display: false
                    },
                    tooltips: {
                        callbacks: {
                            title: function (tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function (tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / dataset['data'].reduce(function (a, b) {
                                    return a + b;
                                }, 0)) * 10000) / 100;
                                return percent + '% (' + humanizeDuration(dataset['data'][tooltipItem['index']] * 60 * 1000) + ')';
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
});