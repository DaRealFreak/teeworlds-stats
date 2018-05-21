<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Khill\Duration\Duration;

/**
 * App\Models\Server
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Player[] $currentPlayers
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 */
class Server extends Model
{
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * check if the player was seen in the passed time span
     *
     * @return bool
     */
    public function online()
    {
        return $this->getAttribute('last_seen') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5);
    }

    public function players()
    {
        return $this->belongsToMany(Player::class, (new PlayerHistory)->getTable(), 'server_id', 'player_id')
            ->groupBy('players.id');
    }

    /**
     * Get the current players on the server associated with this record
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function currentPlayers()
    {
        return $this->players()
            ->whereRaw('`' . (new PlayerHistory)->getTable() . '`.`updated_at` >= ?', [Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)]);
    }

    /**
     * Get the server play records associated with the server
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playerRecords()
    {
        return $this->hasMany(PlayerHistory::class);
    }

    /**
     * Get the server play records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }

    /**
     * @param int $duration
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function onlineHours($duration=0) {
        $onlineHours = $this->hasMany(ServerHistory::class)
            ->selectRaw('`' . (new ServerHistory)->getTable() . '`.*, SUM(`' . (new ServerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('hour')
            ->orderByDesc('hour');

        if ($duration) {
            $onlineHours->where('created_at', '>=', Carbon::today()->subDay($duration));
        }
        return $onlineHours;
    }

    /**
     * @param int $duration
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function onlineDays($duration=0) {
        $onlineDays = $this->hasMany(ServerHistory::class)
            ->selectRaw('`' . (new ServerHistory)->getTable() . '`.*, SUM(`' . (new ServerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('weekday')
            ->orderByDesc('weekday');

        if ($duration) {
            $onlineDays->where('created_at', '>=', Carbon::today()->subDay($duration));
        }
        return $onlineDays;
    }

    /**
     * @param int $duration
     * @param bool $formatted
     * @return float|int
     */
    public function totalHoursOnline($duration = 0, $formatted = False)
    {
        $playerHistory = $this->serverRecords()
            ->selectRaw('`' . (new ServerHistory)->getTable() . '`.*, SUM(`' . (new ServerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('server_id');

        if ($duration) {
            $playerHistory->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        $playerHistory = $playerHistory->first()->sum_minutes;

        if ($formatted) {
            return (new Duration($playerHistory * 60))->humanize();
        } else {
            return $playerHistory;
        }
    }

    /**
     * build an array of the played maps for Chart.js in the frontend
     *
     * @param int $duration
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMaps($duration = 0, $amount = 10, $displayOthers = True)
    {
        $playerMaps = $this->serverRecords()
            ->selectRaw('`' . (new ServerHistory)->getTable() . '`.*, SUM(`' . (new ServerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes')->get();

        if ($duration) {
            $playerMaps->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        /** @var ServerHistory $playedMap */
        foreach ($playerMaps as $playedMap) {
            $results[$playedMap->map->getAttribute('map')] = (int)$playedMap->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $duration
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayedMods($duration = 0, $amount = 10, $displayOthers = True)
    {
        $playerMods = $this->serverRecords()
            ->selectRaw('`' . (new ServerHistory)->getTable() . '`.*, SUM(`' . (new ServerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')->get();

        if ($duration) {
            $playerMods->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        /** @var ServerHistory $playedMod */
        foreach ($playerMods as $playedMod) {
            $results[$playedMod->mod->getAttribute('mod')] = (int)$playedMod->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        // sort by key if radar chart is used(>= 3 mods), else it looks pretty bad normally
        if (count($results) >= 3) {
            ksort($results);
        }

        return $results;
    }

    /**
     * @param int $duration
     * @return array
     */
    public function chartOnlineHours($duration=0)
    {
        $historyEntries = $this->onlineHours($duration)->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 24, 0);

        /** @var ServerHistory $entryHour */
        $max = $historyEntries->sortByDesc('sum_minutes')->first()->sum_minutes;

        foreach ($historyEntries as $historyEntry) {
            $results[$historyEntry->hour] = round($historyEntry->sum_minutes / $max * 100, 2);
        }
        return $results;
    }

    /**
     * @param int $duration
     * @return array
     */
    public function chartOnlineDays($duration=0)
    {
        $historyEntries = $this->onlineDays($duration)->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 7, 0);

        /** @var ServerHistory $entryHour */
        $max = $historyEntries->sortByDesc('sum_minutes')->first()->sum_minutes;
        foreach ($historyEntries as $historyEntry) {
            $results[$historyEntry->weekday] = round($historyEntry->sum_minutes / $max * 100, 2);
        }
        return $results;
    }

    /**
     * build an array of the played mods for Chart.js in the frontend
     *
     * @param int $amount
     * @param bool $displayOthers
     * @return array
     */
    public function chartPlayerCountries($amount = 10, $displayOthers = True)
    {
        // ToDo: group with MySql somehow
        // problem of multiple groups
        $serverPlayers = $this->players()->selectRaw('`players`.*, COUNT(`players`.`country`) as `count_countries`')->get();

        $results = [];

        /** @var Player $player */
        foreach ($serverPlayers as $player) {
            if (!isset($results[$player->getAttribute('country')])) {
                $results[$player->getAttribute('country')] = 1;
            } else {
                $results[$player->getAttribute('country')]++;
            }
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
}
