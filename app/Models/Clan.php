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
     * @param int $amount
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function online($amount = 10)
    {
        return $this->players()->where('updated_at', '>=', Carbon::now()->subMinutes($amount))->get();
    }

    /**
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($amount = 31, $displayOthers = True)
    {
        $clanPlayedMaps = $this->hasManyThrough(PlayerMap::class, Player::class)->get();
        return ChartUtility::chartValues($clanPlayedMaps, 'map', 'times', 5, $amount, $displayOthers);
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMods($amount = 31, $displayOthers = True)
    {
        $clanPlayedMods = $this->hasManyThrough(PlayerMod::class, Player::class)->get();
        return ChartUtility::chartValues($clanPlayedMods, 'mod', 'times', 5, $amount, $displayOthers);
    }

    /**
     * function to retrieve the oldest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasOne|null|object
     */
    public function statsOldestPlayer()
    {
        return $this->hasOne(Player::class, 'clan_id')->orderBy('created_at')->first();
    }

    /**
     * function to retrieve the youngest player of the guild
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasOne|null|object
     */
    public function statsYoungestPlayer()
    {
        return $this->hasOne(Player::class, 'clan_id')->orderByDesc('created_at')->first();
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
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasManyThrough|\Illuminate\Database\Query\Builder|mixed|null|object
     */
    public function statsMostPlayedMap()
    {
        return $this->hasManyThrough(PlayerMap::class, Player::class)->selectRaw('`player_maps`.*, SUM(times) as `sum_times`')->groupBy(['map'])->orderByRaw('SUM(times) DESC')->first();
    }

    /**
     * function to humanize the tracked minutes into a human time(h-m-s or if needed even d-h-m-s etc)
     *
     * @param $minutes
     * @return string
     */
    public static function humanizeDuration($minutes)
    {
        return (new Duration($minutes * 5 * 60))->humanize();
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
