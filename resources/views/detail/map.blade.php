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
@endsection
