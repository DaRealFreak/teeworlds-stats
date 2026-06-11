<?php

namespace Tests\Feature;

use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\PlayerSession;
use App\Models\Server;
use App\Service\SessionRecorder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionRecorderTest extends TestCase
{
    use RefreshDatabase;

    private SessionRecorder $recorder;
    private Player $player;
    private Server $server;
    private Map $map;
    private Mod $mod;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recorder = new SessionRecorder();
        $this->player = Player::factory()->create();
        $this->server = Server::factory()->create();
        // Map and Mod have no factory; they only require a name
        $this->map = Map::create(['name' => 'Dustycave']);
        $this->mod = Mod::create(['name' => 'DDraceNetwork']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_first_observation_opens_a_session(): void
    {
        $session = $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        $this->assertNull($session->ended_at);
        $this->assertSame(10, $session->minutes);
        $this->assertSame(1, PlayerSession::count());
    }

    public function test_consecutive_observations_extend_the_same_session(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $first = $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        Carbon::setTestNow('2026-06-11 20:10:00');
        $second = $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PlayerSession::count());
        $this->assertSame(20, $second->minutes);
        $this->assertNull($second->ended_at);
    }

    public function test_switching_servers_closes_the_old_session_and_opens_a_new_one(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        $otherServer = Server::factory()->create();
        Carbon::setTestNow('2026-06-11 20:10:00');
        $this->recorder->record($this->player, $otherServer, $this->map, $this->mod);

        $this->assertSame(2, PlayerSession::count());
        $this->assertSame(1, PlayerSession::whereNull('ended_at')->count());
        // the closed session ends at its last observation, not "now"
        $closed = PlayerSession::whereNotNull('ended_at')->first();
        $this->assertEquals('2026-06-11 20:00:00', $closed->ended_at->format('Y-m-d H:i:s'));
    }

    public function test_a_long_gap_starts_a_new_session(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        // more than 1.5 * interval later → the old session is stale
        Carbon::setTestNow('2026-06-11 21:00:00');
        $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        $this->assertSame(2, PlayerSession::count());
        $this->assertSame(1, PlayerSession::whereNull('ended_at')->count());
    }

    public function test_close_stale_closes_sessions_past_the_window(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        // the player is no longer seen on any server; a later scrape closes the session
        Carbon::setTestNow('2026-06-11 20:30:00');
        $closed = $this->recorder->closeStale();

        $this->assertSame(1, $closed);
        $session = PlayerSession::first();
        $this->assertNotNull($session->ended_at);
        $this->assertEquals('2026-06-11 20:00:00', $session->ended_at->format('Y-m-d H:i:s'));
    }

    public function test_close_stale_leaves_fresh_sessions_open(): void
    {
        Carbon::setTestNow('2026-06-11 20:00:00');
        $this->recorder->record($this->player, $this->server, $this->map, $this->mod);

        // only 5 minutes later — still within the live window
        Carbon::setTestNow('2026-06-11 20:05:00');
        $closed = $this->recorder->closeStale();

        $this->assertSame(0, $closed);
        $this->assertNull(PlayerSession::first()->ended_at);
    }
}
