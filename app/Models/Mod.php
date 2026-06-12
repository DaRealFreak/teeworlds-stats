<?php

namespace App\Models;

use App\Models\Concerns\HasPlayStatistics;
use App\Utility\ChartUtility;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\Mod
 *
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\PlayerHistory[] $playerRecords
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\ServerHistory[] $serverRecords
 * @mixin \Eloquent
 * @property int $id
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 * @property string $name
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Mod whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Mod whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Mod whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder|\App\Models\Mod whereUpdatedAt($value)
 */
class Mod extends Model
{
    use HasPlayStatistics;

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
            ->orWhere('mod_original_id', '=', $this->getAttribute('id'))
            ->groupBy('mod_id')
            ->orderByDesc('sum_minutes');

        if ($duration) {
            $playerHistoryEntries->where((new PlayerHistory)->getTable() . '.created_at', '>=', Carbon::today()->subDay($duration));
        }

        return $playerHistoryEntries;
    }

    /**
     * a PlayerHistory query scoped to this mod, counting variant records (mod_original_id).
     * The OR is wrapped so trait-added AND-wheres don't leak past it.
     */
    protected function playerHistoryQuery(): Builder
    {
        $id = $this->getAttribute('id');

        return PlayerHistory::where(function (Builder $query) use ($id) {
            $query->where('mod_id', $id)->orWhere('mod_original_id', $id);
        });
    }

    /**
     * a ServerHistory query scoped to this mod, counting variant records (mod_original_id).
     */
    protected function serverHistoryQuery(): Builder
    {
        $id = $this->getAttribute('id');

        return ServerHistory::where(function (Builder $query) use ($id) {
            $query->where('mod_id', $id)->orWhere('mod_original_id', $id);
        });
    }

    /**
     * most-played maps on this mod, as a [map name => minutes] map for the cross chart
     *
     * @return array<string, int>
     */
    public function chartPlayedMaps($amount = 10, $displayOthers = true): array
    {
        $playedMaps = $this->playerHistoryQuery()
            ->selectRaw('`' . (new PlayerHistory)->getTable() . '`.*, SUM(`minutes`) as `sum_minutes`')
            ->groupBy('map_id')
            ->orderByDesc('sum_minutes')
            ->get();

        $results = [];
        /** @var PlayerHistory $playedMap */
        foreach ($playedMaps as $playedMap) {
            $results[$playedMap->map->getAttribute('name')] = (int) $playedMap->getAttribute('sum_minutes');
        }
        ChartUtility::applyLimits($results, $amount, $displayOthers);

        return $results;
    }
}
