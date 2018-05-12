@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">{{ $clan->name }}'s clan page</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="messages-block block">
                        <div class="title">
                            <strong>Players</strong>
                        </div>
                        <div class="messages pre-scrollable pre-scrollable-needed">
                            @foreach ($clan->players as $player)
                                <a href="{{ url("tee", urlencode($player->name)) }}" class="message d-flex align-items-center">
                                    <div class="profile">
                                        <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}"
                                             class="img-fluid">
                                        @if ($player->online())
                                            <div class="status online"></div>
                                        @else
                                            <div class="status offline"></div>
                                        @endif
                                    </div>
                                    <div class="content">
                                        <strong class="d-block">{{ $player->name }}</strong>
                                        <span class="d-block">{{ $clan->name }} </span>
                                        <small class="date d-block">Last seen: {{ $player->last_seen }}</small>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="pie-chart chart block">
                        <div class="title"><strong>{{ $clan->name }}'s player countries</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="playerCountries"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Newest member:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..."
                                                         class="img-fluid"></div>
                                <a href="{{ url("tee", urlencode($clan->statsYoungestPlayer()->name)) }}" class="name">
                                    <strong class="d-block">{{ $clan->statsYoungestPlayer()->name }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">
                                    Joined: {{ $clan->statsYoungestPlayer()->clan_joined_at }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Oldest member:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..."
                                                         class="img-fluid"></div>
                                <a href="{{ url("tee", urlencode($clan->statsOldestPlayer()->name)) }}" class="name">
                                    <strong class="d-block">{{ $clan->statsOldestPlayer()->name }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">
                                    Joined: {{ $clan->statsOldestPlayer()->clan_joined_at }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Most active member:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..."
                                                         class="img-fluid"></div>
                                <a href="{{ url("tee", urlencode($clan->statsMostActivePlayer()->name)) }}" class="name">
                                    <strong class="d-block">{{ $clan->statsMostActivePlayer()->name }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">
                                    Played: {{ $clan->statsMostActivePlayer()->stats()->first()->totalHoursPlayed(True) }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Most played map:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/teehut.png') }}" alt="..."
                                                         class="img-fluid"></div>
                                <a href="#" class="name">
                                    <strong class="d-block">{{ $clan->chartMostPlayedMaps()->first()->map }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">
                                    Played: {{ $clan->humanizeDuration($clan->chartMostPlayedMaps()->first()->sum_minutes) }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="line-chart block chart">
                        <div class="title"><strong>{{ $clan->name }}'s online probability</strong></div>
                        <canvas id="onlineLineChartDays"></canvas>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="line-chart block chart">
                        <div class="title"><strong>{{ $clan->name }}'s online probability per day</strong></div>
                        <canvas id="onlineLineChartHours"></canvas>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="radar-chart chart block">
                        <div class="title"><strong>{{ $clan->name }}'s most played mods</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="playedModsChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="radar-chart chart block">
                        <div class="title"><strong>{{ $clan->name }}'s most played maps</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="playedMapsChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
@section('scripts')
    <script>
        $(document).ready(function () {

            'use strict';

            Chart.defaults.global.defaultFontColor = '#75787c';

            let playedMods = $('#playedModsChart');
            @if (count($clan->chartPlayedMods()) >= 3)
                // ------------------------------------------------------- //
                // Played mods radar chart
                // ------------------------------------------------------ //
                new Chart(playedMods, {
                    type: 'radar',
                    options: {
                        scale: {
                            gridLines: {
                                color: '#3f4145'
                            },
                            ticks: {
                                beginAtZero: true,
                                maxTicksLimit: 3,
                                display: false,
                                userCallback: function (value, index, values) {
                                    return humanizeDuration(value * 60 * 100);
                                }
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
                        labels: {!! json_encode(array_keys($clan->chartPlayedMods())) !!},
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
                                data: {!! json_encode(array_values($clan->chartPlayedMods())) !!}
                            }
                        ]
                    }
                });
            @else
                // ------------------------------------------------------- //
                // Played mods pie chart for less than 3 played mods
                // ------------------------------------------------------ //
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
                        labels: {!! json_encode(array_keys($clan->chartPlayedMods())) !!},
                        datasets: [
                            {
                                data: {!! json_encode(array_values($clan->chartPlayedMods())) !!},
                                borderWidth: 0,
                                backgroundColor: [
                                    '#723ac3',
                                    "#864DD9",
                                    "#9762e6",
                                ],
                                hoverBackgroundColor: [
                                    '#723ac3',
                                    "#864DD9",
                                    "#9762e6",
                                ]
                            }]
                    }
                });
            @endif


            // ------------------------------------------------------- //
            // Clan played mods pie chart
            // ------------------------------------------------------ //
            let playedMapsChart = new Chart($('#playedMapsChart'), {
                type: 'pie',
                options: {
                    legend: {
                        display: true,
                        position: "right",
                        responsive: false
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
                    labels: {!! json_encode(array_keys($clan->chartPlayedMaps())) !!},
                    datasets: [
                        {
                            data: {!! json_encode(array_values($clan->chartPlayedMaps())) !!},
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

            // ------------------------------------------------------- //
            // Clan player countries pie chart
            // ------------------------------------------------------ //
            let playerCountriesChart = new Chart($('#playerCountries'), {
                type: 'pie',
                options: {
                    legend: {
                        display: true,
                        position: "right",
                        responsive: false
                    },
                    tooltips: {
                        callbacks: {
                            title: function (tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function (tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][Object.keys(dataset["_meta"])[0]]['total']) * 10000) / 100;
                                return percent + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: {!! json_encode(array_keys($clan->chartPlayerCountries())) !!},
                    datasets: [
                        {
                            data: {!! json_encode(array_values($clan->chartPlayerCountries())) !!},
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

            // ------------------------------------------------------- //
            // Online probability days
            // ------------------------------------------------------ //
            new Chart($('#onlineLineChartDays'), {
                type: 'line',
                options: {
                    legend: {
                        labels: {
                            fontColor: "#777",
                            fontSize: 12,
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
                            data: {!! json_encode(iterator_to_array($clan->chartOnlineDays())) !!},
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
                            data: {!! json_encode(iterator_to_array($clan->chartOnlineHours())) !!},
                            spanGaps: false
                        }
                    ]
                }
            });

            ChartHelper.chartColors(playedMapsChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            ChartHelper.chartColors(playerCountriesChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
        });
    </script>
@endsection