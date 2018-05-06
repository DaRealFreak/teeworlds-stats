<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerMaps
 *
 * @property-read \App\Models\Server $server
 * @mixin \Eloquent
 */
class ServerMap extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the server record associated with this map record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
