<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerStatus
 *
 * @property-read \App\Models\Server $server
 * @mixin \Eloquent
 */
class ServerStatus extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the status record associated with this server record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
