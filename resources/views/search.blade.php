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
                                <div class="mb-3">
                                    <label for="tee_name" class="form-label">Tee name</label>
                                    @if ($errors->has('tee'))
                                        <input type="text" name="tee_name" id="tee_name" data-autocomplete="{{ url('search/tee') }}"
                                               class="form-control is-invalid" placeholder="Tee name">
                                        <div class="invalid-feedback">{{ $errors->get('tee')[0] }}
                                            @if (filled(session('teeSuggestions')))
                                                , try one of the following:
                                                <ul class="list-group">
                                                    @foreach (session('teeSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url('tee', urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input type="text" name="tee_name" id="tee_name" data-autocomplete="{{ url('search/tee') }}"
                                               class="form-control" placeholder="Tee name">
                                    @endif
                                </div>
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-primary">Submit</button>
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
                                <div class="mb-3">
                                    <label for="clan_name" class="form-label">Clan name</label>
                                    @if ($errors->has('clan'))
                                        <input type="text" name="clan_name" id="clan_name" data-autocomplete="{{ url('search/clan') }}"
                                               class="form-control is-invalid" placeholder="Clan name">
                                        <div class="invalid-feedback">{{ $errors->get('clan')[0] }}
                                            @if (filled(session('clanSuggestions')))
                                                , try one of the following:
                                                <ul class="list-group">
                                                    @foreach (session('clanSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url('clan', urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input type="text" name="clan_name" id="clan_name" data-autocomplete="{{ url('search/clan') }}"
                                               class="form-control" placeholder="Clan name">
                                    @endif
                                </div>
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-primary">Submit</button>
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
                                <div class="mb-3">
                                    <label for="server_name" class="form-label">Server name</label>
                                    @if ($errors->has('server'))
                                        <input type="text" name="server_name" id="server_name" data-autocomplete="{{ url('search/server') }}"
                                               class="form-control is-invalid" placeholder="Server name">
                                        <div class="invalid-feedback">{{ $errors->get('server')[0] }}
                                            @if (filled(session('serverSuggestions')))
                                                , try one of the following:
                                                <ul class="list-group">
                                                    @foreach (session('serverSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url('server', [urlencode($suggestion['id']), urlencode($suggestion['name'])]) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input type="text" name="server_name" id="server_name" data-autocomplete="{{ url('search/server') }}"
                                               class="form-control" placeholder="Server name">
                                    @endif
                                </div>
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-primary">Submit</button>
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
                            <strong>Mod statistics</strong>
                        </div>
                        <div class="block-body">
                            <form action="{{ url('mod') }}" method="get">
                                <div class="mb-3">
                                    <label for="mod_name" class="form-label">Mod name</label>
                                    @if ($errors->has('mod'))
                                        <input type="text" name="mod_name" id="mod_name" data-autocomplete="{{ url('search/mod') }}"
                                               class="form-control is-invalid" placeholder="Mod name">
                                        <div class="invalid-feedback">{{ $errors->get('mod')[0] }}
                                            @if (filled(session('modSuggestions')))
                                                , try one of the following:
                                                <ul class="list-group">
                                                    @foreach (session('modSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url('mod', urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input type="text" name="mod_name" id="mod_name" data-autocomplete="{{ url('search/mod') }}"
                                               class="form-control" placeholder="Mod name">
                                    @endif
                                </div>
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-primary">Submit</button>
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
                            <strong>Map statistics</strong>
                        </div>
                        <div class="block-body">
                            <form action="{{ url('map') }}" method="get">
                                <div class="mb-3">
                                    <label for="map_name" class="form-label">Map name</label>
                                    @if ($errors->has('map'))
                                        <input type="text" name="map_name" id="map_name" data-autocomplete="{{ url('search/map') }}"
                                               class="form-control is-invalid" placeholder="Map name">
                                        <div class="invalid-feedback">{{ $errors->get('map')[0] }}
                                            @if (filled(session('mapSuggestions')))
                                                , try one of the following:
                                                <ul class="list-group">
                                                    @foreach (session('mapSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url('map', urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        <input type="text" name="map_name" id="map_name" data-autocomplete="{{ url('search/map') }}"
                                               class="form-control" placeholder="Map name">
                                    @endif
                                </div>
                                <div class="mb-3">
                                    <button type="submit" class="btn btn-primary">Submit</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
