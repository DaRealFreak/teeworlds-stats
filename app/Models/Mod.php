<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Mod
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 */
class Mod extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Get the player play records associated with the mod
     *
     * @return PlayerHistory
     */
    public function playerRecords()
    {
        return PlayerHistory::where('mod_id', '=', $this->getAttribute('id'))
            ->orWhere('mod_original_id', '=', $this->getAttribute('id'));
    }

    /**
     * Get the server play records associated with the mod
     *
     * @return ServerHistory
     */
    public function serverRecords()
    {
        return ServerHistory::where('mod_id', '=', $this->getAttribute('id'))
            ->orWhere('mod_original_id', '=', $this->getAttribute('id'));
    }

    /**
     * get the PlayerHistory records of the players who played the mod
     *
     * @return PlayerHistory
     */
    public function statsPlayedBy()
    {
        return $this->playerRecords()
            ->groupBy('player_id');
    }

    /**
     * get the ServerHistory records where the mod got played on
     *
     * @return ServerHistory
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
            ->where('mod_id', '=', $this->getAttribute('id'))
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $playerHistoryEntries->where((new PlayerHistory)->getTable() . '.created_at', '>=', Carbon::today()->subDay($duration));
        }

        return $playerHistoryEntries;
    }
}
