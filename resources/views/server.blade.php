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
            @if ($server->players)
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>Currently playing tees</strong>
                            </div>
                            <div class="messages pre-scrollable pre-scrollable-needed">
                                @foreach ($server->players as $player)
                                    <a href="{{ url("tee", urlencode($player->name)) }}"
                                       class="message d-flex align-items-center">
                                        <div class="profile">
                                            <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}"
                                                 class="img-fluid">
                                            <div class="status online"></div>
                                        </div>
                                        <div class="content">
                                            <strong class="d-block">{{ $player->name }}</strong>
                                            <span class="d-block">{{ $player->clan()->name }} </span>
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
                    <div class="pie-chart chart block">
                        <div class="title"><strong>{{ $server->name }}'s player maps</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="serverMaps"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="pie-chart chart block">
                        <div class="title"><strong>{{ $server->name }}'s player countries</strong></div>
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="serverCountries"></canvas>
                        </div>
                    </div>
                </div>
            </div>
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
        </div>
    </section>
@endsection
