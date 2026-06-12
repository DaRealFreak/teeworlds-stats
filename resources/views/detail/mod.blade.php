@extends('layouts.app')

@php
    // view-model for the shared play-stats partial
    $entity     = $mod;
    $kind       = 'Mod';
    $crossTitle = 'Most played maps';
    $crossChart = $mod->chartPlayedMaps();
@endphp

@section('content')
    @include('partials.play-stats')
@endsection

@section('scripts')
    @include('partials.play-stats-scripts')
@endsection
