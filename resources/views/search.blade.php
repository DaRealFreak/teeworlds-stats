@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Charts</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title">
                            <strong>Player statistics</strong>
                        </div>
                        <div class="block-body">
                            <form action="{{ url('tee') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Tee name</label>
                                    @if ($errors->has('tee'))
                                        <input name="tee_name" id="tee_name" placeholder="Tee name"
                                               class="form-control is-invalid" type="text">
                                        <div class="invalid-feedback">{{ $errors->get('tee')[0] }}
                                            @if (session('teeSuggestions') !== null && !session('teeSuggestions')->isEmpty())
                                                , try one of the following :
                                                <ul class="list-group">
                                                    @foreach (session('teeSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url("tee/" . $suggestion['name']) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input name="tee_name" id="tee_name" placeholder="Tee name"
                                               class="mr-sm-3 form-control" type="text">
                                    @endif
                                </div>
                                <div class="form-group">
                                    <input value="Submit" class="btn btn-primary" type="submit">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title">
                            <strong>Clan statistics</strong>
                        </div>
                        <div class="block-body">
                            <form action="{{ url('clan') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Clan name</label>
                                    @if ($errors->has('clan'))
                                        <input name="clan_name" id="clan_name" placeholder="Clan name"
                                               class="form-control is-invalid" type="text">
                                        <div class="invalid-feedback">{{ $errors->get('clan')[0] }}
                                            @if (session('clanSuggestions') !== null && !session('clanSuggestions')->isEmpty())
                                                , try one of the following :
                                                <ul class="list-group">
                                                    @foreach (session('clanSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url("clan/" . $suggestion['name']) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input name="clan_name" id="clan_name" placeholder="Clan name"
                                               class="mr-sm-3 form-control" type="text">
                                    @endif
                                </div>
                                <div class="form-group">
                                    <input value="Submit" class="btn btn-primary" type="submit">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title">
                            <strong>Server statistics</strong>
                        </div>
                        <div class="block-body">
                            <form action="{{ url('server') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Server name</label>
                                    @if ($errors->has('server'))
                                        <input name="server_name" id="server_name" placeholder="Server name"
                                               class="form-control is-invalid" type="text">
                                        <div class="invalid-feedback">{{ $errors->get('server')[0] }}
                                            @if (session('serverSuggestions') !== null && !session('serverSuggestions')->isEmpty())
                                                , try one of the following :
                                                <ul class="list-group">
                                                    @foreach (session('serverSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url("server/" . $suggestion['name']) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input name="server_name" id="server_name" placeholder="Server name"
                                               class="mr-sm-3 form-control" type="text">
                                    @endif
                                </div>
                                <div class="form-group">
                                    <input value="Submit" class="btn btn-primary" type="submit">
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
