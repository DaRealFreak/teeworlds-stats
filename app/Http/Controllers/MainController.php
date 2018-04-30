<?php

namespace App\Http\Controllers;

class MainController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function about()
    {
        return view('about');
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
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

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home()
    {
        return view('main');
    }
}
