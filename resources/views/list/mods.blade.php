@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Mod list</h2>
        </div>
    </div>

    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="block">
                        <div class="title"><strong>Keeping track of:</strong></div>
                        <div class="table-responsive">
                            <table class="table table-striped table-hover" id="mod_table">
                                <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Dummy</th>
                                </tr>
                                </thead>
                                <tbody>
                                @foreach($mods as $mod)
                                    <tr>
                                        <th scope="row">
                                            <a href="{{ url("mod", urlencode($mod->name)) }}">{{ $mod->name }}</a>
                                        </th>
                                        <td>
                                            Dummy Entry
                                        </td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            {{ $mods->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection