<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerHistory
 *
 * @property-read \App\Models\Map $map
 * @property-read \App\Models\Mod $mod
 * @property-read \App\Models\Mod $modOriginal
 * @property-read \App\Models\Server $server
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property int $weekday
 * @property int $hour
 * @property int $continuous
 * @property int $server_id
 * @property int $map_id
 * @property int $mod_id
 * @property int|null $mod_original_id
 * @property int $minutes
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereContinuous($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereHour($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereMapId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereModId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereModOriginalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\ServerHistory whereWeekday($value)
 */
class ServerHistory extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the server record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function server()
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    /**
     * Get the map record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function map()
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    /**
     * Get the mod record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function mod()
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }

    /**
     * Get the mod record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function modOriginal()
    {
        return $this->belongsTo(Mod::class, 'mod_original_id');
    }
}
