<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

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
     * @return bool
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
    public function chartPlayedMaps($amount=31, $displayOthers=True)
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
    public function chartPlayedMods($amount=31, $displayOthers=True)
    {
        $clanPlayedMods = $this->hasManyThrough(PlayerMod::class, Player::class)->get();
        return ChartUtility::chartValues($clanPlayedMods, 'mod', 'times', 5, $amount, $displayOthers);
    }

    /**
     * Get the clan record associated with the tee.
     */
    public function players()
    {
        return $this->hasMany(Player::class);
    }
}
