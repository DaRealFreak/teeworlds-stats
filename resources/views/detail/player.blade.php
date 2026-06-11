@extends('layouts.app')

@php
    use App\TwStats\Utility\Countries;
    use App\Utility\ChartUtility;
    use Carbon\Carbon;

    // resolved once: each call queries the clan history relation
    $clan = $player->clan();
    $flag = Countries::getFlagCode($player->country);
    $isOnline = $player->online();
    $currentSession = $player->currentSession();
    $lastSeen = Carbon::parse($player->last_seen);
    $humanize = fn ($minutes) => ChartUtility::humanizeDuration((int) $minutes);

    $weekdayLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $weekdayNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    $busiestWeekday = $player->busiestWeekday();
    $busiestHour = $player->busiestHour();

    $heatmap = $player->chartOnlineHeatmap();
    $heatmapMax = $heatmap['max'] ?: 1;

    $topMaps = $player->chartPlayedMaps(0, 6, false);
    arsort($topMaps);
    $topMapsMax = $topMaps ? max($topMaps) : 1;

    // mods keep the radar/pie chart used across the rest of the site
    $modsChart = $player->chartPlayedMods();

    $favoriteServers = $player->favoriteServers(5);
    $favoriteServersMax = $favoriteServers->max('sum_minutes') ?: 1;

    $recentSessions = $player->recentSessions(8);
@endphp

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">{{ $player->name }}'s Statistics
                @if ($clan)
                    - [{{ $clan->name }}]
                @endif
            </h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">

            <!-- Identity / KPI header -->
            <div class="player-hero">
                <img src="{{ asset('images/user.png') }}" alt="{{ $player->name }}" class="player-hero__avatar">
                <div class="player-hero__id">
                    <div class="player-hero__name">
                        @if ($flag)
                            <span class="fi fi-{{ $flag }}" title="{{ $player->country }}"></span>
                        @endif
                        {{ $player->name }}
                        @if ($clan)
                            <a class="player-hero__clan" href="{{ url('clan', urlencode($clan->name)) }}">[{{ $clan->name }}]</a>
                        @endif
                    </div>
                    <div class="player-hero__sub">
                        {{ $player->country !== 'none' ? $player->country : 'Unknown country' }}
                        @if ($player->created_at)
                            · tracked since {{ $player->created_at->format('M Y') }}
                        @endif
                        · {{ $humanize($player->totalHoursOnline()) }} played
                    </div>
                </div>
                <div>
                    @if ($isOnline)
                        <span class="player-hero__status player-hero__status--online">
                            <span class="dot"></span> Online now
                        </span>
                        @if ($currentSession && $currentSession->server)
                            <div class="player-hero__sub">
                                on
                                <a class="player-hero__clan"
                                   href="{{ url('server', [urlencode($currentSession->server->id), urlencode($currentSession->server->name)]) }}">{{ $currentSession->server->name }}</a>
                            </div>
                        @endif
                    @else
                        <span class="player-hero__status player-hero__status--offline">
                            <span class="dot"></span> Last seen {{ $lastSeen->diffForHumans() }}
                        </span>
                    @endif
                </div>
            </div>

            <!-- Summary tiles -->
            <div class="stat-tiles">
                <div class="stat-tile">
                    <div class="stat-tile__label">Total time</div>
                    <div class="stat-tile__value"><i class="fa fa-clock-o"></i>{{ $humanize($player->totalHoursOnline()) }}</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile__label">Sessions</div>
                    <div class="stat-tile__value"><i class="fa fa-history"></i>{{ number_format($player->totalSessions()) }}</div>
                    @if ($player->totalSessions() > 0)
                        <div class="stat-tile__hint">avg {{ $humanize($player->averageSessionMinutes()) }} · longest {{ $humanize($player->longestSessionMinutes()) }}</div>
                    @endif
                </div>
                <div class="stat-tile">
                    <div class="stat-tile__label">Servers</div>
                    <div class="stat-tile__value"><i class="fa fa-server"></i>{{ number_format($player->distinctServersCount()) }}</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile__label">Maps</div>
                    <div class="stat-tile__value"><i class="fa fa-map-marker"></i>{{ number_format($player->distinctMapsCount()) }}</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile__label">Mods</div>
                    <div class="stat-tile__value"><i class="fa fa-gamepad"></i>{{ number_format($player->distinctModsCount()) }}</div>
                </div>
                <div class="stat-tile">
                    <div class="stat-tile__label">Most active</div>
                    <div class="stat-tile__value" style="font-size: 1.15rem">
                        <i class="fa fa-bolt"></i>{{ $busiestWeekday !== null ? $weekdayNames[$busiestWeekday] : '—' }}
                    </div>
                    @if ($busiestHour !== null)
                        <div class="stat-tile__hint">around {{ sprintf('%02d:00', $busiestHour) }}</div>
                    @endif
                </div>
            </div>

            <!-- Activity heatmap -->
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>When {{ $player->name }} plays</strong>
                            <span>weekday × hour, by total time online</span>
                        </div>
                        <div class="heatmap">
                            <div class="heatmap__grid">
                                <span></span>
                                @for ($hour = 0; $hour < 24; $hour++)
                                    <span class="heatmap__collabel">{{ $hour % 3 === 0 ? $hour : '' }}</span>
                                @endfor
                                @foreach ($weekdayLabels as $weekdayIndex => $weekdayLabel)
                                    <span class="heatmap__rowlabel">{{ $weekdayLabel }}</span>
                                    @for ($hour = 0; $hour < 24; $hour++)
                                        @php
                                            $cellMinutes = $heatmap['matrix'][$weekdayIndex][$hour];
                                            $alpha = $cellMinutes > 0 ? round(0.14 + ($cellMinutes / $heatmapMax) * 0.86, 2) : 0;
                                        @endphp
                                        <span class="heatmap__cell"
                                              @if ($cellMinutes > 0) style="background: rgba(219, 101, 116, {{ $alpha }})" @endif
                                              title="{{ $weekdayNames[$weekdayIndex] }} {{ sprintf('%02d:00', $hour) }} — {{ $humanize($cellMinutes) }}"></span>
                                    @endfor
                                @endforeach
                            </div>
                            <div class="heatmap__legend">
                                Less
                                <i style="background: rgba(219, 101, 116, 0.2)"></i>
                                <i style="background: rgba(219, 101, 116, 0.45)"></i>
                                <i style="background: rgba(219, 101, 116, 0.7)"></i>
                                <i style="background: rgba(219, 101, 116, 1)"></i>
                                More
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent sessions + favorite servers -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong>Recent sessions</strong></div>
                        @if ($recentSessions->count())
                            <ul class="session-list">
                                @foreach ($recentSessions as $session)
                                    <li class="session-item">
                                        <div class="session-item__when">
                                            <div class="session-item__date">{{ $session->started_at->format('d M') }}</div>
                                            <div class="session-item__time">{{ $session->started_at->format('H:i') }}</div>
                                        </div>
                                        <div class="session-item__body">
                                            @if ($session->server)
                                                <a class="session-item__server"
                                                   href="{{ url('server', [urlencode($session->server->id), urlencode($session->server->name)]) }}">{{ $session->server->name }}</a>
                                            @else
                                                <span class="session-item__server">Unknown server</span>
                                            @endif
                                            <div class="session-item__meta">
                                                {{ $session->started_at->format('H:i') }}–{{ ($session->ended_at ?? $session->last_seen_at)->format('H:i') }}
                                                @if ($session->map)
                                                    · {{ $session->map->name }}
                                                @endif
                                            </div>
                                        </div>
                                        <div class="session-item__dur {{ $session->isOpen() ? 'session-item__dur--live' : '' }}">
                                            @if ($session->isOpen())
                                                ● live
                                            @else
                                                {{ $humanize($session->minutes) }}
                                            @endif
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-small" style="color: #75787f">
                                No sessions recorded yet — these accumulate from the next data update onward.
                            </p>
                        @endif
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong>Favorite servers</strong></div>
                        @if ($favoriteServers->count())
                            <ul class="toplist toplist--servers">
                                @foreach ($favoriteServers as $favorite)
                                    @continue(!$favorite->server)
                                    <li>
                                        <div class="toplist__row">
                                            <a class="toplist__name"
                                               href="{{ url('server', [urlencode($favorite->server->id), urlencode($favorite->server->name)]) }}">{{ $favorite->server->name }}</a>
                                            <span class="toplist__val">{{ $humanize($favorite->sum_minutes) }}</span>
                                        </div>
                                        <div class="toplist__bar">
                                            <span style="width: {{ round($favorite->sum_minutes / $favoriteServersMax * 100) }}%"></span>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-small" style="color: #75787f">No server activity recorded yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Top maps + mods -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong>Most played maps</strong></div>
                        <ul class="toplist">
                            @foreach ($topMaps as $mapName => $mapMinutes)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name" href="{{ url('map', urlencode($mapName)) }}">{{ $mapName }}</a>
                                        <span class="toplist__val">{{ $humanize($mapMinutes) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($mapMinutes / $topMapsMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="block chart">
                        <div class="title"><strong>Most played mods</strong></div>
                        @if (count($modsChart))
                            <div class="radar-chart chart margin-bottom-sm">
                                <canvas id="playedModsChart"></canvas>
                            </div>
                        @else
                            <p class="text-small" style="color: #75787f">No mod data yet.</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Clan history -->
            @if ($player->clanRecords()->count() >= 1)
                <div class="row">
                    <div class="col-lg-12">
                        <div class="messages-block block">
                            <div class="title">
                                <strong>Clan history</strong>
                            </div>
                            <div class="messages pre-scrollable pre-scrollable-needed">
                                @foreach ($player->clanRecords()->get() as $clanRecord)
                                    <a href="{{ url('clan', urlencode($clanRecord->clan->name)) }}"
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

        </div>
    </section>
@endsection
@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if (count($modsChart))
                let playedMods = $('#playedModsChart');
                @if (count($modsChart) >= 3)
                    // radar reads well once there are a few mods to compare
                    ChartHelper.radarChart(playedMods,
                        {!! json_encode(array_keys($modsChart)) !!},
                        {!! json_encode(array_values($modsChart)) !!},
                        {!! max(array_values($modsChart)) !!});
                @else
                    // one or two mods would collapse a radar, so fall back to a pie
                    ChartHelper.pieChart(playedMods,
                        {!! json_encode(array_keys($modsChart)) !!},
                        {!! json_encode(array_values($modsChart)) !!});
                @endif
            @endif
        });
    </script>
@endsection
