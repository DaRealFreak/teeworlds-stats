<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\DailySummary
 *
 * @mixin \Eloquent
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
