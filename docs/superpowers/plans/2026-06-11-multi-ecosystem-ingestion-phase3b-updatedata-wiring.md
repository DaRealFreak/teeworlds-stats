# Multi-ecosystem Ingestion — Phase 3b: UpdateData Wiring — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Cut the live scraper over to the new pipeline — `DdnetHttpSource` → `ServerMerger` → `ServerPersister` → the existing server/player histories, clan history, and `SessionRecorder` — including the player cosmetic snapshot.

**Architecture:** `UpdateData::handle()` stops calling the legacy UDP `MasterServerController`/`GameServerController` and instead fetches the DDNet master over HTTP, merges to logical servers, persists each via `ServerPersister`, then runs the existing history/clan/session logic over the merged `DiscoveredClient` list. The legacy controller classes stay on disk (still unit-tested by `TwStatsParseTest`; removed in Phase 5). The discovery sources, merger, and persister are injected into the command's constructor so the whole pipeline is testable end-to-end with `Http::fake()`.

**Tech Stack:** Laravel 13 console command, Eloquent, the `Http` facade (`Http::fake()` for the e2e test), PHP 8.5. `RefreshDatabase`; `CRONTASK_INTERVAL` is set by `phpunit.xml` (required — the history/session logic depends on it). Run `vendor/bin/phpunit` in the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§10 orchestration, §8 info resolution, §5.5 cosmetics). Depends on Phase 2 (`DdnetHttpSource`, value objects) and Phase 3a (`ServerMerger`, `ServerPersister`).

**Scope note:** Phase 3b of the Phase 3 group; behaviour-changing (the user chose "cut over now"). 3c adds the serverbrowser type display. Vanilla servers registered only to teeworlds.com's master are intentionally not scraped until Phase 4 adds the 0.7 source. Because the only source is DDNet here, `afk` is always a genuine value — the "don't clobber a UDP `afk` null" concern arrives with Phase 4.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Console/Commands/UpdateData.php` (rewrite) | Orchestrate fetch → merge → persist → histories/clan/sessions over the merged clients, with cosmetics. Keeps the existing `updateServerHistory`/`updatePlayerHistory`/`retrieveOrCreateMod` logic. |
| `tests/Feature/UpdateDataTest.php` (new) | End-to-end: `Http::fake()` the DDNet master with the Phase 2 fixture, run `data:update`, assert servers/addresses/flavor/players/cosmetics/histories/sessions. |

---

## Task 1: Rewire `UpdateData` onto the new pipeline + end-to-end test

**Files:**
- Create: `tests/Feature/UpdateDataTest.php`
- Rewrite: `app/Console/Commands/UpdateData.php`

- [ ] **Step 1: Write the end-to-end test**

Create `tests/Feature/UpdateDataTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateDataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeMaster(): void
    {
        // the DdnetHttpSource tries master1 first; serve it the Phase 2 fixture (3 valid servers)
        Http::fake([
            'master1.ddnet.org/*' => Http::response(
                file_get_contents(base_path('tests/Fixtures/ddnet_servers.json')),
                200,
            ),
        ]);
    }

    public function test_it_ingests_the_ddnet_master_into_logical_servers_and_addresses(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseCount('servers', 3);
        // dual-stack DDNet server has 2 addresses; the two vanilla servers 1 each
        $this->assertDatabaseCount('server_addresses', 4);
        $this->assertDatabaseHas('servers', ['name' => 'DDNet GER10', 'flavor' => 'ddnet']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.7 CTF', 'flavor' => 'vanilla_07']);
        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.6 DM', 'flavor' => 'vanilla_06']);

        $this->assertSame([6, 7], Server::where('name', 'DDNet GER10')->first()->protocols());
    }

    public function test_it_persists_players_with_their_cosmetic_snapshot(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // vin, glow (DDNet GER10) + Bob (Vanilla 0.7 CTF); the scalar client entry is skipped
        $this->assertDatabaseCount('players', 3);

        $vin = Player::where('name', 'vin')->first();
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertSame(16726016, $vin->color_body);
        $this->assertSame(16745499, $vin->color_feet);
        $this->assertFalse($vin->afk);

        $glow = Player::where('name', 'glow')->first();
        $this->assertTrue($glow->afk);
        $this->assertSame(['name' => 'standard', 'color' => 65408], $glow->skin_parts['body']);
    }

    public function test_it_records_server_and_player_histories_and_opens_sessions(): void
    {
        $this->fakeMaster();

        $this->artisan('data:update')->assertSuccessful();

        // one server_history row per persisted server, one open session per player
        $this->assertDatabaseCount('server_histories', 3);
        $this->assertDatabaseCount('player_histories', 3);
        $this->assertDatabaseCount('player_sessions', 3);
        $this->assertDatabaseHas('player_sessions', ['ended_at' => null]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: FAIL. The current `handle()` still drives the legacy UDP `MasterServerController` (it does not read the faked HTTP master), so no DDNet servers are ingested and the `assertDatabaseCount('servers', 3)` assertions fail. (The legacy path may briefly attempt and time out a UDP master socket — that is the old code; just confirm the test does not pass.)

- [ ] **Step 3: Rewrite `UpdateData`**

Replace the entire contents of `app/Console/Commands/UpdateData.php` with:

```php
<?php

namespace App\Console\Commands;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\ModRule;
use App\Models\Player;
use App\Models\PlayerClanHistory;
use App\Models\PlayerHistory;
use App\Models\Server;
use App\Models\ServerHistory;
use App\Service\SessionRecorder;
use App\TwStats\Discovery\DdnetHttpSource;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Discovery\ServerMerger;
use App\TwStats\Persistence\ServerPersister;
use App\TwStats\Utility\Countries;
use Carbon\Carbon;
use Illuminate\Console\Command;

class UpdateData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape the configured server sources and update the stats database';

    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly DdnetHttpSource $ddnetHttpSource = new DdnetHttpSource(),
        private readonly ServerMerger $serverMerger = new ServerMerger(),
        private readonly ServerPersister $serverPersister = new ServerPersister(),
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // discover servers from every source, then collapse multi-protocol / duplicate
        // sightings into one logical server each before persisting
        $discovered = $this->ddnetHttpSource->fetch();
        $servers = $this->serverMerger->merge($discovered);

        foreach ($servers as $server) {
            $serverModel = $this->serverPersister->persist($server);
            $this->updateServerMapAndMod($serverModel, $server);
            $this->updatePlayers($server, $serverModel);
        }

        // close sessions of players who dropped off every tracked server this run
        $this->sessionRecorder->closeStale();

        return self::SUCCESS;
    }

    /**
     * resolve the server's current map and mod, then bump its server history
     */
    private function updateServerMapAndMod(Server $serverModel, DiscoveredServer $server): void
    {
        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        /** @var Mod $modModel */
        [$modModel, $originalModModel] = $this->retrieveOrCreateMod($serverModel, $server->gametype);

        $this->updateServerHistory($serverModel, $mapModel, $modModel, $originalModModel);
    }

    /**
     * update or create the server history with the data extracted from the server
     *
     * @param Server $serverModel
     * @param Map $mapModel
     * @param Mod $modModel
     * @param Mod|null $originalModModel
     */
    private function updateServerHistory(Server $serverModel, Map $mapModel, Mod $modModel, ?Mod $originalModModel)
    {
        // retrieve the latest history for the server
        /** @var ServerHistory $latestHistoryEntry */
        $latestHistoryEntry = ServerHistory::where(
            [
                'server_id' => $serverModel->getAttribute('id'),
            ]
        )->orderByDesc('updated_at')->first();

        // retrieve the latest history for the server for map and mod
        /** @var ServerHistory $historyEntry */
        $historyEntry = ServerHistory::orderByDesc('updated_at')->where(
            [
                'server_id' => $serverModel->getAttribute('id'),
                'map_id' => $mapModel->getAttribute('id'),
                'mod_id' => $modModel->getAttribute('id')
            ]
        )->first();

        // if no history for this server for this map and mod is set
        // or it's not the latest history entry or more than 1.5 times the cron interval ago create a new one
        if (!$historyEntry
            || ($latestHistoryEntry && $latestHistoryEntry->isNot($historyEntry))
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)
            || $latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
            || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1
        ) {
            if (!$latestHistoryEntry
                || $latestHistoryEntry->map->isNot($mapModel)
                || $latestHistoryEntry->mod->isNot($modModel)
                || $latestHistoryEntry->server->isNot($serverModel)) {
                $continuous = False;
            } else {
                $continuous = $latestHistoryEntry->getAttribute('updated_at') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5) &&
                    ($latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
                        || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1);
            }

            $historyEntry = ServerHistory::create(
                [
                    'weekday' => Carbon::now()->dayOfWeekIso - 1,
                    'hour' => Carbon::now()->hour,
                    'continuous' => $continuous,
                    'server_id' => $serverModel->getAttribute('id'),
                    'map_id' => $mapModel->getAttribute('id'),
                    'mod_id' => $modModel->getAttribute('id'),
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }

    /**
     * persist the deduped players of one logical server: identity, clan membership, country,
     * the last-seen cosmetic snapshot, play history, and the discrete session
     *
     * @param DiscoveredServer $server
     * @param Server $serverModel
     */
    private function updatePlayers(DiscoveredServer $server, Server $serverModel): void
    {
        /** @var Map $mapModel */
        $mapModel = Map::firstOrCreate(['name' => $server->map]);
        /** @var Mod $modModel */
        [$modModel, $originalModModel] = $this->retrieveOrCreateMod($serverModel, $server->gametype);

        foreach ($server->clients as $client) {
            // players not yet connected have a placeholder name; skip empty/placeholder entries
            if ($client->name === '' || $client->name === '(connecting)') {
                continue;
            }

            /** @var Player $playerModel */
            $playerModel = Player::firstOrCreate(['name' => $client->name]);

            // update player last seen stat
            $playerModel->setAttribute('last_seen', Carbon::now());

            // leave the current clan when the player now reports no tag
            if ($client->clan === '' && $playerModel->clan()) {
                $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
            }

            if ($client->clan !== '') {
                $clanModel = Clan::firstOrCreate(['name' => $client->clan]);

                // leave the current clan if the player is now reporting a different tag
                if ($playerModel->clan() && $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name')) {
                    $playerModel->currentClanRecord()->update(['left_at' => Carbon::now()]);
                }

                // if the player has no clan yet or changed clans, record the new membership
                if (!$playerModel->clan() || $playerModel->clan()->getAttribute('name') !== $clanModel->getAttribute('name')) {
                    PlayerClanHistory::create(
                        [
                            'player_id' => $playerModel->getAttribute('id'),
                            'clan_id' => $clanModel->getAttribute('id'),
                            'joined_at' => Carbon::now()
                        ]
                    );
                }
            }

            // update player country stat (stored as a name, see Countries::getCodeByName)
            $playerModel->setAttribute('country', Countries::getCountryName($client->country));

            // last-seen cosmetic snapshot — only the DDNet feed carries these; UDP sources leave them null
            $playerModel->setAttribute('skin', $client->skin);
            $playerModel->setAttribute('color_body', $client->colorBody);
            $playerModel->setAttribute('color_feet', $client->colorFeet);
            $playerModel->setAttribute('afk', $client->afk);
            $playerModel->setAttribute('skin_parts', $client->skinParts);

            $this->updatePlayerHistory($playerModel, $serverModel, $mapModel, $modModel, $originalModModel);

            $playerModel->save();

            // extend or open the player's discrete session for the sessions timeline
            $this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel);
        }
    }

    /**
     * @param Server $serverModel
     * @param string $gameType
     * @return array
     */
    private function retrieveOrCreateMod(Server $serverModel, string $gameType)
    {
        $mod = Mod::firstOrCreate(['name' => $gameType]);

        /** @var ModRule $modRule */
        foreach (ModRule::orderBy('priority')->get() as $modRule) {
            if ($modRule->getAttribute('decider') == 'server') {
                if ($modRule->servers()->contains($serverModel)) {
                    $originalMod = $mod;
                    $mod = $modRule->mod;
                    break;
                }
            } else {
                if ($modRule->mods()->contains($mod)) {
                    $originalMod = $mod;
                    $mod = $modRule->mod;
                    break;
                }
            }
        }
        if (!isset($originalMod)) {
            $originalMod = null;
        }

        return [$mod, $originalMod];
    }

    /**
     * update or create the player history with the data extracted from the server
     *
     * @param Player $playerModel
     * @param Server $serverModel
     * @param Map $mapModel
     * @param Mod $modModel
     * @param Mod|null $originalModModel
     */
    private function updatePlayerHistory(Player $playerModel, Server $serverModel, Map $mapModel, Mod $modModel, ?Mod $originalModModel)
    {
        // retrieve the latest history for the player
        /** @var PlayerHistory $latestHistoryEntry */
        $latestHistoryEntry = PlayerHistory::where(
            [
                'player_id' => $playerModel->getAttribute('id'),
            ]
        )->orderByDesc('updated_at')->first();

        // retrieve the latest history for the player for the server and map
        /** @var PlayerHistory $historyEntry */
        $historyEntry = PlayerHistory::orderByDesc('updated_at')->where(
            [
                'player_id' => $playerModel->getAttribute('id'),
                'server_id' => $serverModel->getAttribute('id'),
                'map_id' => $mapModel->getAttribute('id'),
                'mod_id' => $modModel->getAttribute('id')
            ]
        )->first();

        // if no history for this server and map is set or it's not the latest history in general create a new one
        if (!$historyEntry
            || $latestHistoryEntry->isNot($historyEntry)
            || $latestHistoryEntry->getAttribute('updated_at') < Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5)
            || $latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
            || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1
        ) {
            if (!$latestHistoryEntry
                || $latestHistoryEntry->map->isNot($mapModel)
                || $latestHistoryEntry->mod->isNot($modModel)
                || $latestHistoryEntry->server->isNot($serverModel)) {
                $continuous = False;
            } else {
                $continuous = $latestHistoryEntry->getAttribute('updated_at') >= Carbon::now()->subMinutes(env('CRONTASK_INTERVAL') * 1.5) &&
                    ($latestHistoryEntry->getAttribute('hour') !== Carbon::now()->hour
                        || $latestHistoryEntry->getAttribute('weekday') !== Carbon::now()->dayOfWeekIso - 1);
            }

            $historyEntry = PlayerHistory::create(
                [
                    'weekday' => Carbon::now()->dayOfWeekIso - 1,
                    'hour' => Carbon::now()->hour,
                    'continuous' => $continuous,
                    'player_id' => $playerModel->getAttribute('id'),
                    'server_id' => $serverModel->getAttribute('id'),
                    'map_id' => $mapModel->getAttribute('id'),
                    'mod_id' => $modModel->getAttribute('id'),
                    'mod_original_id' => $originalModModel ? $originalModModel->getAttribute('id') : null
                ]
            );
        }

        // update the history and persist the changes
        $historyEntry->setAttribute('minutes', $historyEntry->getAttribute('minutes') + env('CRONTASK_INTERVAL'));
        $historyEntry->save();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Run the full suite**

Run: `vendor/bin/phpunit`
Expected: all green. `TwStatsParseTest` still passes (the legacy `GameServerController` parser is untouched on disk); `SessionRecorderTest` still passes (`SessionRecorder` is unchanged).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/UpdateData.php tests/Feature/UpdateDataTest.php
git commit -m "feat(serverbrowser): cut UpdateData over to the DDNet HTTP merge pipeline

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Rebuild the dev DB and confirm a clean run

**Files:** none (verification only).

- [ ] **Step 1: Rebuild the dev schema**

Run: `php artisan migrate:fresh --force`
Expected: all migrations apply on MySQL with no error (no new migrations this phase; this confirms the schema is intact for the live command).

- [ ] **Step 2: Confirm the full suite is green**

Run: `vendor/bin/phpunit`
Expected: all green (Phases 1–3a + the new `UpdateDataTest`).

- [ ] **Step 3: Commit (only if a fixup was needed)**

No code changes expected; commit any needed fixup, otherwise skip.

---

## Self-Review

**Spec coverage (Phase 3b slice of §10/§8/§5.5):**
- `handle()` runs `DdnetHttpSource → ServerMerger → ServerPersister → histories/sessions` (§10) → Task 1. ✓
- Per-logical-server players are the merged/deduped `DiscoveredClient` list (§8 dedup happens in 3a's merger; consumed here) → `updatePlayers` (Task 1). ✓
- Cosmetic snapshot (`skin`/`color_body`/`color_feet`/`afk`/`skin_parts`) persisted from the DDNet feed (§5.5) → `updatePlayers` (Task 1), asserted by the e2e test. ✓
- Existing server/player history, clan history, and `SessionRecorder` behaviour preserved (the three helper methods are carried verbatim) → Task 1. ✓
- Legacy UDP discovery removed from the live path but the classes remain for `TwStatsParseTest` (Phase 5 removes them). ✓
- Out of scope (3c): the serverbrowser flavor/protocol badge + filter. Out of scope (Phase 4): the 0.7/0.6 UDP sources and the `afk`-null merge policy.

**Placeholder scan:** none — the full file and full test are provided.

**Type consistency:** `handle()` consumes `DiscoveredServer[]` from `ServerMerger::merge(DiscoveredServer[])`; `updateServerMapAndMod`/`updatePlayers` take `(Server, DiscoveredServer)` and read `DiscoveredServer->map`/`->gametype`/`->clients` and `DiscoveredClient->name/clan/country/isPlayer/afk/skin/colorBody/colorFeet/skinParts` — the exact Phase 2 property names. `ServerPersister::persist(DiscoveredServer): Server` and `SessionRecorder::record(Player, Server, Map, Mod)` match their definitions. `Countries::getCountryName(int): string` matches. The command returns `self::SUCCESS` so `assertSuccessful()` holds.

**Behaviour-preservation notes:** the three carried helpers (`updateServerHistory`, `retrieveOrCreateMod`, `updatePlayerHistory`) are byte-for-byte the originals. The only player-loop change is the input type (legacy `GameServer` players → `DiscoveredClient`) plus the added cosmetic snapshot; the clan/country/history/session calls are unchanged. Map/mod are resolved once per server (previously re-resolved per player to the same value) — a harmless de-duplication, not a behaviour change.
