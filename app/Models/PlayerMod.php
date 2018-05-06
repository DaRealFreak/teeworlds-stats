<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerMods
 *
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerMod extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player record associated with this mod record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
