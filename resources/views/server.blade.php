@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">{{ $server->name }}'s page</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            @if ($server->currentPlayers)
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>Currently playing tees</strong>
                            </div>
                            <div class="messages pre-scrollable pre-scrollable-needed">
                                @foreach ($server->currentPlayers as $player)
                                    <a href="{{ url("tee", urlencode($player->name)) }}"
                                       class="message d-flex align-items-center">
                                        <div class="profile">
                                            <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}"
                                                 class="img-fluid">
                                            <div class="status online"></div>
                                        </div>
                                        <div class="content">
                                            <strong class="d-block">{{ $player->name }}</strong>
                                            @if ($player->clan())
                                                <span class="d-block">{{ $player->clan()->name }} </span>
                                            @endif
                                            <small class="date d-block">{{ $player->last_seen }}</small>
                                        </div>
                                    </a>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            @endif
            <div class="row">
                <div class="col-lg-6">
                    <div class="line-chart block chart">
                        <div class="title"><strong>{{ $server->name }}'s online probability</strong></div>
                        <canvas id="onlineLineChartDays"></canvas>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="line-chart block chart">
                        <div class="title"><strong>{{ $server->name }}'s online probability per day</strong></div>
                        <canvas id="onlineLineChartHours"></canvas>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-6">
                    <div class="pie-chart chart block">
                        <div class="title"><strong>{{ $server->name }}'s most played maps</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="serverMaps"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="pie-chart chart block">
                        <div class="title"><strong>{{ $server->name }}'s most played countries</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="serverCountries"></canvas>
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

            ChartHelper.lineChart($('#onlineLineChartDays'),
                ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                blade({!! json_encode($server->chartOnlineDays()) !!})
            );

            ChartHelper.lineChart($('#onlineLineChartHours'),
                ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                blade({!! json_encode($server->chartOnlineHours()) !!})
            );

            let playedMaps = ChartHelper.pieChart($('#serverMaps'),
                blade({!! json_encode(array_keys($server->chartPlayedMaps())) !!}),
                blade({!! json_encode(array_values($server->chartPlayedMaps()))  !!})
            );

            let playerCountriesChart = ChartHelper.pieChart($('#serverCountries'),
                blade({!! json_encode(array_keys($server->chartPlayerCountries())) !!}),
                blade({!! json_encode(array_values($server->chartPlayerCountries())) !!})
            );

            ChartHelper.chartColors(playedMaps, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});
            ChartHelper.chartColors(playerCountriesChart, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});

        });
    </script>
@endsection