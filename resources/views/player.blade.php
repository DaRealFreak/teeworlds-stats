@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">{{ $player->name }}'s Statistics
                @if ($player->clan())
                    - [{{ $player->clan()->name }}]
                @endif
            </h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <!-- Nav tabs -->
            <ul class="nav nav-tabs nav-justified">
                <li class="nav-item">
                    <a class="nav-link active" data-toggle="tab" href="#all">All-Time</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#week">This week</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" data-toggle="tab" href="#day">Today</a>
                </li>
            </ul>

            <!-- Tab panes -->
            <div class="tab-content">
                <div class="tab-pane active" id="all">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability</strong></div>
                                <canvas id="onlineLineChartDays"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability per day</strong>
                                </div>
                                <canvas id="onlineLineChartHours"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played mods</strong></div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="playedModsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played maps</strong></div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="playedMapsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane container fade" id="week">

                </div>
                <div class="tab-pane container fade" id="day">

                </div>
            </div>
        </div>
    </section>
    <section class="section-content">
        <div class="container-fluid">

        </div>
    </section>
@endsection
@section('scripts')
    <script>
        $(document).ready(function () {

            'use strict';

            Chart.defaults.global.defaultFontColor = '#75787c';

            // ------------------------------------------------------- //
            // Online probability days
            // ------------------------------------------------------ //
            new Chart($('#onlineLineChartDays'), {
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
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function (tooltipItem, data) {
                                return 'Possibility: ' + data['datasets'][0]['data'][tooltipItem['index']] + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
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
                            data: {!! json_encode(iterator_to_array($player->stats()->first()->chartOnlineDays())) !!},
                            spanGaps: false
                        }
                    ]
                }
            });

            // ------------------------------------------------------- //
            // Online probability hours
            // ------------------------------------------------------ //
            new Chart($('#onlineLineChartHours'), {
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
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function (tooltipItem, data) {
                                return 'Possibility: ' + data['datasets'][0]['data'][tooltipItem['index']] + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM",
                        "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
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
                            data: {!! json_encode(iterator_to_array($player->stats()->first()->chartOnlineHours())) !!},
                            spanGaps: false
                        }
                    ]
                }
            });

            let playedMods = $('#playedModsChart');
            @if (count($player->chartPlayedMods()) >= 3)
                new Chart(playedMods, {
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
                                max: {!! max(array_values($player->chartPlayedMods())) !!}
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
                        labels: {!! json_encode(array_keys($player->chartPlayedMods())) !!},
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
                                data: {!! json_encode(array_values($player->chartPlayedMods())) !!}
                            }
                        ]
                    }
                });
            @else
                new Chart(playedMods, {
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
                        labels: {!! json_encode(array_keys($player->chartPlayedMods())) !!},
                        datasets: [
                            {
                                data: {!! json_encode(array_values($player->chartPlayedMods())) !!},
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
            @endif

            // ------------------------------------------------------- //
            // Played maps pie chart for less than 3 played maps
            // ------------------------------------------------------ //
            let playedMaps = new Chart($('#playedMapsChart'), {
                type: 'pie',
                options: {
                    legend: {
                        display: true,
                        position: "right"
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
                    labels: {!! json_encode(array_keys($player->chartPlayedMaps())) !!},
                    datasets: [
                        {
                            data: {!! json_encode(array_values($player->chartPlayedMaps()))  !!},
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
            ChartHelper.chartColors(playedMaps, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
        });

    </script>
@endsection
