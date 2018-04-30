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
                                        <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}" class="img-fluid">
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
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..." class="img-fluid"></div>
                                <a href="#" class="name">
                                    <strong class="d-block">Jumping the gun (ToDo)</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Joined: 2018-04-24 08:52:14 (ToDo)</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Oldest member:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..." class="img-fluid"></div>
                                <a href="#" class="name">
                                    <strong class="d-block">Gunning the jump (ToDo)</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Joined: 2009-04-24 08:52:14 (ToDo)</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Most active member:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..." class="img-fluid"></div>
                                <a href="#" class="name">
                                    <strong class="d-block">meter'. (ToDo)</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Played: 8.164 hours (ToDo)</div>
                            </div>
                        </div>
                    </div>
                    <div class="public-user-block block">
                        <div class="row d-flex align-items-center">
                            <div class="col-lg-2 d-flex align-items-center">
                                <div class="order">Most played map:</div>
                            </div>
                            <div class="col-lg-4 d-flex align-items-center">
                                <div class="avatar"><img src="{{ asset('images/user.png') }}" alt="..." class="img-fluid"></div>
                                <a href="#" class="name">
                                    <strong class="d-block">meter'. (ToDo)</strong>
                                </a>
                            </div>
                            <div class="col-lg-6 text-center">
                                <div class="contributions">Played: 8.164 hours (ToDo)</div>
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
