<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerMapRecord
 *
 * @property-read \App\Models\Map $map
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerMapRecord extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the stats record associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function map()
    {
        return $this->hasOne(Map::class, 'map_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
