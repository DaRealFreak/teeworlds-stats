# Multi-ecosystem Ingestion — Phase 4c: Wire the 0.7 Source — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add `Teeworlds07Source` to the live scrape alongside `DdnetHttpSource`, deduping a sixup server seen via both (DDNet at `tw-0.6`, the 0.7 source at `tw-0.7`) into one logical server, and never letting a UDP source's empty cosmetics overwrite a DDNet snapshot.

**Architecture:** Three changes. **(1) Merge identity becomes `(ip, port)`** — a physical server is one ip:port regardless of protocol (UDP binding is exclusive), so `ServerMerger` groups by `ip:port` (keeping distinct `ip:port:protocol` addresses) and `ServerPersister` matches an existing server by `ip:port` (any protocol). This unifies a sixup server whose 0.6 and 0.7 endpoints arrive from different sources. **(2) `UpdateData`** fetches both sources (DDNet first → its metadata + cosmetics win the merge) and runs them through the existing merge/persist/histories pipeline. **(3) Cosmetic guard** — `updatePlayers` only writes the skin/colors/afk snapshot when the client carries it (`afk !== null`), so a 0.7 client (cosmetics all null) doesn't wipe a player's DDNet-recorded skin.

**Tech Stack:** Laravel 13, the Phase 2/3/4 components. Live-validated: the 0.7 source already returns ~520 servers (431 ddnet sixup, 91 vanilla_07) — the ddnet ones overlap the DDNet feed and MUST dedup via change (1). Run `vendor/bin/phpunit`.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` §7 (identity), §8 (info resolution / cosmetics), §10 (orchestration). Refines §7's address-set identity to `(ip,port)` after the live finding that DDNet and the 0.7 source surface the same sixup server under different protocols.

**Scope note:** Last sub-phase of Phase 4. Phase 5 refactors the native 0.6 source and removes the dead legacy controllers. After 4c the live scraper sources from DDNet HTTP + Teeworlds 0.7 UDP, merged.

---

## Task 1: Merge identity → `(ip, port)`

**Files:**
- Modify: `app/TwStats/Discovery/ServerMerger.php`, `tests/Unit/Discovery/ServerMergerTest.php`
- Modify: `app/TwStats/Persistence/ServerPersister.php`, `tests/Feature/Persistence/ServerPersisterTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/Discovery/ServerMergerTest.php`, add this test (a sixup server's 0.6 and 0.7 endpoints arrive as two servers sharing ip:port but differing in protocol; they must merge into one with BOTH addresses):

```php
    public function test_same_ip_port_different_protocol_merge_into_one_dual_stack_server(): void
    {
        // DDNet surfaces the 0.6 endpoint; the 0.7 source surfaces the 0.7 endpoint of the same host
        $ddnet = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('alice')], name: 'DDNet', flavor: 'ddnet');
        $seven = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 7)], [$this->client('alice')], name: 'Seven', flavor: 'vanilla_07');

        $merged = (new ServerMerger())->merge([$ddnet, $seven]);

        $this->assertCount(1, $merged);
        $this->assertSame('DDNet', $merged[0]->name); // DDNet (first) wins metadata
        $this->assertSame([6, 7], array_map(fn ($a) => $a->protocol, $merged[0]->addresses)); // both protocols kept
        $this->assertCount(1, $merged[0]->clients); // alice deduped across the two protocol lists
    }
```

In `tests/Feature/Persistence/ServerPersisterTest.php`, add:

```php
    public function test_a_later_protocol_resolves_to_the_same_server_by_ip_and_port(): void
    {
        $persister = new ServerPersister();
        // first cycle: DDNet persisted the 0.6 endpoint only
        $created = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)]));

        // the 0.7 source later reports the same host's 0.7 endpoint (same ip:port, protocol 7)
        $matched = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 7)], name: 'sixup'));

        $this->assertSame($created->id, $matched->id);          // same logical server
        $this->assertDatabaseCount('servers', 1);
        $this->assertSame([6, 7], $matched->fresh()->protocols()); // now records both protocols
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter "ServerMergerTest|ServerPersisterTest"`
Expected: the two new tests FAIL (current grouping/matching is `(ip,port,protocol)`, so the 0.7 endpoint is treated as a separate server). Existing tests still pass.

- [ ] **Step 3: Change `ServerMerger` to group by `ip:port`**

In `app/TwStats/Discovery/ServerMerger.php`:

(a) In `merge()`, replace the two `$this->addressKey($address)` calls (the lookup and the registration) with `$this->groupKey($address)`:

```php
        foreach ($servers as $server) {
            $groupId = null;
            foreach ($server->addresses as $address) {
                $key = $this->groupKey($address);
                if (isset($addressToGroup[$key])) {
                    $groupId = $addressToGroup[$key];
                    break;
                }
            }

            if ($groupId === null) {
                $groupId = count($groups);
                $groups[$groupId] = $server;
            } else {
                $groups[$groupId] = $this->combine($groups[$groupId], $server);
            }

            foreach ($groups[$groupId]->addresses as $address) {
                $addressToGroup[$this->groupKey($address)] = $groupId;
            }
        }
```

(b) Leave `combine()` unchanged — it still dedups the address union by `addressKey()` (`ip:port:protocol`), so both protocol endpoints are kept.

(c) Add the `groupKey()` method (keep the existing `addressKey()`):

```php
    private function groupKey(DiscoveredAddress $address): string
    {
        // a physical server is one ip:port regardless of protocol (UDP binding is exclusive), so the
        // 0.6 and 0.7 endpoints of a sixup server — which different sources surface separately — are
        // the same logical server. The address union still keeps both protocols (see addressKey).
        return $address->ip . ':' . $address->port;
    }
```

(d) Update the class docblock's "share an address" wording to "share an ip:port".

- [ ] **Step 4: Change `ServerPersister` to match by `ip:port`**

In `app/TwStats/Persistence/ServerPersister.php`, in the `orWhere` closure inside `persist()`, drop the protocol condition so an existing server is found by ip:port regardless of protocol:

```php
                        $query->orWhere(function ($match) use ($address) {
                            $match->where('ip', $address->ip)
                                ->where('port', $address->port);
                        });
```

Leave `syncAddresses()` unchanged (it still upserts each distinct `(ip,port,protocol)` row). Update the class docblock to say the server is found by ip:port (any protocol).

- [ ] **Step 5: Run to verify they pass**

Run: `vendor/bin/phpunit --filter "ServerMergerTest|ServerPersisterTest"`
Expected: all green (including the two new tests). Then run the full suite `vendor/bin/phpunit`.

- [ ] **Step 6: Commit**

```bash
git add app/TwStats/Discovery/ServerMerger.php app/TwStats/Persistence/ServerPersister.php \
        tests/Unit/Discovery/ServerMergerTest.php tests/Feature/Persistence/ServerPersisterTest.php
git commit -m "refactor(serverbrowser): identify logical servers by ip:port across protocols

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Wire `Teeworlds07Source` into `UpdateData` + cosmetic guard

**Files:**
- Modify: `app/Console/Commands/UpdateData.php`, `tests/Feature/UpdateDataTest.php`

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/UpdateDataTest.php`:

(a) Add imports at the top:

```php
use App\TwStats\Discovery\Teeworlds07Source;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\VariableInt;
use Tests\Support\FakeUdpTransport;
```

(b) Add a helper that builds a `Teeworlds07Source` returning one scripted vanilla-0.7 server whose single player is named `$playerName`, plus a bind helper. Add these methods to the test class:

```php
    private function bindEmptySevenSource(): void
    {
        // no masters + empty transport → the 0.7 source contributes nothing (no live UDP in tests)
        $this->app->instance(Teeworlds07Source::class, new Teeworlds07Source(new FakeUdpTransport(), masters: []));
    }

    private function bindSevenSourceWithPlayer(string $serverName, string $playerName): void
    {
        $masterIp = '10.9.0.1';
        $serverIp = '198.51.100.7';
        $myToken = Teeworlds07Source::CLIENT_TOKEN;
        $transport = new FakeUdpTransport();

        $tokenResponse = fn (int $t) => "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . pack('N', $t);
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($serverIp) . "\x20\x6f"; // :8303
        $list = SevenConnless::connless(0xA1, $myToken, "\xff\xff\xff\xfflis2" . $entry);

        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";
        $payload = $int(0) . $str('0.7.5') . $str($serverName) . $str('h') . $str('dm1') . $str('DM')
            . $int(0) . $int(1) . $int(1) . $int(8) . $int(1) . $int(8)
            . $str($playerName) . $str('') . $int(0) . $int(0) . $int(0);
        $info = SevenConnless::connless(0xB2, $myToken, "\xff\xff\xff\xffinf3" . $payload);

        $transport->queue($masterIp, 8283, $tokenResponse(0xA1)); $transport->queueGap();
        $transport->queue($masterIp, 8283, $list); $transport->queueGap();
        $transport->queue($serverIp, 8303, $tokenResponse(0xB2)); $transport->queueGap();
        $transport->queue($serverIp, 8303, $info); $transport->queueGap();

        $this->app->instance(Teeworlds07Source::class, new Teeworlds07Source($transport, masters: [['ip' => $masterIp, 'port' => 8283]]));
    }
```

(c) Add `$this->bindEmptySevenSource();` as the first line of each of the three existing test methods (`test_it_ingests_...`, `test_it_persists_players_...`, `test_it_records_...`) so they keep passing with the 0.7 source wired but contributing nothing.

(d) Add two new tests:

```php
    public function test_it_ingests_a_vanilla_07_server_from_the_seven_source(): void
    {
        Http::fake(['master1.ddnet.org/*' => Http::response('{"servers":[]}', 200)]); // DDNet empty
        $this->bindSevenSourceWithPlayer('Vanilla 0.7 DM', 'Sevenplayer');

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.7 DM', 'flavor' => 'vanilla_07']);
        $this->assertDatabaseHas('players', ['name' => 'Sevenplayer']);
        $this->assertDatabaseHas('server_addresses', ['ip' => '198.51.100.7', 'port' => 8303, 'protocol' => 7]);
    }

    public function test_a_seven_source_observation_does_not_wipe_a_ddnet_cosmetic_snapshot(): void
    {
        // DDNet feed gives player "vin" a skin; the 0.7 source reports a server with the same player
        // name but no cosmetics (afk null). vin's DDNet skin must survive.
        $this->fakeMaster(); // DDNet fixture: server "DDNet GER10" with player vin (skin glow_cammo)
        $this->bindSevenSourceWithPlayer('Vanilla 0.7 DM', 'vin');

        $this->artisan('data:update')->assertSuccessful();

        $vin = Player::where('name', 'vin')->first();
        $this->assertSame('glow_cammo', $vin->skin); // not wiped by the cosmetic-less 0.7 observation
        $this->assertFalse($vin->afk);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: FAIL — `Teeworlds07Source` is not yet injected/merged (so the vanilla server isn't ingested), and the cosmetic guard doesn't exist yet (vin's skin may be wiped or the source unwired).

- [ ] **Step 3: Wire the source + add the guard in `UpdateData`**

In `app/Console/Commands/UpdateData.php`:

(a) Add the import: `use App\TwStats\Discovery\Teeworlds07Source;`

(b) Add the source to the constructor (after `$ddnetHttpSource`):

```php
    public function __construct(
        private readonly SessionRecorder $sessionRecorder,
        private readonly DdnetHttpSource $ddnetHttpSource = new DdnetHttpSource(),
        private readonly Teeworlds07Source $teeworldsSevenSource = new Teeworlds07Source(),
        private readonly ServerMerger $serverMerger = new ServerMerger(),
        private readonly ServerPersister $serverPersister = new ServerPersister(),
    ) {
        parent::__construct();
    }
```

(c) In `handle()`, fetch both sources (DDNet first so its richer metadata + cosmetics win the merge):

```php
        // DDNet first: its servers.json carries real limits, players and cosmetics, so it wins the
        // merge over a 0.7 UDP sighting of the same sixup server. The 0.7 source adds the stock
        // Teeworlds servers that register only to teeworlds.com's master.
        $discovered = array_merge($this->ddnetHttpSource->fetch(), $this->teeworldsSevenSource->fetch());
        $servers = $this->serverMerger->merge($discovered);
```

(d) In `updatePlayers()`, wrap the five cosmetic `setAttribute` calls in an `afk !== null` guard:

```php
            // the DDNet feed is the only source of cosmetics; refresh the snapshot only when this
            // observation carries it (afk is non-null for DDNet, null for UDP sources), so a 0.7/0.6
            // sighting of the same player name never wipes a previously-recorded DDNet skin/colors
            if ($client->afk !== null) {
                $playerModel->setAttribute('skin', $client->skin);
                $playerModel->setAttribute('color_body', $client->colorBody);
                $playerModel->setAttribute('color_feet', $client->colorFeet);
                $playerModel->setAttribute('afk', $client->afk);
                $playerModel->setAttribute('skin_parts', $client->skinParts);
            }
```

- [ ] **Step 4: Run to verify they pass**

Run: `vendor/bin/phpunit --filter UpdateDataTest`
Expected: all green (the three existing tests with the empty 0.7 source + the two new ones). Then run the full suite `vendor/bin/phpunit`.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/UpdateData.php tests/Feature/UpdateDataTest.php
git commit -m "feat(serverbrowser): add the Teeworlds 0.7 source to the live scrape

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Rebuild dev DB, full suite, and a live two-source smoke

**Files:** none (verification only).

- [ ] **Step 1:** `php artisan migrate:fresh --force` — expect clean (no new migrations; confirms schema intact).
- [ ] **Step 2:** `vendor/bin/phpunit` — expect all green.
- [ ] **Step 3: Live two-source smoke.** Run the real scrape (DDNet HTTP + 0.7 UDP) and confirm both protocols land and sixup servers are unified, not doubled:

```bash
php artisan data:update && php artisan tinker --execute="
use App\Models\Server; use App\Models\ServerAddress;
echo 'servers='.Server::count().' addresses='.ServerAddress::count().PHP_EOL;
echo 'proto6='.ServerAddress::where('protocol',6)->count().' proto7='.ServerAddress::where('protocol',7)->count().PHP_EOL;
echo 'dual_stack='.Server::has('addresses','>',1)->count().PHP_EOL;
foreach(['ddnet','vanilla_06','vanilla_07'] as \$f) echo \$f.'='.Server::where('flavor',\$f)->count().PHP_EOL;
// no two servers should share an ip:port (would mean a sixup server was not unified)
echo 'ip_port_collisions='.ServerAddress::select('ip','port')->groupBy('ip','port')->havingRaw('COUNT(DISTINCT server_id) > 1')->get()->count().PHP_EOL;
"
```

Expected: a higher server count than DDNet-only (the +vanilla_07 stock servers), both proto6 and proto7 present, `ip_port_collisions = 0` (sixup servers unified into one logical server). If collisions > 0, the `(ip,port)` merge identity isn't holding — investigate.

- [ ] **Step 4:** Commit any fixup; otherwise skip.

---

## Self-Review

**Spec coverage (Phase 4c slice of §7/§8/§10):**
- Merge identity refined to `(ip,port)` so the same physical (sixup) server unifies across sources → Task 1. ✓
- Both sources run through the merge/persist pipeline, DDNet first for priority → Task 2. ✓
- Cosmetic-clobber guard (`afk !== null`) protects a DDNet snapshot from a UDP source's nulls → Task 2. ✓
- Address union still keeps distinct protocols, so a unified sixup server records both 6 and 7 → Task 1 (`combine` + `syncAddresses` unchanged). ✓
- Out of scope (Phase 5): native 0.6 source + removing the dead legacy controllers.

**Placeholder scan:** none — exact diffs + full test code.

**Type consistency:** `ServerMerger` now keys grouping by `groupKey()` (`ip:port`) while `combine()`/`addressKey()` keep `ip:port:protocol` for the address union; `ServerPersister` matches by `(ip,port)` and still upserts `(ip,port,protocol)` rows. `UpdateData::handle()` merges `DiscoveredServer[]` from both sources (DDNet first). The guard reads `DiscoveredClient::$afk` (`?bool` from Phase 4a): null (UDP) skips the cosmetic write, bool (DDNet) performs it. The 0.7 test source is injected via `$this->app->instance(Teeworlds07Source::class, …)` with a `FakeUdpTransport`, so no live UDP runs in the suite.
