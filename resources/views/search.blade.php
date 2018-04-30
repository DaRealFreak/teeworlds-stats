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
                            <!--suppress HtmlUnknownTarget -->
                            <form action="{{ url('tee') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Tee name</label>
                                    <input name="tee_name" id="tee_name" placeholder="Tee name"
                                           class="mr-sm-3 form-control" type="text">
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
                            <!--suppress HtmlUnknownTarget -->
                            <form action="{{ url('clan') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Clan name</label>
                                    <input name="clan_name" id="clan_name" placeholder="Clan name"
                                           class="mr-sm-3 form-control" type="text">
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
                            <!--suppress HtmlUnknownTarget -->
                            <form action="{{ url('server') }}" method="get">
                                <div class="form-group">
                                    <label class="form-control-label">Server name</label>
                                    <input name="server_name" id="server_name" placeholder="Server name"
                                           class="mr-sm-3 form-control" type="text">
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
