<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerStatus
 *
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerStatus extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
