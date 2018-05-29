@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Clan list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="clan_table">
                                <thead>
                                <tr>
                                    <th>Clan</th>
                                    <th>Players</th>
                                    <th>Most played mod</th>
                                    <th>Played time</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($clans as $clanEntry)
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("clan", urlencode($clanEntry->name)) }}">{{ $clanEntry->name }}</a>
                                        </th>
                                        <td>
                                            {{ $clanEntry->players()->count() }}
                                        </td>
                                        <td>
                                            @if (count($clanEntry->mostPlayedMods()->get()) > 0)
                                                {{ $clanEntry->mostPlayedMods()->first()->mod->name }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            {{ $clanEntry->humanizeDuration($clanEntry->totalHoursOnline()->first()->sum_minutes) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $clans->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection