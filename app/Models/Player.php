<?php

namespace App\Models;

use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Khill\Duration\Duration;

/**
 * App\Models\Player
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerClanHistory[] $clanRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $onlineDays
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $onlineHours
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playRecords
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $last_seen
 * @property string $name
 * @property string $country
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereLastSeen($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Player whereUpdatedAt($value)
 */
class Player extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'color_body' => 'integer',
        'color_feet' => 'integer',
        'afk'        => 'boolean',
        'skin_parts' => 'array',
    ];

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
        return $this->hasMany(PlayerClanHistory::class)
            ->orderByDesc('updated_at');
    }

    /**
     * @param Clan $clan
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function exClanRecord(Clan $clan)
    {
        return $this->clanRecords()
            ->where('clan_id', '=', $clan->getAttribute('id'));
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
     * Get the discrete play sessions of the tee, newest first
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function sessions()
    {
        return $this->hasMany(PlayerSession::class)->orderByDesc('started_at');
    }

    /**
     * the session the player is currently in, if they are online right now
     *
     * @return PlayerSession|null
     */
    public function currentSession()
    {
        return $this->sessions()->whereNull('ended_at')->first();
    }

    /**
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection|PlayerSession[]
     */
    public function recentSessions($limit = 8)
    {
        return $this->sessions()->limit($limit)->get();
    }

    public function totalSessions(): int
    {
        return $this->sessions()->count();
    }

    public function longestSessionMinutes(): int
    {
        return (int)$this->sessions()->max('minutes');
    }

    public function averageSessionMinutes(): int
    {
        return (int)round((float)$this->sessions()->avg('minutes'));
    }

    /**
     * count of distinct values the player was recorded on, for the summary tiles
     */
    public function distinctServersCount(): int
    {
        return $this->playRecords()->distinct()->count('server_id');
    }

    public function distinctMapsCount(): int
    {
        return $this->playRecords()->distinct()->count('map_id');
    }

    public function distinctModsCount(): int
    {
        return $this->playRecords()->distinct()->count('mod_id');
    }

    /**
     * the hour (0-23) the player has accumulated the most play time in, or null
     */
    public function busiestHour(): ?int
    {
        $entry = $this->playRecords()
            ->selectRaw('hour, SUM(minutes) as sum_minutes')
            ->groupBy('hour')
            ->orderByDesc('sum_minutes')
            ->first();

        return $entry ? (int)$entry->getAttribute('hour') : null;
    }

    /**
     * the weekday (0 = Monday … 6 = Sunday) with the most accumulated play time, or null
     */
    public function busiestWeekday(): ?int
    {
        $entry = $this->playRecords()
            ->selectRaw('weekday, SUM(minutes) as sum_minutes')
            ->groupBy('weekday')
            ->orderByDesc('sum_minutes')
            ->first();

        return $entry ? (int)$entry->getAttribute('weekday') : null;
    }

    /**
     * the player's top servers by accumulated play time
     *
     * @param int $limit
     * @return \Illuminate\Database\Eloquent\Collection|PlayerHistory[]
     */
    public function favoriteServers($limit = 5)
    {
        return $this->playRecords()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`minutes`) as `sum_minutes`')
            ->groupBy('server_id')
            ->orderByDesc('sum_minutes')
            ->limit($limit)
            ->get();
    }

    /**
     * a weekday × hour matrix of accumulated minutes for the activity heatmap
     *
     * @return array{matrix: array<int, array<int, int>>, max: int}
     */
    public function chartOnlineHeatmap(): array
    {
        $rows = $this->playRecords()
            ->selectRaw('weekday, hour, SUM(minutes) as sum_minutes')
            ->groupBy('weekday', 'hour')
            ->get();

        $matrix = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;

        foreach ($rows as $row) {
            $weekday = (int)$row->getAttribute('weekday');
            $hour = (int)$row->getAttribute('hour');
            if ($weekday < 0 || $weekday > 6 || $hour < 0 || $hour > 23) {
                continue;
            }
            $minutes = (int)$row->getAttribute('sum_minutes');
            $matrix[$weekday][$hour] = $minutes;
            $max = max($max, $minutes);
        }

        return ['matrix' => $matrix, 'max' => $max];
    }

    /**
     * @param int $duration
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function onlineHours($duration = 0)
    {
        $onlineHours = $this->hasMany(PlayerHistory::class)
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
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
    public function onlineDays($duration = 0)
    {
        $onlineDays = $this->hasMany(PlayerHistory::class)
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
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
        $playerHistory = $this->playRecords()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('player_id');

        if ($duration) {
            $playerHistory->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        // a player without any play records in the window has no aggregate row
        $record = $playerHistory->first();
        $playerHistory = $record ? (int)$record->sum_minutes : 0;

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
        $playerMaps = $this->playRecords()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`' . (new PlayerHistory)->getTable() . '`.`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $playerMaps->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        $playerMaps = $playerMaps->get();
        $results = [];

        /** @var PlayerHistory $playedMap */
        foreach ($playerMaps as $playedMap) {
            $results[$playedMap->map->getAttribute('name')] = (int)$playedMap->getAttribute('sum_minutes');
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
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $playerMods->where('created_at', '>=', Carbon::today()->subDay($duration));
        }

        $playerMods = $playerMods->get();
        $results = [];

        /** @var PlayerHistory $playedMod */
        foreach ($playerMods as $playedMod) {
            $results[$playedMod->mod->getAttribute('name')] = (int)$playedMod->getAttribute('sum_minutes');
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
    public function chartOnlineHours($duration = 0)
    {
        $historyEntries = $this->onlineHours($duration)->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 24, 0);

        if ($historyEntries->sortByDesc('sum_minutes')->first()) {
            $max = $historyEntries->sortByDesc('sum_minutes')->first()->sum_minutes;
        } else {
            $max = 1;
        }

        foreach ($historyEntries as $historyEntry) {
            $results[$historyEntry->hour] = round($historyEntry->sum_minutes / $max * 100, 2);
        }
        return $results;
    }

    /**
     * @param int $duration
     * @return array
     */
    public function chartOnlineDays($duration = 0)
    {
        $historyEntries = $this->onlineDays($duration)->get();
        // initialize array for all hours to fill the slots
        $results = array_fill(0, 7, 0);

        if ($historyEntries->sortByDesc('sum_minutes')->first()) {
            $max = $historyEntries->sortByDesc('sum_minutes')->first()->sum_minutes;
        } else {
            $max = 1;
        }

        foreach ($historyEntries as $historyEntry) {
            $results[$historyEntry->weekday] = round($historyEntry->sum_minutes / $max * 100, 2);
        }
        return $results;
    }
}
