@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Player list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="player_table">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Clan</th>
                                    <th>Played time</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($players as $player)
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("tee", urlencode($player->name)) }}">{{ $player->name }}</a>
                                        </th>
                                        <td>
                                            @if ($player->clan())
                                                <a href="{{ url("clan", urlencode($player->clan()->name)) }}">{{ $player->clan()->name }}</a>
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            {{ $player->totalHoursOnline(0, True) }}
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $players->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection