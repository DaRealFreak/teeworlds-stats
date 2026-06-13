<?php

namespace App\Http\Controllers;

use App\Models\Clan;
use App\Models\DailySummary;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerSession;
use App\Models\Server;
use App\Models\PlayerHistory;
use App\Service\MapArchiver;
use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Http\Request;

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
                'maps' => count(Map::groupBy(['name'])->get()),
                'mods' => count(Mod::groupBy(['name'])->get()),
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
     * The player list, sortable across the whole set. Played time, AFK share and the current
     * clan are joined as grouped aggregates so the sort works over every player (not just the
     * page) — which also drops the old per-row clan()/totalHoursOnline() lookups.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function players(Request $request)
    {
        $dir = strtolower((string) $request->query('dir')) === 'desc' ? 'desc' : 'asc';
        $sort = (string) $request->query('sort');

        $query = Player::query()
            ->leftJoinSub(
                PlayerHistory::query()->selectRaw('player_id, SUM(minutes) as minutes')->groupBy('player_id'),
                'played', 'played.player_id', '=', 'players.id'
            )
            ->leftJoinSub(
                PlayerSession::query()->selectRaw('player_id, SUM(afk_minutes) as afk_sum, SUM(afk_tracked_minutes) as afk_tracked')->groupBy('player_id'),
                'afk', 'afk.player_id', '=', 'players.id'
            )
            ->leftJoinSub(
                PlayerClanHistory::query()->whereNull('left_at')->selectRaw('player_id, MAX(clan_id) as clan_id')->groupBy('player_id'),
                'cur_clan', 'cur_clan.player_id', '=', 'players.id'
            )
            ->leftJoin('clans', 'clans.id', '=', 'cur_clan.clan_id')
            ->select('players.*', 'played.minutes as played_minutes', 'afk.afk_sum', 'afk.afk_tracked', 'clans.name as clan_name');

        // afk share over idle-tracked time; null when nothing was tracked → forced to the bottom
        $afkRatio = '(afk.afk_sum * 1.0 / NULLIF(afk.afk_tracked, 0))';

        match ($sort) {
            'played' => $query->orderByRaw('COALESCE(played.minutes, 0) ' . $dir),
            'afk'    => $query->orderByRaw($afkRatio . ' IS NULL ASC')->orderByRaw($afkRatio . ' ' . $dir),
            'clan'   => $query->orderByRaw('clans.name IS NULL ASC')->orderByRaw('clans.name ' . $dir),
            default  => $query->orderBy('players.name', $dir), // 'name' and any unknown sort
        };

        return view('list.player')->with('players', $query->paginate(50)->withQueryString());
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
     * the live server browser: the servers seen in the most recent scrape (online),
     * with their current map/mod eager-loaded and ordered by current player count.
     * currentPlayers is loaded lazily per server (its relation groups by players.id,
     * which makes withCount / cross-server eager loading unsafe) and the page is
     * response-cached, so the per-server count query is cheap in practice.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function liveServers()
    {
        $servers = Server::where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5))
            ->with(['currentServerHistory.map', 'currentServerHistory.mod', 'addresses'])
            ->get()
            ->sortByDesc(fn (Server $server) => $server->currentPlayers->count())
            ->values();

        return view('list.live')->with('servers', $servers);
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function mods()
    {
        return view('list.mods')
            ->with('mods', Mod::orderBy('name')->paginate(50));
    }

    /**
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function maps(MapArchiver $archiver)
    {
        $maps = Map::orderBy('name')->paginate(50);
        $statuses = $maps->getCollection()->mapWithKeys(
            fn (Map $map) => [$map->id => $archiver->statusFor($map)]
        );

        return view('list.maps', compact('maps', 'statuses'));
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
            $results[$playedMap->map->getAttribute('name')] = (int)$playedMap->getAttribute('sum_minutes');
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
            $results[$playedMod->mod->getAttribute('name')] = (int)$playedMod->getAttribute('sum_minutes');
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
     * build the ranked country breakdown for the general statistics page: the top
     * $amount real countries (with flag codes) plus a single folded bucket for
     * players without a country and the long tail beyond the top entries
     *
     * @param int $amount
     * @return array{countries: array<int, array{name: string, code: string|null, count: int}>, unknown: int, max: int, total: int}
     */
    public function playingCountries($amount = 8)
    {
        $players = Player::selectRaw('`players`.*, COUNT(`players`.`country`) as `count_countries`')
            ->groupBy('country')
            ->orderByRaw('COUNT(`players`.`country`) DESC')->get();

        $counts = [];
        /** @var Player $player */
        foreach ($players as $player) {
            $counts[(string)$player->getAttribute('country')] = (int)$player->getAttribute('count_countries');
        }

        return ChartUtility::rankCountries($counts, $amount);
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
