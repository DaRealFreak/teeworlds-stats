<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Map
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 */
class Map extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player play records associated with the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playerRecords()
    {
        return $this->hasMany(PlayerHistory::class);
    }

    /**
     * Get the server play records associated with the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }

    /**
     * get the PlayerHistory records of the players who played the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statsPlayedBy()
    {
        return $this->playerRecords()
            ->groupBy('player_id');
    }

    /**
     * get the ServerHistory records where the map got played on
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statsPlayedOnServer()
    {
        return $this->serverRecords()
            ->groupBy('server_id');
    }
}
