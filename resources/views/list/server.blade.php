@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Server list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="server_table">
                                <thead>
                                <tr>
                                    <th>Server</th>
                                    <th>Players</th>
                                    <th>Total time played</th>
                                    <th>Most played map</th>
                                    <th>Most played mod</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($servers as $serverEntry)
                                    <tr>
                                        <td>
                                            <a href="{{ url("server", [urlencode($serverEntry->id), urlencode($serverEntry->name)]) }}">{{ $serverEntry->name }}</a>
                                        </td>
                                        <td>
                                            {{ $serverEntry->players()->get()->count() }}
                                        </td>
                                        <td>
                                            {{ \App\Utility\ChartUtility::humanizeDuration($serverEntry->totalHoursOnline()) }}
                                        </td>
                                        <td>
                                            <a href="{{ url("mod", urlencode(array_keys($serverEntry->chartPlayedMaps())[0])) }}">
                                                {{ array_keys($serverEntry->chartPlayedMaps())[0] }}
                                            </a>
                                        </td>
                                        <td>
                                            <a href="{{ url("mod", urlencode(array_keys($serverEntry->chartPlayedMods())[0])) }}">
                                                {{ array_keys($serverEntry->chartPlayedMods())[0] }}
                                            </a>
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $servers->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection