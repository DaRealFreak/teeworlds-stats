@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">General statistics</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-6">
                    <div class="statistic-block block">
                        <div class="progress-details d-flex align-items-end justify-content-between">
                            <div class="title">
                                <div class="icon">
                                    <i class="icon-user-outline"></i>
                                </div>
                                <strong>Online tees</strong>
                            </div>
                            <div class="number dashtext-2">{{ $general['online'] }}</div>
                        </div>
                        <div class="progress progress-template">
                            <div role="progressbar" style="width: 70%" aria-valuenow="70" aria-valuemin="0"
                                 aria-valuemax="100" class="progress-bar progress-bar-template dashbg-2"></div>
                        </div>
                    </div>
                    <div class="statistic-block block">
                        <div class="progress-details d-flex align-items-end justify-content-between">
                            <div class="title">
                                <div class="icon">
                                    <i class="icon-user-outline"></i>
                                </div>
                                <strong>New tees in the last 24 hours</strong>
                            </div>
                            <div class="number dashtext-1">27</div>
                        </div>
                        <div class="progress progress-template">
                            <div role="progressbar" style="width: 30%" aria-valuenow="30" aria-valuemin="0"
                                 aria-valuemax="100" class="progress-bar progress-bar-template dashbg-1"></div>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <tbody>
                                <tr>
                                    <th scope="row">Tees</th>
                                    <td>{{ $general['players'] }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Servers</th>
                                    <td>{{ $general['servers'] }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Countries</th>
                                    <td>{{ $general['countries'] }}</td>
                                </tr>
                                <tr>
                                    <th scope="row">Mods</th>
                                    <td>{{ $general['mods'] }}</td>
                                </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="line-chart block chart">
                        <div class="title"><strong>Most playing countries</strong></div>
                        <canvas id="playedCountries"></canvas>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="radar-chart chart block">
                        <div class="title"><strong>Most played mods</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="playedMods"></canvas>
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

            let playedMods = $('#playedMods');
            @if (count($chartPlayedMods) >= 3)
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
                            min: 0,
                            max: {!! json_encode(max($chartPlayedMods)) !!},
                            stepSize: 20
                        },
                        pointLabels: {
                            fontSize: 12
                        }
                    },
                    legend: {
                        position: 'left'
                    },
                    tooltips: {
                        callbacks: {
                            title: function(tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function(tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let sum = dataset['data'].reduce(function(a, b) { return a + b; }, 0);
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / sum) * 100);
                                return percent + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: {!! json_encode(array_keys($chartPlayedMods)) !!},
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
                            data: {!! json_encode(array_values($chartPlayedMods)) !!}
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
                            title: function(tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function(tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][Object.keys(dataset["_meta"])[0]]['total']) * 100);
                                return percent + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: {!! json_encode(array_keys($chartPlayedMods)) !!},
                    datasets: [
                        {
                            data: {!! json_encode(array_values($chartPlayedMods)) !!},
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
            // Player countries pie chart
            // ------------------------------------------------------ //

            let playedCountryChart = new Chart($('#playedCountries'), {
                type: 'pie',
                options: {
                    legend: {
                        display: true,
                        position: "right",
                        responsive: false
                    },
                    tooltips: {
                        callbacks: {
                            title: function(tooltipItem, data) {
                                return data['labels'][tooltipItem[0]['index']];
                            },
                            label: function(tooltipItem, data) {
                                let dataset = data['datasets'][0];
                                let percent = Math.round((dataset['data'][tooltipItem['index']] / dataset["_meta"][Object.keys(dataset["_meta"])[0]]['total']) * 100);
                                return percent + '%';
                            },
                        },
                    }
                },
                data: {
                    labels: {!! json_encode(array_keys($chartPlayedCountries)) !!},
                    datasets: [
                        {
                            data: {!! json_encode(array_values($chartPlayedCountries)) !!},
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

            ChartHelper.chartColors(playedCountryChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});

        });
    </script>
@endsection