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
                    <a class="nav-link active" id="toggle-all" data-toggle="tab" href="#all">All-Time</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="toggle-month" data-toggle="tab" href="#month">This month</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="toggle-week" data-toggle="tab" href="#week">This week</a>
                </li>
            </ul>
            
            @if ($player->clanRecords()->count() >= 1)
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>Clan History</strong>
                            </div>
                            <div class="messages pre-scrollable pre-scrollable-needed">
                                @foreach ($player->clanRecords()->get() as $clanRecord)
                                    <a href="{{ url("clan", urlencode($clanRecord->clan->name)) }}"
                                       class="message d-flex align-items-center">
                                        <div class="profile">
                                            <img src="{{ asset('images/user.png') }}" alt="{{ $clanRecord->clan->name }}"
                                                 class="img-fluid">
                                        </div>
                                        <div class="content">
                                            <strong class="d-block">{{ $clanRecord->clan->name }}</strong>
                                            <small class="date d-block">Joined: {{ $clanRecord->joined_at }}</small>
                                            @if ($clanRecord->left_at)
                                                <small class="date d-block">Left: {{ $clanRecord->left_at }}</small>
                                            @endif
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif

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
                <div class="tab-pane fade" id="month">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability this month</strong>
                                </div>
                                <canvas id="monthOnlineLineChartDays"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability per day this
                                        month</strong>
                                </div>
                                <canvas id="monthOnlineLineChartHours"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played mods this month</strong>
                                </div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="monthPlayedModsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played maps this month</strong>
                                </div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="monthPlayedMapsChart"></canvas>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="week">
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability this week</strong>
                                </div>
                                <canvas id="weekOnlineLineChartDays"></canvas>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="line-chart block chart">
                                <div class="title"><strong>{{ $player->name }}'s online probability per day this
                                        week</strong>
                                </div>
                                <canvas id="weekOnlineLineChartHours"></canvas>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played mods this week</strong>
                                </div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="weekPlayedModsChart"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-6">
                            <div class="radar-chart chart block">
                                <div class="title"><strong>{{ $player->name }}'s most played maps this week</strong>
                                </div>
                                <div class="radar-chart chart margin-bottom-sm">
                                    <canvas id="weekPlayedMapsChart"></canvas>
                                </div>
                            </div>
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
            // All-Time Records
            ChartHelper.lineChart($('#onlineLineChartDays'),
                ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                blade({!! json_encode($player->chartOnlineDays()) !!})
            );

            ChartHelper.lineChart($('#onlineLineChartHours'),
                ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                blade({!! json_encode($player->chartOnlineHours()) !!})
            );

            let playedMods = $('#playedModsChart');
            @if (count($player->chartPlayedMods()) >= 3)
                ChartHelper.radarChart(playedMods,
                    blade({!! json_encode(array_keys($player->chartPlayedMods())) !!}),
                    blade({!! json_encode(array_values($player->chartPlayedMods())) !!}),
                    blade({!! max(array_values($player->chartPlayedMods())) !!}));
            @else
                ChartHelper.pieChart(playedMods,
                    blade({!! json_encode(array_keys($player->chartPlayedMods())) !!}),
                    blade({!! json_encode(array_values($player->chartPlayedMods())) !!})
                );
            @endif

            let playedMaps = ChartHelper.pieChart($('#playedMapsChart'),
                blade({!! json_encode(array_keys($player->chartPlayedMaps())) !!}),
                blade({!! json_encode(array_values($player->chartPlayedMaps()))  !!})
            );

            ChartHelper.chartColors(playedMaps, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});

            document.getElementById('toggle-month').onclick = function () {
                // Monthly Records
                ChartHelper.lineChart($('#monthOnlineLineChartDays'),
                    ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                    blade({!! json_encode($player->chartOnlineDays(30)) !!})
                );

                ChartHelper.lineChart($('#monthOnlineLineChartHours'),
                    ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                    blade({!! json_encode($player->chartOnlineHours(30)) !!})
                );

                let playedModsMonth = $('#monthPlayedModsChart');
                @if (count($player->chartPlayedMods(30)) >= 3)
                    ChartHelper.radarChart(playedModsMonth,
                        blade({!! json_encode(array_keys($player->chartPlayedMods(30))) !!}),
                        blade({!! json_encode(array_values($player->chartPlayedMods(30))) !!}),
                        blade({!! max(array_values($player->chartPlayedMods(30))) !!}));
                @else
                    ChartHelper.pieChart(playedModsMonth,
                        blade({!! json_encode(array_keys($player->chartPlayedMods(30))) !!}),
                        blade({!! json_encode(array_values($player->chartPlayedMods(30))) !!})
                    );
                @endif

                let playedMapsMonth = ChartHelper.pieChart($('#monthPlayedMapsChart'),
                    blade({!! json_encode(array_keys($player->chartPlayedMaps(30))) !!}),
                    blade({!! json_encode(array_values($player->chartPlayedMaps(30)))  !!})
                );

                ChartHelper.chartColors(playedMapsMonth, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            };

            document.getElementById('toggle-week').onclick = function () {
                // Weekly Records
                ChartHelper.lineChart($('#weekOnlineLineChartDays'),
                    ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                    blade({!! json_encode($player->chartOnlineDays(7)) !!})
                );

                ChartHelper.lineChart($('#weekOnlineLineChartHours'),
                    ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                    blade({!! json_encode($player->chartOnlineHours(7)) !!})
                );

                let playedModsWeek = $('#weekPlayedModsChart');
                @if (count($player->chartPlayedMods(7)) >= 3)
                    ChartHelper.radarChart(playedModsWeek,
                        blade({!! json_encode(array_keys($player->chartPlayedMods(7))) !!}),
                        blade({!! json_encode(array_values($player->chartPlayedMods(7))) !!}),
                        blade({!! max(array_values($player->chartPlayedMods(7))) !!}));
                @else
                    ChartHelper.pieChart(playedModsWeek,
                        blade({!! json_encode(array_keys($player->chartPlayedMods(7))) !!}),
                        blade({!! json_encode(array_values($player->chartPlayedMods(7))) !!})
                    );
                @endif

                let playedMapsWeek = ChartHelper.pieChart($('#weekPlayedMapsChart'),
                    blade({!! json_encode(array_keys($player->chartPlayedMaps(7))) !!}),
                    blade({!! json_encode(array_values($player->chartPlayedMaps(7)))  !!})
                );

                ChartHelper.chartColors(playedMapsWeek, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            };

        });

    </script>
@endsection
