<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
            'online' => DB::table('players')->where('updated_at', '>=', Carbon::now()->subMinutes(5))->count(),
            'players' => DB::table('players')->count(),
            'servers' => DB::table('servers')->count(),
            'clans' => DB::table('clans')->count(),
            'countries' => count(DB::table('players')->distinct('country')->get()),
            'maps' => count(DB::table('player_maps')->distinct('map')->get()),
            'mods' => count(DB::table('player_mods')->distinct('mod')->get()),
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
