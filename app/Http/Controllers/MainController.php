<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\PlayerMapRecord;
use App\Models\PlayerModRecord;
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
                'maps' => count(DB::table('maps')->groupBy(['map'])->get()),
                'mods' => count(DB::table('mods')->groupBy(['mod'])->get()),
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
            $mapName = $modRecord->mod->getAttribute('mod');
            $value = $modRecord->getAttribute('minutes');

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
            $mapName = $player->getAttribute('country');

            if (array_key_exists($mapName, $results)) {
                $results[$mapName] += 1;
            } else {
                $results[$mapName] = 1;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
}
