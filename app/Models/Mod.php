<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Mod
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 */
class Mod extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player play records associated with the mod
     *
     * @return PlayerHistory
     */
    public function playerRecords()
    {
        return PlayerHistory::where('mod_id', '=', $this->getAttribute('id'))
            ->orWhere('mod_original_id', '=', $this->getAttribute('id'));
    }

    /**
     * Get the server play records associated with the mod
     *
     * @return ServerHistory
     */
    public function serverRecords()
    {
        return ServerHistory::where('mod_id', '=', $this->getAttribute('id'))
            ->orWhere('mod_original_id', '=', $this->getAttribute('id'));
    }

    /**
     * get the PlayerHistory records of the players who played the mod
     *
     * @return PlayerHistory
     */
    public function statsPlayedBy()
    {
        return $this->playerRecords()
            ->groupBy('player_id');
    }

    /**
     * get the ServerHistory records where the mod got played on
     *
     * @return ServerHistory
     */
    public function statsPlayedOnServer()
    {
        return $this->serverRecords()
            ->groupBy('server_id');
    }
}
