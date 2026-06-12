@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Player list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="player_table">
                                <thead>
                                <tr>
                                    <x-sortable-th column="name" label="Tee" />
                                    <x-sortable-th column="clan" label="Clan" />
                                    <x-sortable-th column="played" label="Played time" default-dir="desc" />
                                    <x-sortable-th column="afk" label="AFK %" default-dir="desc" />
                                    <th>Most played mod</th>
                                    <th>Most played map</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($players as $playerEntry)
                                    @php
                                        $topMods = $playerEntry->chartPlayedMods();
                                        $topMaps = $playerEntry->chartPlayedMaps();
                                        $afkTracked = (int) $playerEntry->afk_tracked;
                                    @endphp
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("tee", urlencode($playerEntry->name)) }}">{{ $playerEntry->name }}</a>
                                        </th>
                                        <td>
                                            @if ($playerEntry->clan_name)
                                                <a href="{{ url("clan", urlencode($playerEntry->clan_name)) }}">{{ $playerEntry->clan_name }}</a>
                                            @endif
                                        </td>
                                        <td>
                                            {{ \App\Utility\ChartUtility::humanizeDuration((int) $playerEntry->played_minutes) }}
                                        </td>
                                        <td>
                                            {{ $afkTracked > 0 ? round($playerEntry->afk_sum / $afkTracked * 100) . '%' : '—' }}
                                        </td>
                                        <td>
                                            @if ($topMods)
                                                <a href="{{ url("mod", urlencode(array_key_first($topMods))) }}">{{ array_key_first($topMods) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td>
                                            @if ($topMaps)
                                                <a href="{{ url("map", urlencode(array_key_first($topMaps))) }}">{{ array_key_first($topMaps) }}</a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $players->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
