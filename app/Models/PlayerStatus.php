<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Khill\Duration\Duration;

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
     * @param bool $formatted
     * @return float|int
     */
    public function totalHoursPlayed($formatted = False)
    {
        $minutes = array_sum(iterator_to_array($this->onlineHours())) * env('CRONTASK_INTERVAL');
        if ($formatted) {
            return (new Duration($minutes * 60))->humanize();
        } else {
            return $minutes;
        }
    }

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
     * @return \Generator
     */
    public function chartOnlineHours()
    {
        $max = max(iterator_to_array($this->onlineHours()));
        foreach ($this->onlineHours() as $hour) {
            yield round($hour / $max * 100, 2);
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

    /**
     * @return \Generator
     */
    public function chartOnlineDays()
    {
        $max = max($this->onlineDays());
        foreach ($this->onlineDays() as $day) {
            yield round($day / $max * 100, 2);
        }
    }

    public function player()
    {
        return $this->belongsTo(Player::class);
    }
}
