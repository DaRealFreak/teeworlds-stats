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

    <!-- Scripts -->
    <script src="{{ asset('js/app.js') }}"></script>

    <!-- Styles -->
    <!-- custom font css since putting it into the app.css would cause a small lag before the font ions are appearing -->
    <link href="{{ asset('css/font.css') }}" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">

    @yield('head')
</head>
<body>
<div id="app">
    <header class="header">
        <nav class="navbar navbar-expand-lg">
            <div class="search-panel">
                <div class="search-inner d-flex align-items-center justify-content-center">
                    <div class="close-btn">Close <i class="fa fa-close"></i></div>
                    <form id="searchForm" action="#">
                        <div class="form-group">
                            <input name="search" placeholder="What are you searching for..." type="search">
                            <button type="submit" class="submit">Search</button>
                        </div>
                    </form>
                </div>
            </div>
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
                <div class="right-menu list-inline no-margin-bottom">
                    @guest
                        <div class="list-inline-item">
                            <a class="nav-link" href="{{ route('login') }}">{{ __('Login') }}</a>
                        </div>
                        <div class="list-inline-item">
                            <a class="nav-link" href="{{ route('register') }}">{{ __('Register') }}</a>
                        </div>
                    @else
                        <li class="nav-item dropdown">
                            <a id="navbarDropdown" class="nav-link dropdown-toggle" href="#" role="button"
                               data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" v-pre>
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
            <!-- Sidebar Navidation Menus--><span class="heading">Main</span>
            <ul class="list-unstyled">
                <li><a href="{{ url('/') }}"> <i class="icon-home"></i>Home </a></li>
                <li><a href="{{ url('general') }}"> <i class="fa fa-bar-chart"></i>Game Statistics </a></li>
                <li><a href="{{ url('search') }}"> <i class="icon-magnifying-glass-browser"></i>Search </a></li>
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
                        <!-- Please do not remove the backlink to us unless you support us at https://bootstrapious.com/donate. It is part of the license conditions. Thank you for understanding :)-->
                        <p class="no-margin-bottom">2018 &copy; DaRealFreak. Design by
                            <a href="{{ url('https://bootstrapious.com') }}">Bootstrapious</a>.
                        </p>
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
