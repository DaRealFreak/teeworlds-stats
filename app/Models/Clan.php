<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Khill\Duration\Duration;

/**
 * App\Models\Clan
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Player[] $players
 * @mixin \Eloquent
 */
class Clan extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * return collection of players who are online
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function online()
    {
        return $this->players()->where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5))->get();
    }

    /**
     * Get the player records associated with the clan
     * players who were seen recently appear on top of the list
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function players()
    {
        return $this->belongsToMany(Player::class, (new PlayerClanHistory)->getTable(), 'clan_id', 'player_id')
            ->where('left_at', null)
            ->orderByRaw('last_seen >= ? DESC', [(string)Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)])
            ->orderBy('name');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function exPlayers()
    {
        $exPlayers = $this->belongsToMany(Player::class, (new PlayerClanHistory)->getTable(), 'clan_id', 'player_id')
            ->where('left_at', '!=', null)
            ->orderByRaw('last_seen >= ? DESC', [(string)Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)])
            ->orderBy('name')
            ->distinct()
            ->get()
            ->unique();

        /** @var Player $player */
        foreach ($exPlayers as $playerIndex => $player) {
            if ($player->clan() && $player->clan()->getAttribute('name') == $this->getAttribute('name')) {
                $exPlayers->forget($playerIndex);
            }
        }

        return $exPlayers;
    }

    /**
     * function to retrieve the most played map of the guild
     *
     * @param int $duration
     * @return PlayerHistory|\Illuminate\Database\Query\Builder
     */
    public function mostPlayedMaps($duration=0)
    {
        $maps = PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->join((new PlayerClanHistory)->getTable(), (new PlayerHistory)->getTable() . '.player_id', '=', (new PlayerClanHistory)->getTable() . '.player_id')
            ->where((new PlayerClanHistory)->getTable() . '.clan_id', '=', $this->getAttribute('id'))
            ->where((new PlayerClanHistory)->getTable() . '.left_at', null)
            ->groupBy(DB::raw((new PlayerHistory)->getTable() . '.map_id'))
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $maps->where((new PlayerHistory)->getTable() . '.created_at', '>=', Carbon::today()->subDay($duration));
        }

        return $maps;
    }

    /**
     * function to retrieve the most played mod of the guild
     *
     * @param int $duration
     * @return PlayerHistory|\Illuminate\Database\Query\Builder
     */
    public function mostPlayedMods($duration=0)
    {
        $mods = PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->join((new PlayerClanHistory)->getTable(), (new PlayerHistory)->getTable() . '.player_id', '=', (new PlayerClanHistory)->getTable() . '.player_id')
            ->where((new PlayerClanHistory)->getTable() . '.clan_id', '=', $this->getAttribute('id'))
            ->where((new PlayerClanHistory)->getTable() . '.left_at', null)
            ->groupBy(DB::raw((new PlayerHistory)->getTable() . '.mod_id'))
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $mods->where((new PlayerHistory)->getTable() . '.created_at', '>=', Carbon::today()->subDay($duration));
        }

        return $mods;
    }

    /**
     * function to retrieve the oldest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasMany|null|object
     */
    public function statsOldestPlayer()
    {
        return $this->hasMany(PlayerClanHistory::class, 'clan_id')
            ->where('left_at', null)
            ->orderBy('joined_at')
            ->first();
    }

    /**
     * function to retrieve the youngest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasMany|null|object
     */
    public function statsYoungestPlayer()
    {
        return $this->hasMany(PlayerClanHistory::class, 'clan_id')
            ->where('left_at', null)
            ->orderByDesc('joined_at')
            ->first();
    }

    /**
     * function to retrieve the most active player of the guild
     *
     * @return Player
     */
    public function statsMostActivePlayer()
    {
        return $this->belongsToMany(Player::class, (new PlayerClanHistory)->getTable(), 'clan_id', 'player_id')
            ->join((new PlayerHistory)->getTable(), (new PlayerHistory)->getTable() . '.player_id', '=', (new Player())->getTable() . '.id')
            ->where('left_at', null)
            ->orderByRaw('SUM(`' . (new PlayerHistory)->getTable() . '`.`hour`) DESC')
            ->groupBy(DB::raw((new Player())->getTable() . '.id'))->first();
    }

    /**
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $duration
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($duration=0, $amount = 10, $displayOthers = False)
    {
        /** @var PlayerHistory $playedMap */
        foreach ($this->mostPlayedMaps($duration)->get() as $playedMap) {
            $results[$playedMap->map->getAttribute('map')] = (int)$playedMap->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $duration
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMods($duration=0, $amount = 10, $displayOthers = False)
    {
        /** @var PlayerHistory $playedMod */
        foreach ($this->mostPlayedMods($duration)->get() as $playedMod) {
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
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayerCountries($amount = 10, $displayOthers = True)
    {
        $clanPlayers = $this->players()->selectRaw('`players`.*, COUNT(`players`.`country`) as `count_countries`')
            ->groupBy('country')
            ->orderByDesc('country')->get();

        /** @var Player $player */
        foreach ($clanPlayers as $player) {
            $results[$player->getAttribute('country')] = (int)$player->getAttribute('count_countries');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * @param int $duration
     * @return \Generator
     */
    public function chartOnlineHours($duration=0)
    {
        $clanOnlineHours = array_fill(0, 24, 0);

        foreach ($this->players as $player) {
            $playerOnlineHours = $player->onlineHours($duration)->get();
            foreach ($playerOnlineHours as $playerOnlineHour) {
                $clanOnlineHours[$playerOnlineHour->hour] += $playerOnlineHour->sum_minutes;
            }
        }

        $max = max($clanOnlineHours);
        foreach ($clanOnlineHours as $clanOnlineHour) {
            yield round($clanOnlineHour / $max * 100, 2);
        }
    }

    /**
     * @param int $duration
     * @return \Generator
     */
    public function chartOnlineDays($duration=0)
    {
        $clanOnlineDays = array_fill(0, 7, 0);

        foreach ($this->players as $player) {
            $playerOnlineDays = $player->onlineDays($duration)->get();
            foreach ($playerOnlineDays as $playerOnlineDay) {
                $clanOnlineDays[$playerOnlineDay->weekday] += $playerOnlineDay->sum_minutes;
            }
        }

        $max = max($clanOnlineDays);
        foreach ($clanOnlineDays as $clanOnlineDay) {
            yield round($clanOnlineDay / $max * 100, 2);
        }
    }

    /**
     * function to humanize the tracked minutes into a human time(h-m-s or if needed even d-h-m-s etc)
     *
     * @param $minutes
     * @return string
     */
    public static function humanizeDuration($minutes)
    {
        return (new Duration($minutes * 60))->humanize();
    }
}
