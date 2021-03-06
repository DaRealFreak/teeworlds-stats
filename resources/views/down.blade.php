@extends('layouts.app')

@section('content')
    <!-- Page Header-->
    <div class="page-header no-margin-bottom">
        <div class="container-fluid">
            <h2 class="h5 no-margin-bottom">Error</h2>
        </div>
    </div>
    <section class="section-content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-12">
                    <div class="line-chart block chart">
                        <div class="title"><strong>Statistics could not be displayed</strong></div>
                        <div class="text">
                        <span>
                            <strong>Error connecting to the database : </strong>
                            The server may be overloaded. Please try again later.
                        </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
