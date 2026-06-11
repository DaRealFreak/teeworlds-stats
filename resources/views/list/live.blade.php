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
                        <div class="title"><strong>Servers online right now</strong></div>

                        {{-- client-side filter bar; serverbrowser.js reads these and shows/hides rows --}}
                        <div class="row g-2 mb-3" id="server_browser_filters">
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="filter_name"
                                       placeholder="Filter by server or player…" autocomplete="off">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="filter_mod">
                                    <option value="">All gametypes</option>
                                    @foreach ($servers->map(fn ($s) => $s->currentServerHistory?->mod?->name)->filter()->unique()->sort() as $modName)
                                        <option value="{{ $modName }}">{{ $modName }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
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
                                <thead>
                                <tr>
                                    <th>Server</th>
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
                                    @endphp
                                    <tr data-name="{{ mb_strtolower($serverEntry->name) }}"
                                        data-map="{{ $mapName }}"
                                        data-mod="{{ $modName }}"
                                        data-players="{{ $playerCount }}"
                                        data-player-names="{{ $playerNames }}">
                                        <td>
                                            <a href="{{ url('server', [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}">{{ $serverEntry->name }}</a>
                                        </td>
                                        <td>
                                            @if ($mapName)
                                                <a href="{{ url('map', urlencode($mapName)) }}">{{ $mapName }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($modName)
                                                <a href="{{ url('mod', urlencode($modName)) }}">{{ $modName }}</a>
                                            @endif
                                        </td>
                                        <td class="players-cell">
                                            @if ($playerCount)
                                                <span class="badge bg-primary server-player-count"
                                                      tabindex="0" role="button"
                                                      aria-label="Players on this server">{{ $playerCount }}</span>
                                                <div class="server-players d-none">
                                                    @foreach ($players as $player)
                                                        @php $clan = $player->clan(); @endphp
                                                        <a href="{{ url('tee', urlencode($player->name)) }}" class="d-block">
                                                            {{ $player->name }}@if ($clan) <small class="text-muted">{{ $clan->name }}</small>@endif
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @else
                                                <span class="badge bg-secondary">0</span>
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
