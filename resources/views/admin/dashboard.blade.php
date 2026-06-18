@extends('layouts.app')

@section('content')
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Map Archive</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            {{-- Headline cards --}}
            {{-- Fixed equal grid so no single card can grow: 2-up (xs), 3-up (md), 5-up (lg) --}}
            <div class="row row-cols-2 row-cols-md-3 row-cols-lg-5">
                @php
                    // Humanize the cache size: at 5-across a long "1534.6 MB" would otherwise
                    // wrap inside its card and read out of step with the short counts beside it.
                    $cacheMb = $overview['cachedBytes'] / 1048576;
                    $cacheSize = $cacheMb >= 1024 ? round($cacheMb / 1024, 1) . ' GB' : round($cacheMb, 1) . ' MB';
                    $cards = [
                        ['label' => 'Cached map files', 'value' => number_format($overview['cachedFiles'])],
                        ['label' => 'Cache size', 'value' => $cacheSize],
                        ['label' => 'Total versions', 'value' => number_format($overview['totalVersions'])],
                        ['label' => 'Never attempted', 'value' => number_format($overview['neverAttempted'])],
                        ['label' => 'Cooling down', 'value' => number_format($overview['coolingDown'])],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <div class="col">
                        {{-- Stacked + centered: the value gets the full card width (no wrap), so every card keeps the same size --}}
                        <div class="statistic-block block text-center">
                            <div class="progress-details">
                                <div class="title"><strong>{{ $card['label'] }}</strong></div>
                                <div class="number dashtext-1">{{ $card['value'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row">
                {{-- Downloads over time --}}
                <div class="col-lg-8">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Downloads over time (last {{ $days }} days)</strong></div>
                        <div class="block-body">
                            <canvas id="downloadsChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                {{-- Version status breakdown: counts per acquisition_status, so the backlog drain is observable --}}
                <div class="col-lg-4">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Version status</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Status</th><th>Versions</th></tr></thead>
                                <tbody>
                                @foreach ($statusBreakdown as $status => $count)
                                    <tr><td>{{ $status }}</td><td>{{ number_format($count) }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Source breakdown: stacked under Version status in the same column --}}
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">By source (last {{ $days }} days)</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Source</th><th>Success</th><th>Failure</th></tr></thead>
                                <tbody>
                                @forelse ($sourceBreakdown as $source => $counts)
                                    <tr><td>{{ $source }}</td><td>{{ $counts['success'] }}</td><td>{{ $counts['failure'] }}</td></tr>
                                @empty
                                    <tr><td colspan="3" class="text-muted">No attempts recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                {{-- Recent downloads: standalone full-width row --}}
                <div class="col-12">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Recent downloads</strong></div>
                        <div class="block-body table-responsive">
                            {{-- Fixed layout + truncation: map names carry an unbreakable name_<sha256> token that otherwise blows out the column --}}
                            <table class="table table-striped" style="table-layout: fixed;">
                                <thead><tr><th style="width: 42%;">Map</th><th style="width: 20%;">Source</th><th style="width: 18%;">Size</th><th style="width: 20%;">When</th></tr></thead>
                                <tbody>
                                @forelse ($recentDownloads as $d)
                                    <tr>
                                        <td class="text-truncate" title="{{ $d->mapVersion?->map?->name }}">{{ $d->mapVersion?->map?->name ?? '—' }}</td>
                                        <td class="text-truncate">{{ $d->source }}</td>
                                        <td>{{ $d->bytes ? round($d->bytes / 1024) . ' KB' : '—' }}</td>
                                        <td>{{ $d->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">No downloads recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                {{-- Failing servers --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Failing servers</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Server</th><th>Fails</th><th>Success rate</th><th>Last reason</th></tr></thead>
                                <tbody>
                                @forelse ($failingServers as $s)
                                    <tr>
                                        <td>{{ $s['name'] }}<br><small class="text-muted">{{ $s['address'] }}</small></td>
                                        <td>{{ $s['fails'] }}</td>
                                        <td>{{ $s['successRate'] }}%</td>
                                        <td>{{ $s['lastReason'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="text-muted">No server failures recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                {{-- Recent failures --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title">
                            <strong class="d-block px-3 pt-3">Recent failures</strong>
                            <small class="d-block px-3 pb-2 text-muted">Mirror-only 404s are omitted — those maps aren't on maps.ddnet.org but are retried over UDP in the same pass.</small>
                        </div>
                        <div class="block-body table-responsive">
                            {{-- Fixed layout + truncation: map names carry an unbreakable name_<sha256> token that otherwise blows out the column --}}
                            <table class="table table-striped" style="table-layout: fixed;">
                                <thead><tr><th style="width: 28%;">Map</th><th style="width: 15%;">Source</th><th style="width: 20%;">Reason</th><th style="width: 25%;">Server</th><th style="width: 12%;">When</th></tr></thead>
                                <tbody>
                                @forelse ($recentFailures as $f)
                                    <tr>
                                        <td class="text-truncate" title="{{ $f->mapVersion?->map?->name }}">{{ $f->mapVersion?->map?->name ?? '—' }}</td>
                                        <td class="text-truncate">{{ $f->source }}</td>
                                        <td class="text-truncate" title="{{ $f->reason }}">{{ $f->reason }}</td>
                                        <td class="text-truncate" title="{{ $f->server?->name }}">{{ $f->server?->name ?? '—' }}</td>
                                        <td>{{ $f->created_at?->diffForHumans() }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="5" class="text-muted">No failures recorded yet.</td></tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection

@section('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            'use strict';
            Chart.defaults.color = '#75787c';

            // downloadsPerDay is an ordered {day: count} map from MapArchiveStats
            const series = {!! json_encode($downloadsPerDay) !!};
            new Chart(document.getElementById('downloadsChart'), {
                type: 'bar',
                data: {
                    labels: Object.keys(series),
                    datasets: [{
                        label: 'Successful downloads',
                        data: Object.values(series),
                        backgroundColor: 'rgba(113, 39, 172, 0.5)',
                    }],
                },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { color: '#3f4145' } },
                        y: { beginAtZero: true, grid: { color: '#3f4145' }, ticks: { precision: 0 } },
                    },
                },
            });
        });
    </script>
@endsection
