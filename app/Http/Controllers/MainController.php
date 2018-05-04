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
        foreach (PlayerMaps::all() as $map) {
            $mapName = $map->getAttribute('map');

            if (array_key_exists($mapName, $results)) {
                $results[$mapName] += $map->getAttribute('times') * 5;
            } else {
                $results[$mapName] = $map->getAttribute('times') * 5;
            }
        }
        $this->applyLimits($results, $amount, $displayOthers);

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
        foreach (PlayerMods::all() as $mod) {
            $modName = $mod->getAttribute('mod');

            if (array_key_exists($modName, $results)) {
                $results[$modName] += $mod->getAttribute('times') * 5;
            } else {
                $results[$modName] = $mod->getAttribute('times') * 5;
            }
        }
        $this->applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    public function chartPlayedCountries($amount = 31, $displayOthers = True)
    {
        $results = [];
        foreach (Player::all() as $player) {
            /** @var Player $player */
            $playerName = $player->getAttribute('country');
            if (array_key_exists($playerName, $results)) {
                $results[$playerName] += 1;
            } else {
                $results[$playerName] = 1;
            }
        }
        $this->applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * function to apply the amount and displayOthers limitation and sort the
     * results by value
     *
     * @param $results
     * @param $amount
     * @param $displayOthers
     */
    private function applyLimits(&$results, $amount, $displayOthers)
    {
        if ($amount && count($results) > $amount) {
            arsort($results, true);
            $i = 0;
            foreach ($results as $map => $times) {
                if ($i >= $amount) {
                    if (isset($results['others'])) {
                        $results['others'] += $times;
                    } else {
                        $results['others'] = $times;
                    }
                    unset($results[$map]);
                }
                $i++;
            }
            if (!$displayOthers) {
                unset($results['others']);
            }
        }
        arsort($results, true);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home()
    {
        return view('main');
    }
}
