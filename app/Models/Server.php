<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Server
 *
<<<<<<< HEAD
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Player[] $currentPlayers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
=======
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerMapRecord[] $mapRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerModRecord[] $modRecords
>>>>>>> a78c4ee... [!git add app/ routes/][TASK] rename times to minutes, extract maps and mods to unified table connected to models with record models
 * @property-read \App\Models\ServerStatus $stats
 * @mixin \Eloquent
 */
class Server extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * check if the player was seen in the passed time span
     *
     * @return bool
     */
    public function online()
    {
        return $this->getAttribute('last_seen') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5);
    }

    /**
     * Get the current players on the server associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function currentPlayers()
    {
        return $this->hasManyThrough(Player::class, PlayerHistory::class, 'server_id', 'id')
            ->whereRaw('`' . (new PlayerHistory)->getTable() . '`.`updated_at` >= ?', [Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)]);
    }

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
     * Get the server play records associated with the server
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playerRecords()
    {
        return $this->hasMany(PlayerHistory::class);
    }

    /**
     * Get the server play records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
<<<<<<< HEAD
    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
=======
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
>>>>>>> a78c4ee... [!git add app/ routes/][TASK] rename times to minutes, extract maps and mods to unified table connected to models with record models
    }
}
