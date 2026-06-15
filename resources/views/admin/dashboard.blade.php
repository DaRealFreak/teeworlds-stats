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
            <div class="row">
                @php
                    $cards = [
                        ['label' => 'Cached map files', 'value' => number_format($overview['cachedFiles'])],
                        ['label' => 'Cache size', 'value' => round($overview['cachedBytes'] / 1048576, 1) . ' MB'],
                        ['label' => 'Total versions', 'value' => number_format($overview['totalVersions'])],
                        ['label' => 'Never attempted', 'value' => number_format($overview['neverAttempted'])],
                        ['label' => 'Cooling down', 'value' => number_format($overview['coolingDown'])],
                    ];
                @endphp
                @foreach ($cards as $card)
                    <div class="col-lg col-md-4 col-6">
                        <div class="statistic-block block">
                            <div class="progress-details d-flex align-items-end justify-content-between">
                                <div class="title"><strong>{{ $card['label'] }}</strong></div>
                                <div class="number dashtext-1">{{ $card['value'] }}</div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Downloads over time --}}
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Downloads over time (last {{ $days }} days)</strong></div>
                        <div class="block-body">
                            <canvas id="downloadsChart" height="80"></canvas>
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

                {{-- Source breakdown --}}
                <div class="col-lg-6">
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
                {{-- Recent downloads --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Recent downloads</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Map</th><th>Source</th><th>Size</th><th>When</th></tr></thead>
                                <tbody>
                                @forelse ($recentDownloads as $d)
                                    <tr>
                                        <td>{{ $d->mapVersion?->map?->name ?? '—' }}</td>
                                        <td>{{ $d->source }}</td>
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

                {{-- Recent failures --}}
                <div class="col-lg-6">
                    <div class="block">
                        <div class="title"><strong class="d-block px-3 pt-3">Recent failures</strong></div>
                        <div class="block-body table-responsive">
                            <table class="table table-striped">
                                <thead><tr><th>Map</th><th>Source</th><th>Reason</th><th>Server</th><th>When</th></tr></thead>
                                <tbody>
                                @forelse ($recentFailures as $f)
                                    <tr>
                                        <td>{{ $f->mapVersion?->map?->name ?? '—' }}</td>
                                        <td>{{ $f->source }}</td>
                                        <td>{{ $f->reason }}</td>
                                        <td>{{ $f->server?->name ?? '—' }}</td>
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
