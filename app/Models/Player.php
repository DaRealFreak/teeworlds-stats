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

    /**
     * Get the clan record associated with the tee.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }

    /**
     * Get the stats record associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function stats()
    {
        return $this->hasOne(PlayerStatus::class, 'player_id');
    }

    /**
     * Get the mod records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mods()
    {
        return $this->hasMany(PlayerMod::class, 'player_id');
    }

    /**
     * Get the map records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function maps()
    {
        return $this->hasMany(PlayerMap::class, 'player_id');
    }
}
