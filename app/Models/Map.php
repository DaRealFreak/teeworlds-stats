<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Map
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerPlayHistory[] $playRecords
 * @mixin \Eloquent
 */
class Map extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the server play records associated with the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playRecords()
    {
        return $this->hasMany(ServerPlayHistory::class);
    }
}
