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
            @if ($server->currentPlayers->count())
                @php $playerCount = $server->currentPlayers->count(); @endphp
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>Currently playing tees</strong>
                            </div>
                            {{-- flow into columns once the roster is long, so it doesn't run off the page --}}
                            <div class="messages pre-scrollable pre-scrollable-needed @if ($playerCount > 12) messages--grid @endif">
                                @foreach ($server->currentPlayers as $player)
                                    @php
                                        $playerTee = \App\Utility\TeeSkin::describe($player->skin, $player->color_body, $player->color_feet, $player->skin_parts);
                                    @endphp
                                    <a href="{{ url("tee", urlencode($player->name)) }}"
                                       class="message d-flex align-items-center">
                                        <div class="profile">
                                            @if ($playerTee)
                                                <canvas class="profile-tee" width="50" height="50" data-tee='@json($playerTee)'></canvas>
                                            @else
                                                <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}"
                                                     class="img-fluid">
                                            @endif
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
                    @php $countryStats = $server->playingCountries(8); @endphp
                    <div class="block chart">
                        <div class="title"><strong>{{ $server->name }}'s most played countries</strong></div>
                        @if (count($countryStats['countries']) || $countryStats['unknown'] > 0)
                            @include('partials.countries', ['countryStats' => $countryStats, 'canvasId' => 'serverCountries'])
                        @else
                            <p class="text-small" style="color: #75787f">No player country data yet.</p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            ChartHelper.lineChart(document.getElementById('onlineLineChartDays'),
                ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"],
                blade({!! json_encode($server->chartOnlineDays()) !!})
            );

            ChartHelper.lineChart(document.getElementById('onlineLineChartHours'),
                ["12 AM", "1 AM", "2 AM", "3 AM", "4 AM", "5 AM", "6 AM", "7 AM", "8 AM", "9 AM", "10 AM", "11 AM", "12 PM", "13 PM", "14 PM", "15 PM", "16 PM", "17 PM", "18 PM", "19 PM", "20 PM", "21 PM", "22 PM", "23 PM"],
                blade({!! json_encode($server->chartOnlineHours()) !!})
            );

            let playedMaps = ChartHelper.pieChart(document.getElementById('serverMaps'),
                blade({!! json_encode(array_keys($server->chartPlayedMaps())) !!}),
                blade({!! json_encode(array_values($server->chartPlayedMaps()))  !!})
            );

            ChartHelper.chartColors(playedMaps, {0: [117, 46, 224, 1], 100: [166, 120, 235, 1]});

            @if (count($countryStats['countries']))
                ChartHelper.countryDoughnut(document.getElementById('serverCountries'),
                    {!! json_encode(array_column($countryStats['countries'], 'name')) !!},
                    {!! json_encode(array_column($countryStats['countries'], 'count')) !!}
                );
            @endif

        });
    </script>
@endsection