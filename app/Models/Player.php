<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Player
 *
 * @property-read \App\Models\Clan $clan
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerMaps[] $maps
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerMods[] $mods
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
    public function chartPlayedMaps($amount=31, $displayOthers=True)
    {
        $results = [];
        foreach ($this->maps->sortByDesc('times') as $map) {
            /** @var PlayerMaps $map */
            $mapName = $map->getAttribute('map');
            if (count($results) >= $amount) {
                if (!$displayOthers) {
                    break;
                }
                $mapName = "others";
            }
            $results[$mapName] = $map->getAttribute('times') * 5;
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
    public function chartPlayedMods($amount=31, $displayOthers=True)
    {
        $results = [];
        foreach ($this->mods->sortByDesc('times') as $mod) {
            /** @var PlayerMods $mod */
            $modName = $mod->getAttribute('mod');
            if (count($results) >= $amount) {
                if (!$displayOthers) {
                    break;
                }
                $modName = "others";
            }
            $results[$modName] = $mod->getAttribute('times') * 5;
        }
        return $results;
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
        return $this->hasMany(PlayerMods::class, 'player_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function maps()
    {
        return $this->hasMany(PlayerMaps::class, 'player_id');
    }
}
