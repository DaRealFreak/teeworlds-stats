<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerMaps;
use App\Models\PlayerMods;
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
                'countries' => count(DB::table('players')->distinct('country')->get()),
                'maps' => count(DB::table('player_maps')->distinct('map')->get()),
                'mods' => count(DB::table('player_mods')->distinct('mod')->get()),
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
    public function chartPlayedMaps($amount = 16, $displayOthers = True)
    {
        return ChartUtility::chartValues(PlayerMaps::all(), 'map', 'times', 5, $amount, $displayOthers);
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMods($amount = 16, $displayOthers = False)
    {
        return ChartUtility::chartValues(PlayerMods::all(), 'mod', 'times', 5, $amount, $displayOthers);
    }

    /**
     * build an array of the playing countries for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedCountries($amount = 31, $displayOthers = True)
    {
        return ChartUtility::chartValues(Player::all(), 'country', null, 1, $amount, $displayOthers);
    }
}
