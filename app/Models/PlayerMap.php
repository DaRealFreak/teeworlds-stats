<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerMaps
 *
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerMap extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player record associated with this map record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
