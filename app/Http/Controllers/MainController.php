<?php

namespace App\Http\Controllers;

class MainController extends Controller
{
    public function about()
    {
        return view('about');
    }

    public function general()
    {
        return view('general')->with('general', [
            'online' => 0,
            'players' => 0,
            'servers' => 0,
            'clans' => 0,
            'countries' => 0,
            'maps' => 0,
            'mods' => 0
        ]);
    }

    public function home()
    {
        return view('main');
    }
}
