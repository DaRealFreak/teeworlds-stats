<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Server;
use App\Models\ServerMapBackoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The per-(host, map) UDP backoff: a host-health download failure parks that pair for an escalating
 * span (30m → 1h → 2h … capped at 24h), cleared on success. Keying on (server, map) lets a host that
 * cycles to a different map earn a fresh attempt while a chronically-dead pair stays parked.
 */
class ServerMapBackoffTest extends TestCase
{
    use RefreshDatabase;

    private function pair(): array
    {
        // Real rows so the cascade FKs (enforced under SQLite in this repo) are satisfied.
        $server = Server::factory()->create();
        $map = Map::create(['name' => 'm']);

        return [$server->id, $map->id];
    }

    public function test_recording_a_failure_escalates_the_cooldown_span(): void
    {
        [$serverId, $mapId] = $this->pair();

        ServerMapBackoff::recordFailure($serverId, $mapId);
        $row = ServerMapBackoff::firstWhere(['server_id' => $serverId, 'map_id' => $mapId]);
        $this->assertSame(1, $row->failure_streak);
        $this->assertEqualsWithDelta(30, now()->diffInMinutes($row->cooldown_until), 1);

        ServerMapBackoff::recordFailure($serverId, $mapId);
        $row->refresh();
        $this->assertSame(2, $row->failure_streak);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($row->cooldown_until), 1);

        ServerMapBackoff::recordFailure($serverId, $mapId);
        $row->refresh();
        $this->assertSame(3, $row->failure_streak);
        $this->assertEqualsWithDelta(120, now()->diffInMinutes($row->cooldown_until), 1);
    }

    public function test_span_is_capped(): void
    {
        [$serverId, $mapId] = $this->pair();

        for ($i = 0; $i < 10; $i++) {
            ServerMapBackoff::recordFailure($serverId, $mapId);
        }

        $row = ServerMapBackoff::firstWhere(['server_id' => $serverId, 'map_id' => $mapId]);
        $this->assertLessThanOrEqual(1440, now()->diffInMinutes($row->cooldown_until));
    }

    public function test_clear_removes_the_pair(): void
    {
        [$serverId, $mapId] = $this->pair();

        ServerMapBackoff::recordFailure($serverId, $mapId);
        ServerMapBackoff::clear($serverId, $mapId);

        $this->assertDatabaseMissing('server_map_backoffs', [
            'server_id' => $serverId,
            'map_id' => $mapId,
        ]);
    }

    public function test_in_backoff_only_when_cooldown_in_future(): void
    {
        [$serverId, $mapId] = $this->pair();

        $past = ServerMapBackoff::create([
            'server_id' => $serverId,
            'map_id' => $mapId,
            'failure_streak' => 1,
            'cooldown_until' => now()->subMinute(),
        ]);
        $this->assertFalse($past->inBackoff());

        $past->update(['cooldown_until' => now()->addMinutes(30)]);
        $this->assertTrue($past->fresh()->inBackoff());
    }
}
