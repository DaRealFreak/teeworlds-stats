@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Map list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="map_table">
                                <thead>
                                <tr>
                                    <th>Map</th>
                                    <th>Played by players</th>
                                    <th>Played on servers</th>
                                    <th>Total time played(players)</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($maps as $mapEntry)
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("map", urlencode($mapEntry->name)) }}">{{ $mapEntry->name }}</a>
                                        </th>
                                        <td>
                                            {{ $mapEntry->statsPlayedBy()->get()->count() }}
                                        </td>
                                        <td>
                                            {{ $mapEntry->statsPlayedOnServer()->get()->count() }}
                                        </td>
                                        <td>
                                            @if($hourEntry = $mapEntry->totalHoursOnline()->first())
                                                {{ \App\Utility\ChartUtility::humanizeDuration($hourEntry->sum_minutes) }}
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $maps->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection