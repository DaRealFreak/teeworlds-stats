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
                    <div class="map-viewer__stage">
                        <canvas id="mapCanvas" class="map-viewer__canvas" width="960" height="540"></canvas>
                        <canvas id="mapOverlay" class="map-viewer__overlay" width="960" height="540"></canvas>
                    </div>
                    <div class="map-viewer__controls">
                        <button type="button" id="mapFit" class="btn btn-sm btn-secondary">Fit</button>
                        <label class="map-viewer__slider" for="mapZoom">Zoom
                            <input type="range" id="mapZoom" min="0" max="100" value="0" step="1"
                                   title="Fit ↔ 1:1 (actual size)">
                        </label>
                        <label class="map-viewer__slider" for="mapEntities">Entities
                            <input type="range" id="mapEntities" min="0" max="100" value="0" step="1">
                        </label>
                        <div class="map-viewer__legend" id="mapLegend"></div>
                    </div>
                    <p class="map-viewer__hint text-muted">Drag to pan, scroll to zoom. With Entities up, click a teleport to jump to its destination.</p>
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
            var overlay = document.getElementById('mapOverlay');
            if (!canvas || !overlay || !window.MapViewer) return;
            window.MapViewer.init(canvas, {
                mapUrl: {!! json_encode(route('mapFile', ['map_name' => $map->name])) !!},
                mapresBase: {!! json_encode(asset('mapres')) !!},
                entitiesUrl: {!! json_encode(asset('entities/DDNet.png')) !!},
                overlayCanvas: overlay
            }).then(function (viewer) {
                if (!viewer) return;
                var fit = document.getElementById('mapFit');
                var zoom = document.getElementById('mapZoom');
                var ent = document.getElementById('mapEntities');
                if (fit) fit.addEventListener('click', function () { viewer.fit(); });
                if (zoom) {
                    zoom.addEventListener('input', function () {
                        viewer.setZoomFraction(Number(zoom.value) / 100);
                    });
                    // Keep the slider in sync with wheel-zoom / Fit / resize.
                    viewer.setZoomChangeListener(function (frac) {
                        zoom.value = String(Math.round(frac * 100));
                    });
                }
                if (ent) ent.addEventListener('input', function () {
                    viewer.setEntitiesOpacity(Number(ent.value) / 100);
                });

                // Build the highlight legend from the viewer's categories.
                var legend = document.getElementById('mapLegend');
                if (legend && window.MapViewer.highlightCategories) {
                    window.MapViewer.highlightCategories().forEach(function (cat) {
                        var item = document.createElement('label');
                        item.className = 'map-viewer__legend-item';
                        var cb = document.createElement('input');
                        cb.type = 'checkbox';
                        cb.addEventListener('change', function () { viewer.setHighlight(cat.key, cb.checked); });
                        var swatch = document.createElement('span');
                        swatch.className = 'map-viewer__swatch';
                        swatch.style.background = 'rgb(' +
                            Math.round(cat.color[0] * 255) + ',' +
                            Math.round(cat.color[1] * 255) + ',' +
                            Math.round(cat.color[2] * 255) + ')';
                        item.appendChild(cb);
                        item.appendChild(swatch);
                        item.appendChild(document.createTextNode(' ' + cat.label));
                        legend.appendChild(item);
                    });
                }
            });
        });
    </script>
    @endif
@endsection
