<?php

namespace App\TwStats\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\TwStats\Models\Server
 *
 * @mixin \Eloquent
 */
abstract class Server extends Model
{
    protected $fillable = [
        'hostname', 'ip', 'port', 'response'
    ];

    protected $attributes = [
        'port' => 8303,
        'major_version' => 6,
        'response' => False
    ];
}
