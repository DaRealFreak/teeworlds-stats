@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">About teeworlds-stats</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="messages-block block">
                        <div class="messages">
                            <a href="{{ url('https://github.com/DaRealFreak/teeworlds-stats') }}">
                                <img style="position: absolute; top: 0; right: 12px; border: 0; z-index: 1;"
                                     src="{{ url('https://s3.amazonaws.com/github/ribbons/forkme_right_darkblue_121621.png') }}"
                                     alt="Fork me on GitHub" class="img-fluid"></a>
                            <div class="form-group-material">
                                <h3>Teeworlds</h3>
                                <div>
                                    <a href="{{ url('https://www.teeworlds.com/') }}">Teeworlds</a>
                                    is an opensource multiplayer game.
                                </div>
                            </div>

                            <div class="form-group-material">
                                <h3>Choose which statistics to display</h3>
                                <div>You are now free to choose whever or not to display these statistics.</div>
                                <div>Just access your account via facebook login to setup your preferences.</div>
                            </div>

                            <div class="form-group-material">
                                <h3>Facebook login</h3>
                                <div>Facebook is only used as a mean of identification.</div>
                                <div>Teeworlds-stats will not publish anything on your wall.</div>
                                <div>Teeworlds will not even appear on your facebook profile.</div>
                            </div>

                            <div class="form-group-material">
                                <h3>If a clan/server/tee is missing</h3>
                                <div>It could be because this clan/server/tee</div>
                                <div>
                                    <ul>
                                        <li>Has not yet been online more than {{ env('CRONTASK_INTERVAL') }} minutes</li>
                                        <li>Was not reachable through the official master servers</li>
                                        <li>Was misspelled</li>
                                    </ul>
                                </div>
                                <div>In the latter case, try typing only part of the name and see the suggestions.</div>
                            </div>

                            <div class="form-group-material">
                                <h3>If your nickname is associated with another clan/country</h3>
                                <div>Then, a second player is probably using the same nickname.</div>
                                <div>As a result, both of your statistics were merged.</div>
                            </div>

                            <div class="form-group-material">
                                <h3>Where do these statistics come from?</h3>
                                <div>Teeworlds servers are looked up every {{ env('CRONTASK_INTERVAL') }} minutes to gather data about players,
                                    clans, mods, etc...
                                </div>
                                <div>Results are displayed on demand.</div>
                            </div>

                            <div class="form-group-material">
                                <h3>Opensource</h3>
                                <div>
                                    Teeworlds-stats is opensource,
                                    <a href="{{ url('https://github.com/DaRealFreak/teeworlds-stats') }}">
                                        check it out on github.
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
