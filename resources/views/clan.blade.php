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
                                <a href="{{ url("tee/" . $player->name) }}" class="message d-flex align-items-center">
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
                                        <small class="date d-block">{{ $player->updated_at }}</small>
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
                                <a href="#" class="name">
                                    <strong class="d-block">{{ $clan->statsYoungestPlayer()->name }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Joined: {{ $clan->statsYoungestPlayer()->created_at }}</div>
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
                                <a href="#" class="name">
                                    <strong class="d-block">{{ $clan->statsOldestPlayer()->name }}</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Joined: {{ $clan->statsOldestPlayer()->created_at }}</div>
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
                                <a href="#" class="name">
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
                                    Played: {{ $clan->humanizeDuration($clan->chartMostPlayedMaps()->first()->sum_times) }}</div>
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

            // ------------------------------------------------------- //
            // Clan played maps pie chart
            // ------------------------------------------------------ //
            let playedModsChart = new Chart($('#playedModsChart'), {
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
                            hoverBackgroundColor: '#4313a0',
                        }]
                }
            });

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

            ChartHelper.chartColors(playedModsChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            ChartHelper.chartColors(playedMapsChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            ChartHelper.chartColors(playerCountriesChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
        });
    </script>
@endsection