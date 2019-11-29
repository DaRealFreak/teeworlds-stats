<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\DailySummary
 *
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $date
 * @property int $players_online_peak
 * @property int $players_online
 * @property int $clans_online_peak
 * @property int $clans_online
 * @property int $servers_online_peak
 * @property int $servers_online
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereClansOnline($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereClansOnlinePeak($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary wherePlayersOnline($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary wherePlayersOnlinePeak($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereServersOnline($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereServersOnlinePeak($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\DailySummary whereUpdatedAt($value)
 */
class DailySummary extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * get new players created on the date of this summary
     *
     * @return Player[]|\Illuminate\Database\Eloquent\Collection
     */
    public function newPlayers()
    {
        $usedDate = Carbon::parse($this->getAttribute('date'));
        return Player::whereBetween('created_at', [$usedDate, $usedDate->copy()->addDay()->subSecond()])->get();
    }

    /**
     * get new clans created on the date of this summary
     *
     * @return Clan[]|\Illuminate\Database\Eloquent\Collection
     */
    public function newClans()
    {
        $usedDate = Carbon::parse($this->getAttribute('date'));
        return Clan::whereBetween('created_at', [$usedDate, $usedDate->copy()->addDay()->subSecond()])->get();
    }

    /**
     * get new servers created on the date of this summary
     *
     * @return Server[]|\Illuminate\Database\Eloquent\Collection
     */
    public function newServers()
    {
        $usedDate = Carbon::parse($this->getAttribute('date'));
        return Server::whereBetween('created_at', [$usedDate, $usedDate->copy()->addDay()->subSecond()])->get();
    }
}
