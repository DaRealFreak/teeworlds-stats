@extends('layouts.app')

@php
    // view-model for the shared play-stats partial
    $entity     = $map;
    $kind       = 'Map';
    $crossTitle = 'Most played mods';
    $crossChart = $map->chartPlayedMods();
@endphp

@section('content')
    <section class="section-content">
        <div class="container-fluid">
            @if($map->hasDownloadableFile())
                <div class="block map-viewer">
                    <canvas id="mapCanvas" class="map-viewer__canvas" width="960" height="540"></canvas>
                    <div class="map-viewer__controls">
                        <button type="button" id="mapFit" class="btn btn-sm btn-secondary">Fit</button>
                        <label class="map-viewer__toggle"><input type="checkbox" id="mapEntities"> Entities</label>
                    </div>
                </div>
            @endif
            <div class="block">
                @if($map->hasDownloadableFile())
                    <a href="{{ route('mapDownload', ['map_name' => $map->name]) }}" class="btn btn-primary">
                        <i class="fa fa-download" aria-hidden="true"></i> Download .map
                    </a>
                @else
                    <p class="text-muted mb-0">Map file not available.</p>
                @endif
            </div>
        </div>
    </section>
    @include('partials.play-stats')
@endsection

@section('scripts')
    @include('partials.play-stats-scripts')
    @if($map->hasDownloadableFile())
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var canvas = document.getElementById('mapCanvas');
            if (!canvas || !window.MapViewer) return;
            window.MapViewer.init(canvas, {
                mapUrl: {!! json_encode(route('mapFile', ['map_name' => $map->name])) !!},
                mapresBase: {!! json_encode(asset('mapres')) !!},
                entitiesUrl: {!! json_encode(asset('entities/DDNet.png')) !!}
            }).then(function (viewer) {
                if (!viewer) return;
                var fit = document.getElementById('mapFit');
                var ent = document.getElementById('mapEntities');
                if (fit) fit.addEventListener('click', function () { viewer.fit(); });
                if (ent) ent.addEventListener('change', function () { viewer.toggleEntities(); });
            });
        });
    </script>
    @endif
@endsection
