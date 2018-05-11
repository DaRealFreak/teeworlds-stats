<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerMapRecord;
use App\Models\PlayerModRecord;
use App\Models\Server;
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
                'online' => Player::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') + 1))->count(),
                'players' => Player::count(),
                'servers' => Server::count(),
                'clans' => Clan::count(),
                'countries' => count(Player::groupBy(['country'])->get()),
                'maps' => count(Map::groupBy(['map'])->get()),
                'mods' => count(Mod::groupBy(['mod'])->get()),
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
        $results = [];
        /** @var PlayerMapRecord $mapRecord */
        foreach (PlayerMapRecord::all() as $mapRecord) {
            $mapName = $mapRecord->map->getAttribute('map');
            $value = $mapRecord->getAttribute('minutes');

            if (array_key_exists($mapName, $results)) {
                $results[$mapName] += $value;
            } else {
                $results[$mapName] = $value;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
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
        $results = [];
        /** @var PlayerModRecord $modRecord */
        foreach (PlayerModRecord::all() as $modRecord) {
            $modName = $modRecord->mod->getAttribute('mod');
            $value = $modRecord->getAttribute('minutes');

            if (array_key_exists($modName, $results)) {
                $results[$modName] += $value;
            } else {
                $results[$modName] = $value;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        // sort by key if radar chart is used(>= 3 mods), else it looks pretty bad normally
        if (count($results) >= 3) {
            ksort($results);
        }

        return $results;
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
        $results = [];
        foreach (Player::all() as $player) {
            $country = $player->getAttribute('country');

            if (array_key_exists($country, $results)) {
                $results[$country] += 1;
            } else {
                $results[$country] = 1;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
}
