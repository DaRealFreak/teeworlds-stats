<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerModRecord
 *
 * @property-read \App\Models\Mod $map
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 * @property-read \App\Models\Mod $mod
 */
class PlayerModRecord extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the stats record associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function mod()
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
