<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Clan extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the clan record associated with the tee.
     */
    public function players()
    {
        return $this->hasMany(Player::class);
    }
}
