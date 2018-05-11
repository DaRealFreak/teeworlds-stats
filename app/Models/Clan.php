<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
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
        return $this->players()->where('last_seen', '>=', Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') + 1))->get();
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
        $clanPlayedMaps = $this->hasManyThrough(PlayerMapRecord::class, Player::class)
            ->selectRaw('`player_map_records`.*, SUM(`player_map_records`.`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByRaw('SUM(`player_map_records`.`minutes`) DESC')->get();

        /** @var PlayerMapRecord $playedMap */
        foreach ($clanPlayedMaps as $playedMap) {
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
        $clanPlayedMods = $this->hasManyThrough(PlayerModRecord::class, Player::class)
            ->selectRaw('`player_mod_records`.*, SUM(`player_mod_records`.`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')->get();

        /** @var PlayerModRecord $playedMod */
        foreach ($clanPlayedMods as $playedMod) {
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
     * function to retrieve the oldest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasOne|null|object
     */
    public function statsOldestPlayer()
    {
        return $this->hasOne(Player::class, 'clan_id')->orderBy('clan_joined_at')->first();
    }

    /**
     * function to retrieve the youngest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasOne|null|object
     */
    public function statsYoungestPlayer()
    {
        return $this->hasOne(Player::class, 'clan_id')->orderByDesc('clan_joined_at')->first();
    }

    /**
     * function to retrieve the most active player of the guild
     *
     * @return Player
     */
    public function statsMostActivePlayer()
    {
        return $this->hasManyThrough(PlayerStatus::class, Player::class)->orderByRaw('
        SUM(`player_statuses`.`hour_0`+`player_statuses`.`hour_1`+`player_statuses`.`hour_2`+`player_statuses`.`hour_3`+
        `player_statuses`.`hour_4`+`player_statuses`.`hour_5`+`player_statuses`.`hour_6`+`player_statuses`.`hour_7`+
        `player_statuses`.`hour_8`+`player_statuses`.`hour_9`+`player_statuses`.`hour_10`+`player_statuses`.`hour_11`+
        `player_statuses`.`hour_12`+`player_statuses`.`hour_13`+`player_statuses`.`hour_14`+`player_statuses`.`hour_15`+
        `player_statuses`.`hour_16`+`player_statuses`.`hour_17`+`player_statuses`.`hour_18`+`player_statuses`.`hour_19`+
        `player_statuses`.`hour_20`+`player_statuses`.`hour_21`+`player_statuses`.`hour_22`+`player_statuses`.`hour_23`) DESC')->groupBy(['player_id'])->first()->player;
    }

    /**
     * function to retrieve the most played map of the guild
     *
     * @return \Illuminate\Database\Eloquent\Collection|\Illuminate\Support\Collection
     */
    public function chartMostPlayedMaps()
    {
        return $this->hasManyThrough(PlayerMapRecord::class, Player::class)
            ->join((new Map)->getTable(), (new PlayerMapRecord())->getTable() . '.map_id', '=', (new Map())->getTable() . '.id')
            ->selectRaw('`maps`.*, SUM(`player_map_records`.`minutes`) as `sum_minutes`')
            ->groupBy(['map'])
            ->orderByRaw('SUM(`player_map_records`.`minutes`) DESC')
            ->get();
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

    /**
     * Get the player records associated with the clan
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function players()
    {
        return $this->hasMany(Player::class);
    }
}
