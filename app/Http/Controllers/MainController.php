<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerMap;
use App\Models\PlayerMod;
use App\Utility\ChartUtility;
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
        return view('general')
            ->with('general', [
                'online' => DB::table('players')->where('updated_at', '>=', Carbon::now()->subMinutes(10))->count(),
                'players' => DB::table('players')->count(),
                'servers' => DB::table('servers')->count(),
                'clans' => DB::table('clans')->count(),
                'countries' => count(DB::table('players')->groupBy(['country'])->get()),
                'maps' => count(DB::table('player_maps')->groupBy(['map'])->get()),
                'mods' => count(DB::table('player_mods')->groupBy(['mod'])->get()),
            ])
            ->with('chartPlayedMaps', $this->chartPlayedMaps())
            ->with('chartPlayedCountries', $this->chartPlayedCountries())
            ->with('chartPlayedMods', $this->chartPlayedMods());
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home()
    {
        return view('main');
    }

    /**
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($amount = 10, $displayOthers = True)
    {
        return ChartUtility::chartValues(Map::all(), 'map', 'minutes', $amount, $displayOthers);
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMods($amount = 10, $displayOthers = False)
    {
        return ChartUtility::chartValues(Mod::all(), 'mod', 'minutes', $amount, $displayOthers);
    }

    /**
     * build an array of the playing countries for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedCountries($amount = 10, $displayOthers = True)
    {
        return ChartUtility::chartValues(Player::all(), 'country', null, $amount, $displayOthers);
    }
}
