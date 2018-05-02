<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\PlayerStatus
 *
 * @property-read \App\Models\Player $player
 * @mixin \Eloquent
 */
class PlayerStatus extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * @return \Generator
     */
    public function onlineHours()
    {
        for ($i = 0; $i <= 23; $i++) {
            yield $this->getAttribute('hour_' . $i);
        }
    }

    /**
     * @return array
     */
    public function onlineDays()
    {
        return [
            $this->getAttribute('monday'),
            $this->getAttribute('tuesday'),
            $this->getAttribute('wednesday'),
            $this->getAttribute('thursday'),
            $this->getAttribute('friday'),
            $this->getAttribute('saturday'),
            $this->getAttribute('sunday'),
        ];
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
