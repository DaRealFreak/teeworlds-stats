<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="all,follow">
    <meta name="author" content="DaRealFreak">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <link rel="shortcut icon" href="{{ asset('images/favicon.ico') }}" type="image/x-icon">
    <link rel="icon" href="{{ asset('images/favicon.ico') }}" type="image/x-icon">

    <!-- Prebuilt vendor stylesheets (copied by vite.config.js), kept out of the Sass bundle -->
    <link rel="stylesheet" href="{{ asset('build/css/font-awesome.min.css') }}">
    <link rel="stylesheet" href="{{ asset('build/css/flag-icons.min.css') }}">

    @vite([
        'resources/assets/sass/app.scss',
        'resources/assets/sass/font-awesome.scss',
        'resources/assets/js/app.ts',
    ])

    @yield('head')
</head>
<body>
<div id="app">
    <header class="header">
        <nav class="navbar navbar-expand-lg">
            <div class="container-fluid d-flex align-items-center justify-content-between">
                <div class="navbar-header">
                    <!-- Navbar Header-->
                    <a href="{{ url('/') }}" title="{{ config('app.name', 'Laravel') }}" class="navbar-brand">
                        <div class="brand-text brand-big visible text-uppercase">
                            <img src="{{ asset('images/teeworlds-stats.png') }}" class="img-fluid"/>
                        </div>
                        <div class="brand-text brand-sm">
                            <img src="{{ asset('images/teeworlds-stats.png') }}" class="img-fluid"/>
                        </div>
                    </a>
                    <!-- Sidebar Toggle Btn-->
                    <button class="sidebar-toggle"><i class="fa fa-long-arrow-left"></i></button>
                </div>
                <form class="global-search" role="search" autocomplete="off"
                      onsubmit="return false;">
                    <i class="fa fa-search global-search__icon" aria-hidden="true"></i>
                    <input type="search" id="global_search_input" class="global-search__input"
                           placeholder="Search players, clans, servers…" aria-label="Global search"
                           data-global-search="{{ url('search/global') }}">
                    <ul class="global-search__menu" id="global_search_menu" hidden></ul>
                </form>
                <div class="right-menu list-inline no-margin-bottom">
                    @guest
                        <div class="list-inline-item">
                            <a class="nav-link" href="{{ route('login') }}">{{ __('Login') }}</a>
                        </div>
                    @else
                        <li class="nav-item dropdown">
                            <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button"
                               data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                {{ Auth::user()->name }} <span class="caret"></span>
                            </a>

                            <div class="dropdown-menu" aria-labelledby="navbarDropdown">
                                <a class="dropdown-item" href="{{ route('logout') }}"
                                   onclick="event.preventDefault();
                                                     document.getElementById('logout-form').submit();">
                                    {{ __('Logout') }}
                                </a>

                                <form id="logout-form" action="{{ route('logout') }}" method="POST"
                                      style="display: none;">
                                    @csrf
                                </form>
                            </div>
                        </li>
                    @endguest
                </div>
            </div>
        </nav>
    </header>

    <div class="d-flex align-items-stretch">
        <!-- Sidebar Navigation-->
        <nav id="sidebar">
            <!-- Sidebar Navigation Menus-->
            <span class="heading heading-first">Main</span>
            <ul class="list-unstyled">
                <li><a href="{{ url('/') }}"> <i class="icon-home"></i>Home </a></li>
                <li><a href="{{ url('general') }}"> <i class="fa fa-bar-chart"></i>Game Statistics </a></li>
                <li><a href="{{ url('search') }}"> <i class="icon-magnifying-glass-browser"></i>Search </a></li>
                <li><a href="{{ url('serverbrowser') }}"> <i class="fa fa-server"></i>Server Browser </a></li>
            </ul>

            <!-- List View Navigation Entries -->
            <span class="heading">Overview</span>
            <ul class="list-unstyled">
                <li>
                    <a href="#list_view_dropdown" aria-expanded="false" data-bs-toggle="collapse" data-bs-target="#list_view_dropdown">
                        <i class="icon-grid"></i>Lists
                    </a>
                    <ul id="list_view_dropdown" class="collapse list-unstyled ">
                        <li><a href="{{ url('tees') }}">Tees</a></li>
                        <li><a href="{{ url('clans') }}">Clans</a></li>
                        <li><a href="{{ url('servers') }}">Servers</a></li>
                        <li><a href="{{ url('mods') }}">Mods</a></li>
                        <li><a href="{{ url('maps') }}">Maps</a></li>
                    </ul>
                </li>
            </ul>

            @php
                // if we are on the player page and the player has a clan define the clan as local variable
                if (!isset($server) && isset($player) && $player->clan()) {
                    $clan = $player->clan();
                }
            @endphp

            <!-- Clan Navigation Entry -->
            @if(!empty($clan->name))
                <span class="heading">Clan</span>
                <ul class="list-unstyled">
                    <li>
                        <a href="{{ url('clan', urlencode($clan->name))}}">
                            <i class="icon-chart"></i>
                            {{ $clan->name }}
                        </a>
                    </li>
                </ul>
            @endif

            <!-- Server Navigation Entry -->
            @if(!empty($server))
                <span class="heading">Server</span>
                <ul class="list-unstyled">
                    <li>
                        <a href="{{ url("server", [urlencode($server->id), urlencode($server->name)]) }}">
                            <i class="icon-chart"></i>
                            {{ $server->name }}
                        </a>
                    </li>
                </ul>
            @endif

            <span class="heading">Extras</span>
            <ul class="list-unstyled">
                <li><a href="{{ url('about') }}"> <i class="icon-padnote"></i>About </a></li>
            </ul>
        </nav>
        <div class="page-content">
            @yield('content')
            <footer class="footer">
                <div class="footer__block block no-margin-bottom">
                    <div class="container-fluid text-center">
                        <p class="no-margin-bottom">2018 &copy; DaRealFreak. Design based on a Bootstrapious template.</p>
                    </div>
                </div>
            </footer>
        </div>
        <!-- Sidebar Navigation end-->
    </div>
</div>
@yield('scripts')
</body>
</html>
