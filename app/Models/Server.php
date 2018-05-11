<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Server
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerMapRecord[] $mapRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerModRecord[] $modRecords
 * @property-read \App\Models\ServerStatus $stats
 * @mixin \Eloquent
 */
class Server extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the stats record associated with this server record
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function stats()
    {
        return $this->hasOne(ServerStatus::class, 'server_id');
    }

    /**
     * Get the map records associated with this server record
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function mapRecords()
    {
        return $this->hasMany(ServerMapRecord::class);
    }

    /**
     * Get the mod records associated with this server record
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function modRecords()
    {
        return $this->hasMany(ServerModRecord::class);
    }
}
