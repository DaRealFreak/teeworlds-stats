<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerClanHistory
 *
 * @property-read \App\Models\Clan $clan
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property int $player_id
 * @property int $clan_id
 * @property string $joined_at
 * @property string|null $left_at
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereClanId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereJoinedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereLeftAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory wherePlayerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\PlayerClanHistory whereUpdatedAt($value)
 */
class PlayerClanHistory extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the clan record associated with the tee.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function clan()
    {
        return $this->belongsTo(Clan::class, 'clan_id');
    }

    /**
     * Get the clan records associated with the tee.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function player()
    {
        return $this->belongsTo(Player::class, 'player_id');
    }
}
