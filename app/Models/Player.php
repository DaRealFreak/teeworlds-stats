<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Player
 *
 * @property-read \App\Models\Clan $clan
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerMap[] $maps
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerMod[] $mods
 * @property-read \App\Models\PlayerStatus $stats
 * @mixin \Eloquent
 */
class Player extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * check if the player was seen in the passed time span
     *
     * @param int $amount
     * @return bool
     */
    public function online($amount = 10)
    {
        return $this->where('updated_at', '>=', Carbon::now()->subMinutes($amount))->count() > 0;
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
        return ChartUtility::chartValues($this->maps, 'map', 'times', 5, $amount, $displayOthers);
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
        return ChartUtility::chartValues($this->mods, 'mod', 'times', 5, $amount, $displayOthers);
    }

    public function totalHoursPlayed()
    {
        return array_sum([$this->getAttribute('hour_0'), $this->getAttribute('hour_1'), $this->getAttribute('hour_2'),
            $this->getAttribute('hour_3'), $this->getAttribute('hour_4'), $this->getAttribute('hour_5'),
            $this->getAttribute('hour_6'), $this->getAttribute('hour_7'), $this->getAttribute('hour_8'),
            $this->getAttribute('hour_9'), $this->getAttribute('hour_10'), $this->getAttribute('hour_11'),
            $this->getAttribute('hour_12'), $this->getAttribute('hour_13'), $this->getAttribute('hour_14'),
            $this->getAttribute('hour_15'), $this->getAttribute('hour_16'), $this->getAttribute('hour_17'),
            $this->getAttribute('hour_18'), $this->getAttribute('hour_19'), $this->getAttribute('hour_20'),
            $this->getAttribute('hour_21'), $this->getAttribute('hour_22'), $this->getAttribute('hour_23')]);
    }

    /**
     * Get the clan record associated with the tee.
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function stats()
    {
        return $this->hasOne(PlayerStatus::class, 'player_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function mods()
    {
        return $this->hasMany(PlayerMod::class, 'player_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function maps()
    {
        return $this->hasMany(PlayerMap::class, 'player_id');
    }
}
