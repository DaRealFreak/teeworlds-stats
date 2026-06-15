<?php

namespace App\Models;

use App\Models\Concerns\HasPlayStatistics;
use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Map
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\MapVersion[] $versions
 * @property-read \App\Models\MapVersion|null $currentVersion
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $name
 * @property string|null $sha256
 * @property int|null $size
 * @property string|null $crc
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereCrc($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereSha256($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Map whereUpdatedAt($value)
 */
class Map extends Model
{
    use HasPlayStatistics;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [];
    }

    /**
     * Whether a downloadable .map file can potentially be acquired for this map: it has a content key
     * (sha256/crc — on the map row itself for legacy maps, or on any MapVersion row for versioned maps),
     * or it is keyless but was seen on a server we can connect to and download it from over UDP,
     * backfilling the key from the bytes.
     */
    public function hasDownloadableFile(): bool
    {
        if ($this->getAttribute('sha256') !== null || $this->getAttribute('crc') !== null) {
            return true;
        }
        // Versioned maps: at least one version with a known content key is sufficient.
        if ($this->relationLoaded('versions')) {
            if ($this->versions->contains(fn ($v) => $v->contentKey() !== null)) {
                return true;
            }
        } else {
            if (MapVersion::where('map_id', $this->getAttribute('id'))
                ->where(fn ($q) => $q->whereNotNull('sha256')->orWhereNotNull('crc'))
                ->exists()) {
                return true;
            }
        }

        return $this->hasRecordedHost();
    }

    /**
     * Whether any server_histories row records a host for this map (a server we can attempt a UDP
     * download from). Honours an eager-loaded server_records_count to avoid an N+1 on list pages.
     */
    public function hasRecordedHost(): bool
    {
        $count = $this->getAttribute('server_records_count');
        if ($count !== null) {
            return (int) $count > 0;
        }

        return $this->serverRecords()->exists();
    }

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

    /** Every distinct content version observed for this map name, newest-first. */
    public function versions()
    {
        return $this->hasMany(MapVersion::class)->latest('last_seen_at');
    }

    /** The current version = the most-recently-seen one; default for the viewer/download. */
    public function currentVersion()
    {
        return $this->hasOne(MapVersion::class)->latestOfMany('last_seen_at');
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

    /**
     * a PlayerHistory query scoped to this map
     */
    protected function playerHistoryQuery(): Builder
    {
        return PlayerHistory::where('map_id', $this->getAttribute('id'));
    }

    /**
     * a ServerHistory query scoped to this map
     */
    protected function serverHistoryQuery(): Builder
    {
        return ServerHistory::where('map_id', $this->getAttribute('id'));
    }

    /**
     * most-played mods on this map, as a [mod name => minutes] map for the cross chart
     *
     * @return array<string, int>
     */
    public function chartPlayedMods($amount = 10, $displayOthers = true): array
    {
        $playedMods = $this->playerHistoryQuery()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`minutes`) as `sum_minutes`')
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes')
            ->with('mod')
            ->get();

        $results = [];
        /** @var PlayerHistory $playedMod */
        foreach ($playedMods as $playedMod) {
            $results[$playedMod->mod->getAttribute('name')] = (int) $playedMod->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
}
