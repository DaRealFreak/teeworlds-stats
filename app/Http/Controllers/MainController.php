<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerMaps;
use App\Models\PlayerMods;
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
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($amount = 16, $displayOthers = True)
    {
        $results = [];
        foreach (PlayerMaps::orderBy('times')->get() as $map) {
            $mapName = $map->getAttribute('map');
            if (count($results) >= $amount) {
                if (!$displayOthers) {
                    break;
                }
                $mapName = "others";
            }
            if (array_key_exists($mapName, $results)) {
                $results[$mapName] += $map->getAttribute('times') * 5;
            } else {
                $results[$mapName] = $map->getAttribute('times') * 5;
            }
        }
        return $results;
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
        $results = [];
        foreach (PlayerMods::orderBy('times')->get() as $mod) {
            $modName = $mod->getAttribute('mod');
            if (count($results) >= $amount) {
                if (!$displayOthers) {
                    break;
                }
                $modName = "others";
            }
            if (array_key_exists($modName, $results)) {
                $results[$modName] += $mod->getAttribute('times') * 5;
            } else {
                $results[$modName] = $mod->getAttribute('times') * 5;
            }
        }
        return $results;
    }

    public function chartPlayedCountries($amount = 31, $displayOthers = True)
    {
        $results = [];
        foreach (Player::all()->sortByDesc('count(country)') as $player) {
            /** @var Player $player */
            $playerName = $player->getAttribute('country');
            if (count($results) >= $amount) {
                if (!$displayOthers) {
                    break;
                }
                $playerName = "others";
            }
            if (array_key_exists($playerName, $results)) {
                $results[$playerName] += 1;
            } else {
                $results[$playerName] = 1;
            }
        }
        return $results;
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home()
    {
        return view('main');
    }
}
