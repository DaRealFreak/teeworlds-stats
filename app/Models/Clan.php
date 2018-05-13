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
            ->get();

        foreach ($exPlayers as $index => $exPlayer) {
            if ($this->players->contains($exPlayer)) {
                $exPlayers->forget($exPlayer);
            }
        }
        return $exPlayers;
    }

    /**
     * function to retrieve the most played map of the guild
     *
     * @return PlayerHistory|\Illuminate\Database\Query\Builder
     */
    public function mostPlayedMaps()
    {
        return PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->join((new PlayerClanHistory)->getTable(), (new PlayerHistory)->getTable() . '.player_id', '=', (new PlayerClanHistory)->getTable() . '.player_id')
            ->where((new PlayerClanHistory)->getTable() . '.clan_id', '=', $this->getAttribute('id'))
            ->where((new PlayerClanHistory)->getTable() . '.left_at', null)
            ->groupBy(DB::raw((new PlayerHistory)->getTable() . '.map_id'))
            ->orderByDesc('sum_minutes');
    }

    /**
     * function to retrieve the most played mod of the guild
     *
     * @return PlayerHistory|\Illuminate\Database\Query\Builder
     */
    public function mostPlayedMods()
    {
        return PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->join((new PlayerClanHistory)->getTable(), (new PlayerHistory)->getTable() . '.player_id', '=', (new PlayerClanHistory)->getTable() . '.player_id')
            ->where((new PlayerClanHistory)->getTable() . '.clan_id', '=', $this->getAttribute('id'))
            ->where((new PlayerClanHistory)->getTable() . '.left_at', null)
            ->groupBy(DB::raw((new PlayerHistory)->getTable() . '.mod_id'))
            ->orderByDesc('sum_minutes');
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
            ->join((new PlayerStatus)->getTable(), (new PlayerStatus())->getTable() . '.player_id', '=', (new Player())->getTable() . '.id')
            ->where('left_at', null)
            ->orderByRaw('SUM(`player_statuses`.`hour_0`+`player_statuses`.`hour_1`+`player_statuses`.`hour_2`+`player_statuses`.`hour_3`+
                `player_statuses`.`hour_4`+`player_statuses`.`hour_5`+`player_statuses`.`hour_6`+`player_statuses`.`hour_7`+
                `player_statuses`.`hour_8`+`player_statuses`.`hour_9`+`player_statuses`.`hour_10`+`player_statuses`.`hour_11`+
                `player_statuses`.`hour_12`+`player_statuses`.`hour_13`+`player_statuses`.`hour_14`+`player_statuses`.`hour_15`+
                `player_statuses`.`hour_16`+`player_statuses`.`hour_17`+`player_statuses`.`hour_18`+`player_statuses`.`hour_19`+
                `player_statuses`.`hour_20`+`player_statuses`.`hour_21`+`player_statuses`.`hour_22`+`player_statuses`.`hour_23`) DESC')
            ->groupBy([DB::raw((new Player())->getTable() . '.id')])->first();
    }

    /**
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($amount = 10, $displayOthers = False)
    {
        /** @var PlayerHistory $playedMap */
        foreach ($this->mostPlayedMaps()->get() as $playedMap) {
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
        /** @var PlayerHistory $playedMod */
        foreach ($this->mostPlayedMods()->get() as $playedMod) {
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
            ->orderByRaw('COUNT(`players`.`country`) DESC')->get();

        /** @var Player $player */
        foreach ($clanPlayers as $player) {
            $results[$player->getAttribute('country')] = (int)$player->getAttribute('count_countries');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * @return \Generator
     */
    public function chartOnlineHours()
    {
        $clanOnlineHours = [];
        foreach ($this->players as $player) {
            $playerOnlineStats = iterator_to_array($player->stats->onlineHours());
            foreach ($playerOnlineStats as $playerOnlineHour => $playerOnlineTimes) {
                if (!array_key_exists($playerOnlineHour, $clanOnlineHours)) {
                    $clanOnlineHours[$playerOnlineHour] = $playerOnlineTimes;
                } else {
                    $clanOnlineHours[$playerOnlineHour] += $playerOnlineTimes;
                }
            }
        }

        $max = max($clanOnlineHours);
        foreach ($clanOnlineHours as $clanOnlineHour) {
            yield round($clanOnlineHour / $max * 100, 2);
        }
    }

    /**
     * @return \Generator
     */
    public function chartOnlineDays()
    {
        $clanOnlineDays = [];
        foreach ($this->players as $player) {
            $playerOnlineDayStats = $player->stats->onlineDays();
            foreach ($playerOnlineDayStats as $playerOnlineDay => $playerOnlineTimes) {
                if (!array_key_exists($playerOnlineDay, $clanOnlineDays)) {
                    $clanOnlineDays[$playerOnlineDay] = $playerOnlineTimes;
                } else {
                    $clanOnlineDays[$playerOnlineDay] += $playerOnlineTimes;
                }
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
