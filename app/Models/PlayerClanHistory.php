<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerClanHistory
 *
 * @property-read \App\Models\Clan $clan
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerClanHistory extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the clan record associated with the tee.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }

    /**
     * Get the clan records associated with the tee.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
