<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Models\PlayerSession
 *
 * A contiguous period a player was observed on a single server, maintained by the
 * UpdateData collector. Open while the player keeps being seen ({@see $ended_at} is
 * null), closed once they drop off the master server list.
 *
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property int $player_id
 * @property int $server_id
 * @property int $map_id
 * @property int $mod_id
 * @property int $minutes
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon $last_seen_at
 * @property \Carbon\Carbon|null $ended_at
 * @property-read \App\Models\Player $player
 * @property-read \App\Models\Server $server
 * @property-read \App\Models\Map $map
 * @property-read \App\Models\Mod $mod
 * @mixin \Eloquent
 */
class PlayerSession extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * whether the player is still being seen in this session
     */
    public function isOpen(): bool
    {
        return $this->getAttribute('ended_at') === null;
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function map(): BelongsTo
    {
        return $this->belongsTo(Map::class, 'map_id');
    }

    public function mod(): BelongsTo
    {
        return $this->belongsTo(Mod::class, 'mod_id');
    }
}
