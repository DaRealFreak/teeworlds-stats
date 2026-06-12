<?php

namespace App\Models\Concerns;

use App\Models\Clan;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\ServerHistory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared play-time aggregates for the sibling Map/Mod models. Each model supplies the
 * two scoped base-query hooks; everything else is computed identically.
 */
trait HasPlayStatistics
{
    /**
     * a PlayerHistory query already constrained to this record. Implementations MUST wrap
     * any OR condition in a nested closure so the AND-wheres added below cannot leak past it.
     */
    abstract protected function playerHistoryQuery(): Builder;

    /**
     * a ServerHistory query already constrained to this record (same nesting contract).
     */
    abstract protected function serverHistoryQuery(): Builder;

    /**
     * total minutes players accumulated on this record
     */
    public function totalPlayerMinutes(): int
    {
        return (int) $this->playerHistoryQuery()->sum('minutes');
    }

    /**
     * total minutes this record was live on servers
     */
    public function totalServerMinutes(): int
    {
        return (int) $this->serverHistoryQuery()->sum('minutes');
    }

    /**
     * distinct players with a record on this record
     */
    public function distinctPlayersCount(): int
    {
        return $this->playerHistoryQuery()->distinct()->count('player_id');
    }

    /**
     * distinct servers this record was live on
     */
    public function distinctServersCount(): int
    {
        return $this->serverHistoryQuery()->distinct()->count('server_id');
    }

    /**
     * top players by accumulated minutes; rows carry only `player_id` and `sum_minutes`,
     * plus an eager-loaded `player` relation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PlayerHistory>
     */
    public function topPlayers(int $limit = 10)
    {
        return $this->playerHistoryQuery()
            ->selectRaw('player_id, SUM(minutes) as sum_minutes')
            ->groupBy('player_id')
            ->orderByDesc('sum_minutes')
            ->with('player')
            ->limit($limit)
            ->get();
    }

    /**
     * top servers by accumulated minutes this record was live; rows carry only `server_id`
     * and `sum_minutes`, plus an eager-loaded `server` relation.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, ServerHistory>
     */
    public function topServers(int $limit = 10)
    {
        return $this->serverHistoryQuery()
            ->selectRaw('server_id, SUM(minutes) as sum_minutes')
            ->groupBy('server_id')
            ->orderByDesc('sum_minutes')
            ->with('server')
            ->limit($limit)
            ->get();
    }

    /**
     * top clans by accumulated minutes their *current* members played on this record.
     * Returns a normalised shape because PlayerHistory has no clan relation.
     *
     * @return Collection<int, array{clan: Clan, minutes: int}>
     */
    public function topClans(int $limit = 10): Collection
    {
        $history = (new PlayerHistory)->getTable();
        $clans   = (new PlayerClanHistory)->getTable();

        $rows = $this->playerHistoryQuery()
            ->join($clans, "$history.player_id", '=', "$clans.player_id")
            ->whereNull("$clans.left_at")
            ->selectRaw("$clans.clan_id as clan_id, SUM($history.minutes) as sum_minutes")
            ->groupBy("$clans.clan_id")
            ->orderByDesc('sum_minutes')
            ->limit($limit)
            ->get();

        $clanModels = Clan::whereIn('id', $rows->pluck('clan_id'))->get()->keyBy('id');

        return $rows
            ->map(fn ($row) => [
                'clan'    => $clanModels->get($row->clan_id),
                'minutes' => (int) $row->sum_minutes,
            ])
            ->filter(fn ($entry) => $entry['clan'] !== null)
            ->values();
    }

    /**
     * server-online minutes per weekday (0=Mon … 6=Sun), scaled to 0–100 of the busiest slot.
     *
     * @return array<int, float|int>
     */
    public function chartOnlineDays(): array
    {
        return $this->normalisedDistribution('weekday', 7);
    }

    /**
     * server-online minutes per hour (0–23), scaled to 0–100 of the busiest slot.
     *
     * @return array<int, float|int>
     */
    public function chartOnlineHours(): array
    {
        return $this->normalisedDistribution('hour', 24);
    }

    /**
     * sum server-online minutes grouped by $column, fill all $slots, scale to 0–100.
     *
     * @return array<int, float|int>
     */
    protected function normalisedDistribution(string $column, int $slots): array
    {
        $entries = $this->serverHistoryQuery()
            ->selectRaw("$column as slot, SUM(minutes) as sum_minutes")
            ->groupBy($column)
            ->get();

        $results = array_fill(0, $slots, 0);
        $max = (int) $entries->max('sum_minutes') ?: 1;

        foreach ($entries as $entry) {
            $slot = (int) $entry->getAttribute('slot');
            if ($slot < 0 || $slot >= $slots) {
                continue;
            }
            $results[$slot] = round($entry->getAttribute('sum_minutes') / $max * 100, 2);
        }

        return $results;
    }
}
