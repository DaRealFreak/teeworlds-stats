<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Map
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 */
class Map extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player play records associated with the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playerRecords()
    {
        return $this->hasMany(PlayerHistory::class);
    }

    /**
     * Get the server play records associated with the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }

    /**
     * get the PlayerHistory records of the players who played the map
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statsPlayedBy()
    {
        return $this->playerRecords()
            ->groupBy('player_id');
    }

    /**
     * get the ServerHistory records where the map got played on
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function statsPlayedOnServer()
    {
        return $this->serverRecords()
            ->groupBy('server_id');
    }

    /**
     * function to retrieve the total hours of the current players having played
     *
     * @param int $duration
     * @return PlayerHistory|\Illuminate\Database\Query\Builder
     */
    public function totalHoursOnline($duration = 0)
    {
        $playerHistoryEntries = PlayerHistory::selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->where('map_id', '=', $this->getAttribute('id'))
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $playerHistoryEntries->where((new PlayerHistory)->getTable() . '.created_at', '>=', Carbon::today()->subDay($duration));
        }

        return $playerHistoryEntries;
    }
}
