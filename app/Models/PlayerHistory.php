<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerHistory
 *
 * @property-read \App\Models\Map $map
 * @property-read \App\Models\Mod $mod
 * @property-read \App\Models\Mod $modOriginal
 * @property-read \App\Models\Player $player
 * @property-read \App\Models\Server $server
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property int $weekday
 * @property int $hour
 * @property int $continuous
 * @property int $server_id
 * @property int $player_id
 * @property int $map_id
 * @property int $mod_id
 * @property int|null $mod_original_id
 * @property int $minutes
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereContinuous($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereHour($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereMapId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereMinutes($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereModId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereModOriginalId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory wherePlayerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerHistory whereWeekday($value)
 */
class PlayerHistory extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

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
     * Get the player record associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
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
}
