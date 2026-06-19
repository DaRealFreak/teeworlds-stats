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
                <div class="alert mb-2" id="mapCompareNotice" role="status" hidden></div>
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
            <div class="block d-flex align-items-center gap-2">
                <span class="badge {{ $availability->badgeClass() }}" title="{{ $availability->label() }} map file">{{ $availability->label() }}</span>
                @if($map->hasDownloadableFile())
                    <a href="{{ $selected ? route('mapDownload', ['map_name' => $map->name, 'version' => $selected->contentKey()]) : route('mapDownload', ['map_name' => $map->name]) }}" class="btn btn-primary">
                        <i class="fa fa-download" aria-hidden="true"></i> Download .map
                    </a>
                @else
                    <span class="text-muted">Map file not available.</span>
                @endif
            </div>
            @if($map->versions->isNotEmpty())
                <div class="block" id="mapVersions">
                    <h2 class="h5">Versions ({{ $map->versions->count() }})</h2>
                    <p class="text-muted small mb-2">
                        @if($map->versions->count() >= 2)
                            This name exists as several distinct map files. View or download a specific one.
                        @else
                            The known version of this map file.
                        @endif
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Version</th>
                                    <th scope="col">File</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Last seen</th>
                                    <th scope="col">Servers</th>
                                    <th scope="col"></th>
                                </tr>
                            </thead>
                            @php($selectedPickable = $selected && ($versionAvailability[$selected->id] ?? null) !== \App\Service\MapAvailability::Unavailable)
                            <tbody>
                                @foreach($map->versions as $version)
                                    @php($key = $version->contentKey())
                                    @php($isSelected = $selected && $selected->is($version))
                                    @php($isCurrent = $version->is($map->versions->first()))
                                    @php($avail = $versionAvailability[$version->id])
                                    @php($pickable = $avail !== \App\Service\MapAvailability::Unavailable)
                                    <tr @class(['table-active' => $isSelected])>
                                        <td>
                                            <code>{{ \Illuminate\Support\Str::substr($key, 0, 12) }}</code>
                                            @if($isCurrent)<span class="badge bg-secondary">current</span>@endif
                                            @if($isSelected)<span class="badge bg-info">viewing</span>@endif
                                            @if($compare && $compare->is($version))<span class="badge bg-warning text-dark">base</span>@endif
                                        </td>
                                        <td><span class="badge {{ $avail->badgeClass() }}">{{ $avail->label() }}</span></td>
                                        <td>{{ $version->size ? number_format($version->size / 1024) . ' KB' : '—' }}</td>
                                        <td>{{ $version->last_seen_at?->diffForHumans() ?? '—' }}</td>
                                        <td>{{ $serverCounts[$version->id] ?? 0 }}</td>
                                        <td class="text-end">
                                            @if($canCompare && $pickable && !$isSelected && $selectedPickable)
                                                <a href="{{ route('searchMapByName', ['map_name' => $map->name, 'version' => $key, 'compare' => $selected->contentKey()]) }}" class="btn btn-sm btn-outline-info">Compare</a>
                                            @endif
                                            @unless($isSelected)
                                                <a href="{{ route('searchMapByName', ['map_name' => $map->name, 'version' => $key]) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                            @endunless
                                            <a href="{{ route('mapDownload', ['map_name' => $map->name, 'version' => $key]) }}" class="btn btn-sm btn-outline-primary">Download</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
            @if($canCompare)
                <div class="block" id="mapCompareResult" hidden></div>
            @endif
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
                mapUrl: {!! json_encode($selected ? route('mapFile', ['map_name' => $map->name, 'version' => $selected->contentKey()]) : route('mapFile', ['map_name' => $map->name]), JSON_UNESCAPED_SLASHES) !!},
                mapresBase: {!! json_encode(asset('mapres'), JSON_UNESCAPED_SLASHES) !!},
                entitiesUrl: {!! json_encode(asset('entities/DDNet.png'), JSON_UNESCAPED_SLASHES) !!},
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

                // Build the grouped legend/navigator from the entities present on this map.
                var legend = document.getElementById('mapLegend');
                if (legend && viewer.mapIndex) {
                    var entries = viewer.mapIndex();
                    if (!entries.length) {
                        legend.style.display = 'none';
                    } else {
                        var checkboxes = {}; // outline key -> checkbox, for jump-driven sync
                        var masterSync = {}; // child outline key -> fn refreshing its group master

                        var swatchEl = function (color) {
                            var s = document.createElement('span');
                            s.className = 'map-viewer__swatch';
                            s.style.background = 'rgb(' + Math.round(color[0] * 255) + ',' +
                                Math.round(color[1] * 255) + ',' + Math.round(color[2] * 255) + ')';
                            return s;
                        };

                        // A leaf row: optional outline checkbox (+swatch, label-wrapped so clicking the
                        // name toggles it), label, and an optional crosshair jump that cycles instances.
                        var buildLeaf = function (entry, depth, onToggle) {
                            var row = document.createElement('div');
                            row.className = 'map-viewer__legend-item' + (depth ? ' map-viewer__legend-child' : '');
                            var label = document.createElement('span');
                            label.className = 'map-viewer__legend-label';
                            label.textContent = entry.label + ' (' + entry.count + ')';
                            if (entry.toggleable) {
                                var toggle = document.createElement('label');
                                toggle.className = 'map-viewer__legend-toggle';
                                var cb = document.createElement('input');
                                cb.type = 'checkbox';
                                cb.addEventListener('change', function () {
                                    viewer.setHighlight(entry.key, cb.checked);
                                    if (onToggle) onToggle();
                                });
                                checkboxes[entry.key] = cb;
                                toggle.appendChild(cb);
                                if (entry.color) toggle.appendChild(swatchEl(entry.color));
                                toggle.appendChild(label);
                                row.appendChild(toggle);
                            } else {
                                if (entry.color) row.appendChild(swatchEl(entry.color));
                                row.appendChild(label);
                            }
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
                                    if (masterSync[res.highlightKey]) masterSync[res.highlightKey]();
                                });
                                row.appendChild(jump);
                                row.appendChild(counter);
                            }
                            return row;
                        };

                        // A collapsible <details> group. Typed groups (per-child colours) start open
                        // with a tri-state master toggle; numbered groups (one colour, jump-only
                        // children) start collapsed and the master is the group's single outline toggle.
                        var buildGroup = function (entry) {
                            var typed = entry.children.length > 0 && entry.children[0].toggleable;
                            var details = document.createElement('details');
                            details.className = 'map-viewer__legend-group';
                            if (typed) details.open = true;

                            var summary = document.createElement('summary');
                            summary.className = 'map-viewer__legend-header';
                            var caret = document.createElement('span');
                            caret.className = 'map-viewer__legend-caret';
                            caret.setAttribute('aria-hidden', 'true');
                            summary.appendChild(caret);
                            var master = document.createElement('input');
                            master.type = 'checkbox';
                            // The group name is in a sibling span, not a <label>, so name the master for SR users.
                            master.setAttribute('aria-label', 'Toggle all ' + entry.label);
                            // Toggling the checkbox must not also open/close the <details>.
                            master.addEventListener('click', function (e) { e.stopPropagation(); });
                            summary.appendChild(master);
                            if (entry.color) summary.appendChild(swatchEl(entry.color));
                            var label = document.createElement('span');
                            label.className = 'map-viewer__legend-label';
                            label.textContent = entry.label + ' (' + entry.count + ')';
                            summary.appendChild(label);
                            details.appendChild(summary);

                            var body = document.createElement('div');
                            body.className = 'map-viewer__legend-children';
                            if (typed) {
                                // Master aggregates the per-child outline toggles (tri-state).
                                var childBoxes = [];
                                var refresh = function () {
                                    var on = 0;
                                    for (var i = 0; i < childBoxes.length; i++) if (childBoxes[i].checked) on++;
                                    master.checked = on === childBoxes.length && on > 0;
                                    master.indeterminate = on > 0 && on < childBoxes.length;
                                };
                                entry.children.forEach(function (child) {
                                    body.appendChild(buildLeaf(child, 1, refresh));
                                    childBoxes.push(checkboxes[child.key]);
                                    masterSync[child.key] = refresh;
                                });
                                master.addEventListener('change', function () {
                                    var on = master.checked;
                                    childBoxes.forEach(function (cb, i) {
                                        cb.checked = on;
                                        viewer.setHighlight(entry.children[i].key, on);
                                    });
                                    master.indeterminate = false;
                                });
                                refresh();
                            } else {
                                // Numbered group: the master is the group's single outline toggle.
                                checkboxes[entry.key] = master;
                                master.addEventListener('change', function () {
                                    viewer.setHighlight(entry.key, master.checked);
                                });
                                entry.children.forEach(function (child) {
                                    body.appendChild(buildLeaf(child, 1));
                                });
                            }
                            details.appendChild(body);
                            return details;
                        };

                        entries.forEach(function (entry) {
                            legend.appendChild(entry.children ? buildGroup(entry) : buildLeaf(entry, 0));
                        });
                    }
                }

                // When the page was opened with a ?compare base, overlay the physics diff onto B.
                var compareUrl = {!! $compare ? json_encode(route('mapFile', ['map_name' => $map->name, 'version' => $compare->contentKey()]), JSON_UNESCAPED_SLASHES) : 'null' !!};
                var comparePanel = document.getElementById('mapCompareResult');
                if (compareUrl && comparePanel && window.MapCompare) {
                    window.MapCompare.run({
                        baseUrl: compareUrl,
                        targetUrl: {!! json_encode($selected ? route('mapFile', ['map_name' => $map->name, 'version' => $selected->contentKey()]) : route('mapFile', ['map_name' => $map->name]), JSON_UNESCAPED_SLASHES) !!},
                        viewer: viewer,
                        panelEl: comparePanel,
                        noticeEl: document.getElementById('mapCompareNotice'),
                        labels: {
                            base: {!! json_encode($compare ? \Illuminate\Support\Str::substr($compare->contentKey(), 0, 12) : '', JSON_UNESCAPED_SLASHES) !!},
                            target: {!! json_encode($selected ? \Illuminate\Support\Str::substr($selected->contentKey(), 0, 12) : '', JSON_UNESCAPED_SLASHES) !!}
                        }
                    });
                }
            });
        });
    </script>
    @endif
@endsection
