<?php

namespace App\TwStats\Models;

use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $fillable = [
        'name', 'clan', 'country', 'score', 'ingame'
    ];

}
