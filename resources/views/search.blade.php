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
                            {{ Form::open(['url' => url('tee') , 'method' => 'get']) }}
                                <div class="form-group">
                                    {{ Form::label('tee_name', 'Tee name', ['class' => 'form-control-label']) }}
                                    @if ($errors->has('tee'))
                                        {{ Form::text('tee_name', null, array_merge(['class' => 'form-control is-invalid'], ['placeholder' => 'Tee name'])) }}
                                        <div class="invalid-feedback">{{ $errors->get('tee')[0] }}
                                            @if (session('teeSuggestions') !== null && !session('teeSuggestions')->isEmpty())
                                                , try one of the following :
                                                <ul class="list-group">
                                                    @foreach (session('teeSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url("tee", urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        {{ Form::text('tee_name', null, array_merge(['class' => 'mr-sm-3 form-control'], ['placeholder' => 'Tee name'])) }}
                                    @endif
                                </div>
                                <div class="form-group">
                                    {{ Form::submit('Submit', ['class' => 'btn btn-primary']) }}
                                </div>
                            {{ Form::close() }}
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
                            {{ Form::open(['url' => url('clan') , 'method' => 'get']) }}
                                <div class="form-group">
                                    {{ Form::label('clan_name', 'Clan name', ['class' => 'form-control-label']) }}
                                    @if ($errors->has('clan'))
                                        {{ Form::text('clan_name', null, array_merge(['class' => 'form-control is-invalid'], ['placeholder' => 'Clan name'])) }}
                                        <div class="invalid-feedback">{{ $errors->get('clan')[0] }}
                                            @if (session('clanSuggestions') !== null && !session('clanSuggestions')->isEmpty())
                                                , try one of the following :
                                                <ul class="list-group">
                                                    @foreach (session('clanSuggestions') as $suggestion)
                                                        <li class="list-group-item list-group-item-transparent">
                                                            <a href="{{ url("clan", urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                                                        </li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @else
                                        {{ Form::text('clan_name', null, array_merge(['class' => 'mr-sm-3 form-control'], ['placeholder' => 'Clan name'])) }}
                                    @endif
                                </div>
                                <div class="form-group">
                                    {{ Form::submit('Submit', ['class' => 'btn btn-primary']) }}
                                </div>
                            {{ Form::close() }}
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
                            {{ Form::open(['url' => url('server') , 'method' => 'get']) }}
                            <div class="form-group">
                                {{ Form::label('server_name', 'Server name', ['class' => 'form-control-label']) }}
                                @if ($errors->has('server'))
                                    {{ Form::text('server_name', null, array_merge(['class' => 'form-control is-invalid'], ['placeholder' => 'Server name'])) }}
                                    <div class="invalid-feedback">{{ $errors->get('server')[0] }}
                                        @if (session('serverSuggestions') !== null && !session('serverSuggestions')->isEmpty())
                                            , try one of the following :
                                            <ul class="list-group">
                                                @foreach (session('serverSuggestions') as $suggestion)
                                                    <li class="list-group-item list-group-item-transparent">
                                                        <a href="{{ url("server", [urlencode($suggestion['id']), urlencode($suggestion['name'])]) }}">{{ $suggestion['name'] }}</a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @else
                                    {{ Form::text('server_name', null, array_merge(['class' => 'mr-sm-3 form-control'], ['placeholder' => 'Server name'])) }}
                                @endif
                            </div>
                            <div class="form-group">
                                {{ Form::submit('Submit', ['class' => 'btn btn-primary']) }}
                            </div>
                            {{ Form::close() }}
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
                            {{ Form::open(['url' => url('mod') , 'method' => 'get']) }}
                            <div class="form-group">
                                {{ Form::label('mod_name', 'Mod name', ['class' => 'form-control-label']) }}
                                @if ($errors->has('mod'))
                                    {{ Form::text('mod_name', null, array_merge(['class' => 'form-control is-invalid'], ['placeholder' => 'Mod name'])) }}
                                    <div class="invalid-feedback">{{ $errors->get('mod')[0] }}
                                        @if (session('modSuggestions') !== null && !session('modSuggestions')->isEmpty())
                                            , try one of the following :
                                            <ul class="list-group">
                                                @foreach (session('modSuggestions') as $suggestion)
                                                    <li class="list-group-item list-group-item-transparent">
                                                        <a href="{{ url("mod", urlencode($suggestion['mod'])) }}">{{ $suggestion['mod'] }}</a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @else
                                    {{ Form::text('mod_name', null, array_merge(['class' => 'mr-sm-3 form-control'], ['placeholder' => 'Mod name'])) }}
                                @endif
                            </div>
                            <div class="form-group">
                                {{ Form::submit('Submit', ['class' => 'btn btn-primary']) }}
                            </div>
                            {{ Form::close() }}
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
                            {{ Form::open(['url' => url('map') , 'method' => 'get']) }}
                            <div class="form-group">
                                {{ Form::label('map_name', 'Map name', ['class' => 'form-control-label']) }}
                                @if ($errors->has('map'))
                                    {{ Form::text('map_name', null, array_merge(['class' => 'form-control is-invalid'], ['placeholder' => 'Map name'])) }}
                                    <div class="invalid-feedback">{{ $errors->get('map')[0] }}
                                        @if (session('mapSuggestions') !== null && !session('mapSuggestions')->isEmpty())
                                            , try one of the following :
                                            <ul class="list-group">
                                                @foreach (session('mapSuggestions') as $suggestion)
                                                    <li class="list-group-item list-group-item-transparent">
                                                        <a href="{{ url("map", urlencode($suggestion['map'])) }}">{{ $suggestion['map'] }}</a>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </div>
                                @else
                                    {{ Form::text('map_name', null, array_merge(['class' => 'mr-sm-3 form-control'], ['placeholder' => 'Map name'])) }}
                                @endif
                            </div>
                            <div class="form-group">
                                {{ Form::submit('Submit', ['class' => 'btn btn-primary']) }}
                            </div>
                            {{ Form::close() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
@section('scripts')
    <script>
        $(function() {
            $("#tee_name").autocomplete({
                source: "search/tee",
                minLength: 1,
                select: function( event, ui ) {
                    $(this).val(ui.item.id);
                }
            });

            $("#clan_name").autocomplete({
                source: "search/clan",
                minLength: 1,
                select: function( event, ui ) {
                    $(this).val(ui.item.id);
                }
            });

            $("#server_name").autocomplete({
                source: "search/server",
                minLength: 1,
                select: function( event, ui ) {
                    $(this).val(ui.item.id);
                }
            });

            $("#mod_name").autocomplete({
                source: "search/mod",
                minLength: 1,
                select: function( event, ui ) {
                    $(this).val(ui.item.id);
                }
            });

            $("#map_name").autocomplete({
                source: "search/map",
                minLength: 1,
                select: function( event, ui ) {
                    $(this).val(ui.item.id);
                }
            });
        });
    </script>
@endsection