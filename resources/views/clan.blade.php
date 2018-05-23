@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">{{ $clan->name }}'s clan page</h2>
        </div>
    </div>
    @if ($clan->players->isEmpty())
        <section class="section-content">
            <div class="container-fluid">
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>This clan got abandoned!</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    @else
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
                                    <a href="{{ url("tee", urlencode($player->name)) }}"
                                       class="message d-flex align-items-center">
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
                                            <small class="date d-block">Last
                                                seen: {{ $player->last_seen }}</small>
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
                                    <a href="{{ url("tee", urlencode($clan->statsYoungestPlayer()->player->name)) }}"
                                       class="name">
                                        <strong class="d-block">{{ $clan->statsYoungestPlayer()->player->name }}</strong>
                                    </a>
                                </div>
                                <div class="col-lg-6 text-center">
                                    <div class="contributions">
                                        Joined: {{ $clan->statsYoungestPlayer()->joined_at }}</div>
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
                                    <a href="{{ url("tee", urlencode($clan->statsOldestPlayer()->player->name)) }}"
                                       class="name">
                                        <strong class="d-block">{{ $clan->statsOldestPlayer()->player->name }}</strong>
                                    </a>
                                </div>
                                <div class="col-lg-6 text-center">
                                    <div class="contributions">
                                        Joined: {{ $clan->statsOldestPlayer()->joined_at }}</div>
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
                                    <a href="{{ url("tee", urlencode($clan->statsMostActivePlayer()->name)) }}"
                                       class="name">
                                        <strong class="d-block">{{ $clan->statsMostActivePlayer()->name }}</strong>
                                    </a>
                                </div>
                                <div class="col-lg-6 text-center">
                                    <div class="contributions">
                                        Played: {{ $clan->statsMostActivePlayer()->totalHoursOnline(0, True) }}</div>
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
                                        <strong class="d-block">{{ $clan->mostPlayedMaps()->first()->map->map }}</strong>
                                    </a>
                                </div>
                                <div class="col-lg-6 text-center">
                                    <div class="contributions">
                                        Played: {{ $clan->humanizeDuration($clan->mostPlayedMaps()->first()->sum_minutes) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

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

                <!-- Tab panes -->
                <div class="tab-content">
                    <div class="tab-pane active" id="all">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="line-chart block chart">
                                    <div class="title"><strong>{{ $clan->name }}'s online probability</strong></div>
                                    <canvas id="onlineLineChartDays"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="line-chart block chart">
                                    <div class="title"><strong>{{ $clan->name }}'s online probability per day</strong>
                                    </div>
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
                    <div class="tab-pane fade" id="month">
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="line-chart block chart">
                                    <div class="title"><strong>{{ $clan->name }}'s online probability</strong></div>
                                    <canvas id="monthOnlineLineChartDays"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="line-chart block chart">
                                    <div class="title"><strong>{{ $clan->name }}'s online probability per day</strong>
                                    </div>
                                    <canvas id="monthOnlineLineChartHours"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="radar-chart chart block">
                                    <div class="title"><strong>{{ $clan->name }}'s most played mods</strong></div>
                                    <div class="radar-chart chart margin-bottom-sm">
                                        <canvas id="monthPlayedModsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="radar-chart chart block">
                                    <div class="title"><strong>{{ $clan->name }}'s most played maps</strong></div>
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
                                    <div class="title"><strong>{{ $clan->name }}'s online probability</strong></div>
                                    <canvas id="weekOnlineLineChartDays"></canvas>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="line-chart block chart">
                                    <div class="title"><strong>{{ $clan->name }}'s online probability per day</strong>
                                    </div>
                                    <canvas id="weekOnlineLineChartHours"></canvas>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-lg-6">
                                <div class="radar-chart chart block">
                                    <div class="title"><strong>{{ $clan->name }}'s most played mods</strong></div>
                                    <div class="radar-chart chart margin-bottom-sm">
                                        <canvas id="weekPlayedModsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                            <div class="col-lg-6">
                                <div class="radar-chart chart block">
                                    <div class="title"><strong>{{ $clan->name }}'s most played maps</strong></div>
                                    <div class="radar-chart chart margin-bottom-sm">
                                        <canvas id="weekPlayedMapsChart"></canvas>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @if (count($clan->exPlayers()) > 0)
                    <div class="row">
                        <div class="col-lg-12">
                            <div class="messages-block block">
                                <div class="title">
                                    <strong>Ex-Players</strong>
                                </div>
                                <div class="messages pre-scrollable pre-scrollable-needed">
                                    @foreach ($clan->exPlayers() as $exPlayer)
                                        <a href="{{ url("tee", urlencode($exPlayer->name)) }}"
                                           class="message d-flex align-items-center">
                                            <div class="profile">
                                                <img src="{{ asset('images/user.png') }}" alt="{{ $exPlayer->name }}"
                                                     class="img-fluid">
                                                @if ($exPlayer->online())
                                                    <div class="status online"></div>
                                                @else
                                                    <div class="status offline"></div>
                                                @endif
                                            </div>
                                            <div class="content">
                                                <strong class="d-block">{{ $exPlayer->name }}</strong>
                                                @if ($exPlayer->clan())
                                                    <span class="d-block">{{ $exPlayer->clan()->name }} </span>
                                                @endif
                                                <small class="date d-block">Joined: {{ $exPlayer->exClanRecord($clan)->first()->joined_at }}</small>
                                                <small class="date d-block">Left: {{ $exPlayer->exClanRecord($clan)->first()->left_at }}</small>
                                            </div>
                                        </a>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>
        </section>
    @endif
@endsection
@section('scripts')
    @if (!$clan->players->isEmpty())
        <script>
            $(document).ready(function () {

                // All-Time Records
                let playerCountriesChart = ChartHelper.pieChart($('#playerCountries'),
                    blade({!! json_encode(array_keys($clan->chartPlayerCountries())) !!}),
                    blade({!! json_encode(array_values($clan->chartPlayerCountries())) !!})
                );

                ChartHelper.lineChart($('#onlineLineChartDays'),
                    ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                    blade({!! json_encode(iterator_to_array($clan->chartOnlineDays())) !!})
                );

                ChartHelper.lineChart($('#onlineLineChartHours'),
                    ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                    blade({!! json_encode(iterator_to_array($clan->chartOnlineHours())) !!})
                );

                let playedMods = $('#playedModsChart');
                @if (count($clan->chartPlayedMods()) >= 3)
                    ChartHelper.radarChart(playedMods,
                        blade({!! json_encode(array_keys($clan->chartPlayedMods())) !!}),
                        blade({!! json_encode(array_values($clan->chartPlayedMods())) !!}),
                        blade({!! max(array_values($clan->chartPlayedMods())) !!}));
                @else
                    ChartHelper.pieChart(playedMods,
                        blade({!! json_encode(array_keys($clan->chartPlayedMods())) !!}),
                        blade({!! json_encode(array_values($clan->chartPlayedMods())) !!})
                    );
                @endif

                let playedMaps = ChartHelper.pieChart($('#playedMapsChart'),
                    blade({!! json_encode(array_keys($clan->chartPlayedMaps())) !!}),
                    blade({!! json_encode(array_values($clan->chartPlayedMaps()))  !!})
                );

                ChartHelper.chartColors(playedMaps, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
                ChartHelper.chartColors(playerCountriesChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});

                document.getElementById('toggle-month').onclick = function () {
                    // Monthly Records
                    ChartHelper.lineChart($('#monthOnlineLineChartDays'),
                        ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                        blade({!! json_encode(iterator_to_array($clan->chartOnlineDays(30))) !!})
                    );

                    ChartHelper.lineChart($('#monthOnlineLineChartHours'),
                        ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                        blade({!! json_encode(iterator_to_array($clan->chartOnlineHours(30))) !!})
                    );

                    let playedModsMonth = $('#monthPlayedModsChart');
                    @if (count($clan->chartPlayedMods(30)) >= 3)
                        ChartHelper.radarChart(playedModsMonth,
                            blade({!! json_encode(array_keys($clan->chartPlayedMods(30))) !!}),
                            blade({!! json_encode(array_values($clan->chartPlayedMods(30))) !!}),
                            blade({!! max(array_values($clan->chartPlayedMods(30))) !!}));
                    @else
                        ChartHelper.pieChart(playedModsMonth,
                            blade({!! json_encode(array_keys($clan->chartPlayedMods(30))) !!}),
                            blade({!! json_encode(array_values($clan->chartPlayedMods(30))) !!})
                        );
                    @endif

                    let playedMapsMonth = ChartHelper.pieChart($('#monthPlayedMapsChart'),
                        blade({!! json_encode(array_keys($clan->chartPlayedMaps(30))) !!}),
                        blade({!! json_encode(array_values($clan->chartPlayedMaps(30)))  !!})
                    );

                    ChartHelper.chartColors(playedMapsMonth, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
                };

                document.getElementById('toggle-week').onclick = function () {
                    // Weekly Records
                    ChartHelper.lineChart($('#weekOnlineLineChartDays'),
                        ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                        blade({!! json_encode(iterator_to_array($clan->chartOnlineDays(7))) !!})
                    );

                    ChartHelper.lineChart($('#weekOnlineLineChartHours'),
                        ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                        blade({!! json_encode(iterator_to_array($clan->chartOnlineHours(7))) !!})
                    );

                    let playedModsWeek = $('#weekPlayedModsChart');
                    @if (count($clan->chartPlayedMods(7)) >= 3)
                        ChartHelper.radarChart(playedModsWeek,
                            blade({!! json_encode(array_keys($clan->chartPlayedMods(7))) !!}),
                            blade({!! json_encode(array_values($clan->chartPlayedMods(7))) !!}),
                            blade({!! max(array_values($clan->chartPlayedMods(7))) !!}));
                    @else
                        ChartHelper.pieChart(playedModsWeek,
                            blade({!! json_encode(array_keys($clan->chartPlayedMods(7))) !!}),
                            blade({!! json_encode(array_values($clan->chartPlayedMods(7))) !!})
                        );
                    @endif

                    let playedMapsWeek = ChartHelper.pieChart($('#weekPlayedMapsChart'),
                        blade({!! json_encode(array_keys($clan->chartPlayedMaps(7))) !!}),
                        blade({!! json_encode(array_values($clan->chartPlayedMaps(7)))  !!})
                    );

                    ChartHelper.chartColors(playedMapsWeek, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
                };
            });
        </script>
    @endif
@endsection