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
                if (ent) {
                    ent.addEventListener('input', function () {
                        viewer.setEntitiesOpacity(Number(ent.value) / 100);
                    });
                    // Keep the slider in sync when a jump auto-raises the entities overlay.
                    viewer.setEntitiesChangeListener(function (op) {
                        ent.value = String(Math.round(op * 100));
                    });
                }

                // Build the legend/navigator from the entities actually present on this map.
                var legend = document.getElementById('mapLegend');
                if (legend && viewer.mapIndex) {
                    var entries = viewer.mapIndex();
                    if (!entries.length) {
                        legend.style.display = 'none';
                    } else {
                        var checkboxes = {}; // highlight key -> checkbox, for jump-driven sync
                        var buildRow = function (entry, depth) {
                            var row = document.createElement('div');
                            row.className = 'map-viewer__legend-item' + (depth ? ' map-viewer__legend-child' : '');
                            if (entry.toggleable) {
                                var cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.addEventListener('change', function () { viewer.setHighlight(entry.key, cb.checked); });
                                checkboxes[entry.key] = cb;
                                row.appendChild(cb);
                            }
                            if (entry.color) {
                                var swatch = document.createElement('span');
                                swatch.className = 'map-viewer__swatch';
                                swatch.style.background = 'rgb(' +
                                    Math.round(entry.color[0] * 255) + ',' +
                                    Math.round(entry.color[1] * 255) + ',' +
                                    Math.round(entry.color[2] * 255) + ')';
                                row.appendChild(swatch);
                            }
                            var label = document.createElement('span');
                            label.className = 'map-viewer__legend-label';
                            label.textContent = entry.label + ' (' + entry.count + ')';
                            row.appendChild(label);
                            if (entry.jumpable) {
                                var jump = document.createElement('button');
                                jump.type = 'button';
                                jump.className = 'map-viewer__jump';
                                jump.title = 'Jump to ' + entry.label + ' (click again for the next one)';
                                jump.innerHTML = '<i class="fa fa-crosshairs" aria-hidden="true"></i>';
                                var counter = document.createElement('span');
                                counter.className = 'map-viewer__jump-count';
                                jump.addEventListener('click', function () {
                                    var res = viewer.jumpToNext(entry.key);
                                    if (!res) return;
                                    counter.textContent = (res.index + 1) + '/' + res.total;
                                    if (checkboxes[res.highlightKey]) checkboxes[res.highlightKey].checked = true;
                                });
                                row.appendChild(jump);
                                row.appendChild(counter);
                            }
                            return row;
                        };
                        entries.forEach(function (entry) {
                            legend.appendChild(buildRow(entry, 0));
                            if (entry.children) {
                                entry.children.forEach(function (child) { legend.appendChild(buildRow(child, 1)); });
                            }
                        });
                    }
                }
            });
        });
    </script>
    @endif
@endsection
