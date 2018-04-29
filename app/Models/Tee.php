<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tee extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the clan record associated with the tee.
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function stats()
    {
        return $this->hasOne(PlayerStatus::class, 'tee_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function mods()
    {
        return $this->hasMany(PlayerMods::class, 'tee_id');
    }

    /**
     * Get the stats record associated with the tee
     */
    public function maps()
    {
        return $this->hasMany(PlayerMaps::class, 'tee_id');
    }
}
