<?php

namespace App\Models;

use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-(host, map) UDP download backoff. A host-health failure (connect/timeout/transfer) on a
 * (server, map) pair parks that pair for an escalating span; success clears it. Keying on the pair
 * means a host that moves to a different map is a different key and earns a fresh attempt, so a
 * cycling host is never permanently blacklisted — only the actually-failing pair backs off.
 */
class ServerMapBackoff extends Model
{
    use MassPrunable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'cooldown_until' => 'datetime',
        ];
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    /**
     * The escalating backoff span for a 1-based streak: base doubles each failure, capped. A first
     * failure already implies ~10 failed handshake retries over the connect window, so the very first
     * failure earns the full base span.
     */
    protected static function span(int $streak): CarbonInterval
    {
        $base = (int) env('MAP_HOST_UDP_BACKOFF_BASE_MIN', 30);
        $cap = (int) env('MAP_HOST_UDP_BACKOFF_CAP_MIN', 1440);

        $minutes = min($base << ($streak - 1), $cap);

        return CarbonInterval::minutes($minutes);
    }

    /** Record a host-health UDP failure for this pair: bump the streak and (re)set the cooldown. */
    public static function recordFailure(int $serverId, int $mapId): void
    {
        $row = static::firstOrNew(['server_id' => $serverId, 'map_id' => $mapId]);
        $row->failure_streak = (int) $row->failure_streak + 1;
        $row->cooldown_until = now()->add(static::span($row->failure_streak));
        $row->save();
    }

    /** A successful download clears the pair so the next failure starts the escalation fresh. */
    public static function clear(int $serverId, int $mapId): void
    {
        static::where('server_id', $serverId)->where('map_id', $mapId)->delete();
    }

    /** Whether this pair is currently parked (a future cooldown). */
    public function inBackoff(): bool
    {
        return $this->cooldown_until !== null && $this->cooldown_until->isFuture();
    }

    /** Keep the table bounded — rows long past their cooldown are swept by the daily model:prune. */
    public function prunable()
    {
        return static::where('cooldown_until', '<', now()->subDay());
    }
}
