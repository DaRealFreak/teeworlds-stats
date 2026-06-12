@extends('layouts.app')

@php
    // view-model for the shared play-stats partial
    $entity     = $map;
    $kind       = 'Map';
    $crossTitle = 'Most played mods';
    $crossChart = $map->chartPlayedMods();
@endphp

@section('content')
    @include('partials.play-stats')
@endsection

@section('scripts')
    @include('partials.play-stats-scripts')
@endsection
