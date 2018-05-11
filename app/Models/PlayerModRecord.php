<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerModRecord
 *
 * @property-read \App\Models\Mod $mod
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerModRecord extends Model
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
     * Get the player record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
