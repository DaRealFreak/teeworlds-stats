@php
    use App\Utility\ChartUtility;

    // $entity, $kind, $crossTitle, $crossChart are provided by the including view.
    $humanize = fn ($minutes) => ChartUtility::humanizeDuration((int) $minutes);

    $topPlayers = $entity->topPlayers(10);
    $topClans   = $entity->topClans(10);
    $topServers = $entity->topServers(10);

    $topPlayersMax = $topPlayers->max('sum_minutes') ?: 1;
    $topClansMax   = $topClans->max('minutes') ?: 1;
    $topServersMax = $topServers->max('sum_minutes') ?: 1;
@endphp

<!-- Page Header-->
<div class="page-header no-margin-bottom">
    <div class="container-fluid">
        <h2 class="h5 no-margin-bottom">{{ $kind }}: {{ $entity->name }}</h2>
    </div>
</div>

<section class="section-content">
    <div class="container-fluid">

        <!-- Summary tiles -->
        <div class="stat-tiles">
            <div class="stat-tile">
                <div class="stat-tile__label">Played by players</div>
                <div class="stat-tile__value"><i class="fa fa-clock-o"></i>{{ $humanize($entity->totalPlayerMinutes()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Online on servers</div>
                <div class="stat-tile__value"><i class="fa fa-server"></i>{{ $humanize($entity->totalServerMinutes()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Players</div>
                <div class="stat-tile__value"><i class="fa fa-users"></i>{{ number_format($entity->distinctPlayersCount()) }}</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile__label">Servers</div>
                <div class="stat-tile__value"><i class="fa fa-hdd-o"></i>{{ number_format($entity->distinctServersCount()) }}</div>
            </div>
        </div>

        <!-- Activity charts -->
        <div class="row">
            <div class="col-lg-6">
                <div class="line-chart block chart">
                    <div class="title"><strong>{{ $entity->name }}'s online probability</strong></div>
                    <canvas id="onlineLineChartDays"></canvas>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="line-chart block chart">
                    <div class="title"><strong>{{ $entity->name }}'s online probability per day</strong></div>
                    <canvas id="onlineLineChartHours"></canvas>
                </div>
            </div>
        </div>

        <!-- Cross chart -->
        <div class="row">
            <div class="col-lg-6">
                <div class="pie-chart chart block">
                    <div class="title"><strong>{{ $crossTitle }}</strong></div>
                    @if (count($crossChart))
                        <div class="radar-chart chart margin-bottom-sm">
                            <canvas id="crossChart"></canvas>
                        </div>
                    @else
                        <p class="text-small" style="color: #75787f">No play data yet.</p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Rankings -->
        <div class="row">
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top players</strong></div>
                    @if ($topPlayers->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topPlayers as $row)
                                @continue(!$row->player)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name d-flex align-items-center gap-2"
                                           href="{{ url('tee', urlencode($row->player->name)) }}">
                                            <x-player-tee :player="$row->player" :size="28" />
                                            {{ $row->player->name }}
                                        </a>
                                        <span class="toplist__val">{{ $humanize($row->sum_minutes) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row->sum_minutes / $topPlayersMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-small" style="color: #75787f">No player activity recorded yet.</p>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top clans</strong></div>
                    @if ($topClans->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topClans as $row)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name"
                                           href="{{ url('clan', urlencode($row['clan']->name)) }}">{{ $row['clan']->name }}</a>
                                        <span class="toplist__val">{{ $humanize($row['minutes']) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row['minutes'] / $topClansMax * 100) }}%"></span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-small" style="color: #75787f">No clan activity recorded yet.</p>
                    @endif
                </div>
            </div>
            <div class="col-lg-4">
                <div class="block">
                    <div class="title"><strong>Top servers</strong></div>
                    @if ($topServers->count())
                        <ul class="toplist toplist--servers">
                            @foreach ($topServers as $row)
                                @continue(!$row->server)
                                <li>
                                    <div class="toplist__row">
                                        <a class="toplist__name"
                                           href="{{ url('server', [urlencode($row->server->id), urlencode($row->server->name)]) }}">{{ $row->server->name }}</a>
                                        <span class="toplist__val">{{ $humanize($row->sum_minutes) }}</span>
                                    </div>
                                    <div class="toplist__bar">
                                        <span style="width: {{ round($row->sum_minutes / $topServersMax * 100) }}%"></span>
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

    </div>
</section>
