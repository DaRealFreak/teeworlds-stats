<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\DailySummary;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use App\Models\PlayerHistory;
use App\Utility\ChartUtility;
use Carbon\Carbon;

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
                'online' => Player::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5))->count(),
                'players' => Player::count(),
                'servers' => Server::count(),
                'clans' => Clan::count(),
                'countries' => count(Player::groupBy(['country'])->get()),
                'maps' => count(Map::groupBy(['map'])->get()),
                'mods' => count(Mod::groupBy(['mod'])->get()),
            ])
            ->with('dailySummary', DailySummary::firstOrCreate(['date' => Carbon::today()]))
            ->with('controller', $this);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function home()
    {
        return view('main');
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function players()
    {
        return view('list.player')
            ->with('players', Player::orderBy('name')->paginate(50));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function clans()
    {
        return view('list.clan')
            ->with('clans', Clan::orderBy('name')->paginate(50));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function servers()
    {
        return view('list.server')
            ->with('servers', Server::orderBy('name')->paginate(50));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function mods()
    {
        return view('list.mods')
            ->with('mods', Mod::orderBy('mod')->paginate(50));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function maps()
    {
        return view('list.maps')
            ->with('maps', Map::orderBy('map')->paginate(50));
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
        $playedMaps = PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes')->get();

        /** @var PlayerHistory $playedMap */
        foreach ($playedMaps as $playedMap) {
            $results[$playedMap->map->getAttribute('map')] = (int)$playedMap->getAttribute('sum_minutes');
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
        $playedMods = PlayerHistory::selectRaw('`player_histories`.*, SUM(`player_histories`.`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')->get();

        /** @var PlayerHistory $playedMod */
        foreach ($playedMods as $playedMod) {
            $results[$playedMod->mod->getAttribute('mod')] = (int)$playedMod->getAttribute('sum_minutes');
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
        $players = Player::selectRaw('`players`.*, COUNT(`players`.`country`) as `count_countries`')
            ->groupBy('country')
            ->orderByRaw('COUNT(`players`.`country`) DESC')->get();

        /** @var Player $player */
        foreach ($players as $player) {
            $results[$player->getAttribute('country')] = (int)$player->getAttribute('count_countries');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * @return Player[]|\Illuminate\Database\Eloquent\Collection
     */
    public function playersCreatedLast24Hours()
    {
        return Player::where('created_at', '>=', Carbon::now()->subDay())->get();
    }

    /**
     * @return Player[]|\Illuminate\Database\Eloquent\Collection
     */
    public function playersSeenLast24Hours()
    {
        return Player::where('last_seen', '>=', Carbon::now()->subDay())->get();
    }
}
