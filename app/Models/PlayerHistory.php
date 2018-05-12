<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerHistory
 *
 * @property-read \App\Models\Map $map
 * @property-read \App\Models\Mod $mod
 * @property-read \App\Models\Player $player
 * @property-read \App\Models\Server $server
 * @mixin \Eloquent
 */
class PlayerHistory extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the mod record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function mod()
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }

    /**
     * Get the server record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * Get the player record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    /**
     * Get the map record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }
}
