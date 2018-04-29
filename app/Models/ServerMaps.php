<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerMaps extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
