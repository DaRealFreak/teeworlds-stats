@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Server browser</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        @php
                            // the freshest last_seen is when the scraper last touched a server, i.e. the last refresh
                            $lastRefreshed = $servers->max('last_seen');
                        @endphp
                        <div class="title">
                            <strong><span id="online_server_count">{{ number_format($servers->count()) }}</span> Servers online</strong>
                            @if ($lastRefreshed)
                                <span class="server-browser-refreshed">&mdash; Last refreshed: {{ \Carbon\Carbon::parse($lastRefreshed)->format('M j, Y H:i') }}</span>
                            @endif
                        </div>

                        {{-- client-side filter bar; serverbrowser.js reads these and shows/hides rows --}}
                        <div class="row g-2 mb-3" id="server_browser_filters">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="filter_name"
                                       placeholder="Filter by server or player…" autocomplete="off">
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filter_type">
                                    <option value="">All types</option>
                                    <option value="ddnet">DDNet</option>
                                    <option value="vanilla_06">Vanilla 0.6</option>
                                    <option value="vanilla_07">Vanilla 0.7</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="filter_mod">
                                    <option value="">All gametypes</option>
                                    @foreach ($servers->map(fn ($s) => $s->currentServerHistory?->mod?->name)->filter()->unique()->sort() as $modName)
                                        <option value="{{ $modName }}">{{ $modName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select class="form-select" id="filter_map">
                                    <option value="">All maps</option>
                                    @foreach ($servers->map(fn ($s) => $s->currentServerHistory?->map?->name)->filter()->unique()->sort() as $mapName)
                                        <option value="{{ $mapName }}">{{ $mapName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-2 d-flex align-items-center">
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input" id="filter_hide_empty">
                                    <label class="form-check-label" for="filter_hide_empty">Hide empty</label>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="server_browser_table">
                                {{-- server name and map hold the longest values (incl. map hashes) and wrap, so they
                                     get equal, generous width; type is just a few badges and players a single count --}}
                                <colgroup>
                                    <col style="width: 30%;">
                                    <col style="width: 10%;">
                                    <col style="width: 30%;">
                                    <col style="width: 18%;">
                                    <col style="width: 12%;">
                                </colgroup>
                                <thead>
                                <tr>
                                    <th>Server</th>
                                    <th>Type</th>
                                    <th>Map</th>
                                    <th>Gametype</th>
                                    <th>Players</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach ($servers as $serverEntry)
                                    @php
                                        $history = $serverEntry->currentServerHistory;
                                        $mapName = $history?->map?->name;
                                        $modName = $history?->mod?->name;
                                        $players = $serverEntry->currentPlayers;
                                        $playerCount = $players->count();
                                        $playerNames = mb_strtolower($players->pluck('name')->implode(' '));
                                        // flavor → human label; protocol pills come from the address set
                                        $flavorLabel = match ($serverEntry->flavor) {
                                            'ddnet' => 'DDNet',
                                            'vanilla_06', 'vanilla_07' => 'Vanilla',
                                            default => null,
                                        };
                                        $maxClients = $serverEntry->max_clients;
                                        // legacy rows that pre-date the max_clients column never reported slot data, so
                                        // there is no meaningful denominator to show for them — fall back to a bare count.
                                        $ratio = $maxClients > 0 ? $playerCount . '/' . $maxClients : (string) $playerCount;
                                        // IPv6 literals contain colons, so the host must be bracketed ([host]:port) for the
                                        // game's connect field to parse host vs. port; IPv4 addresses are left untouched.
                                        $connectAddress = str_contains($serverEntry->ip, ':')
                                            ? '[' . $serverEntry->ip . ']:' . $serverEntry->port
                                            : $serverEntry->ip . ':' . $serverEntry->port;
                                    @endphp
                                    <tr data-name="{{ mb_strtolower($serverEntry->name) }}"
                                        data-map="{{ $mapName }}"
                                        data-mod="{{ $modName }}"
                                        data-flavor="{{ $serverEntry->flavor }}"
                                        data-players="{{ $playerCount }}"
                                        data-player-names="{{ $playerNames }}">
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}"
                                               class="server-name d-block">{{ $serverEntry->name }}</a>
                                            <span class="server-connect small" role="button" tabindex="0"
                                                  data-connect="{{ $connectAddress }}"
                                                  aria-label="Copy {{ $connectAddress }} to clipboard"
                                                  title="Copy {{ $connectAddress }} to clipboard">{{ $connectAddress }} <i class="fa fa-clipboard" aria-hidden="true"></i></span>
                                        </td>
                                        <td>
                                            @if ($flavorLabel)
                                                <span class="badge {{ $serverEntry->flavor === 'ddnet' ? 'bg-info' : 'bg-secondary' }}">{{ $flavorLabel }}</span>
                                            @endif
                                            @foreach ($serverEntry->protocols() as $protocol)
                                                <span class="badge bg-dark">0.{{ $protocol }}</span>
                                            @endforeach
                                        </td>
                                        <td class="cell-wrap">
                                            @if ($mapName)
                                                <a href="{{ url('map', urlencode($mapName)) }}">{{ $mapName }}</a>
                                            @endif
                                        </td>
                                        <td class="cell-truncate">
                                            @if ($modName)
                                                <a href="{{ url('mod', urlencode($modName)) }}" title="{{ $modName }}">{{ $modName }}</a>
                                            @endif
                                        </td>
                                        <td class="players-cell">
                                            @if ($playerCount)
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button"
                                                      aria-label="Players on this server">{{ $ratio }}</span>
                                                <div class="server-players d-none">
                                                    @foreach ($players as $player)
                                                        @php $clan = $player->clan(); @endphp
                                                        <a href="{{ url('tee', urlencode($player->name)) }}" class="d-block">
                                                            {{ $player->name }}@if ($clan) <small class="text-muted">{{ $clan->name }}</small>@endif
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="badge bg-secondary" aria-label="Players on this server">{{ $ratio }}</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
