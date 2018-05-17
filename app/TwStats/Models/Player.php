<?php

namespace App\TwStats\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\TwStats\Models\Player
 *
 * @mixin \Eloquent
 */
class Player extends Model
{
    protected $fillable = [
        'name', 'clan', 'country', 'score', 'ingame'
    ];

}
