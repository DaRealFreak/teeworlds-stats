<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Player
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerClanHistory[] $clanRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playRecords
 * @property-read \App\Models\PlayerStatus $stats
 * @mixin \Eloquent
 */
class Player extends Model
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

    /**
     * Get the clan record associated with the tee.
     *
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function clan()
    {
        /** @var PlayerClanHistory $clanRecord */
        if ($clanRecord = $this->currentClanRecord()) {
            return $clanRecord->clan;
        } else {
            return null;
        }
    }

    /**
     * @return Model|\Illuminate\Database\Eloquent\Relations\HasMany|null
     */
    public function currentClanRecord()
    {
        return $this->clanRecords()->where('left_at', null)->first();
    }

    /**
     * Get the player clan records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function clanRecords()
    {
        return $this->hasMany(PlayerClanHistory::class);
    }

    /**
     * Get the server play records associated with the tee
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function playRecords()
    {
        return $this->hasMany(PlayerHistory::class);
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
        $playerMaps = $this->playRecords()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes')->get();

        if ($duration) {
            $playerMaps->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        /** @var PlayerHistory $playedMap */
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
        $playerMods = $this->playRecords()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')->get();

        if ($duration) {
            $playerMods->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        /** @var PlayerHistory $playedMod */
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
        $historyEntries = $this->hasMany(PlayerHistory::class)
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('hour')
            ->orderByDesc('hour');

        if ($duration) {
            $historyEntries->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        $historyEntries = $historyEntries->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 24, 0);

        /** @var PlayerHistory $entryHour */
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
        $historyEntries = $this->hasMany(PlayerHistory::class)
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('weekday')
            ->orderByDesc('weekday');

        if ($duration) {
            $historyEntries->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        $historyEntries = $historyEntries->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 7, 0);

        /** @var PlayerHistory $entryHour */
        $max = $historyEntries->sortByDesc('sum_minutes')->first()->sum_minutes;
        foreach ($historyEntries as $historyEntry) {
            $results[$historyEntry->hour] = round($historyEntry->sum_minutes / $max * 100, 2);
        }
        return $results;
    }
}
