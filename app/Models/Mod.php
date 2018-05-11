<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Mod
 *
<<<<<<< HEAD
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
=======
>>>>>>> a78c4ee... [!git add app/ routes/][TASK] rename times to minutes, extract maps and mods to unified table connected to models with record models
 * @mixin \Eloquent
 */
class Mod extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];
<<<<<<< HEAD

    /**
     * Get the player play records associated with the mod
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playerRecords()
    {
        return $this->hasMany(PlayerHistory::class);
    }

    /**
     * Get the server play records associated with the mod
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }
=======
>>>>>>> a78c4ee... [!git add app/ routes/][TASK] rename times to minutes, extract maps and mods to unified table connected to models with record models
}
