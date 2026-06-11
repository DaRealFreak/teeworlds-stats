# Multi-ecosystem Ingestion — Phase 3a: Merge + Persistence Engine — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `DiscoveredServer[]` (from the source adapters) into persisted logical servers: group servers that share an address into one, dedup their players by `(name, clan)`, and upsert the `Server` + `server_addresses` rows keyed on the address-set identity.

**Architecture:** Two small units. `App\TwStats\Discovery\ServerMerger` is a pure transform — `DiscoveredServer[] → DiscoveredServer[]` — that unions the addresses of any servers sharing an endpoint, dedups clients (first occurrence wins, so callers pass higher-priority sources first), and keeps the first group member's metadata. `App\TwStats\Persistence\ServerPersister` writes one merged `DiscoveredServer` to the DB: it finds the logical `Server` by any of its addresses (else creates it), refreshes name/version/flavor/last_seen and the denormalised canonical ip/port, and syncs the `server_addresses` rows. This phase is additive — it does NOT touch `UpdateData`, players, histories, or the UI (those are Phases 3b/3c).

**Tech Stack:** Laravel 13, Eloquent, PHP 8.5. Pure-logic merger test in `tests/Unit`; persister test in `tests/Feature` with `RefreshDatabase`. Run `vendor/bin/phpunit` in the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§7 identity & dedup, §8 info resolution, §6 data model). Depends on Phase 1 (`Server`, `ServerAddress`, `servers.flavor`) and Phase 2 (`DiscoveredServer`/`DiscoveredClient`/`DiscoveredAddress`).

**Scope note:** Phase 3a of the Phase 3 group. 3b wires `DdnetHttpSource` → merger → persister into `UpdateData` (reusing the existing player/history/session logic + cosmetics) and is behaviour-changing; 3c adds the serverbrowser type display. The merger is built to handle multi-source input (grouping/dedup) even though Phase 3's only live source is DDNet — it's tested with synthetic multi-source inputs so Phase 4 can rely on it.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/TwStats/Discovery/ServerMerger.php` | Pure `merge(DiscoveredServer[]): DiscoveredServer[]` — group by shared address, union addresses, dedup clients by `(name,clan)`, first-wins metadata. |
| `app/TwStats/Persistence/ServerPersister.php` | `persist(DiscoveredServer): Server` — address-set-keyed upsert of `Server` + `server_addresses` + flavor + canonical pointer. |
| `tests/Unit/Discovery/ServerMergerTest.php` | Grouping + client dedup with synthetic single- and multi-source inputs. |
| `tests/Feature/Persistence/ServerPersisterTest.php` | DB: create, cross-cycle reuse by any shared address, canonical + dual-stack. |

---

## Task 1: `ServerMerger`

**Files:**
- Create: `tests/Unit/Discovery/ServerMergerTest.php`
- Create: `app/TwStats/Discovery/ServerMerger.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Discovery/ServerMergerTest.php`:

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredClient;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Discovery\ServerMerger;
use PHPUnit\Framework\TestCase;

class ServerMergerTest extends TestCase
{
    private function client(string $name, string $clan = ''): DiscoveredClient
    {
        return new DiscoveredClient($name, $clan, 0, 0, true, false, null, null, null, null);
    }

    private function server(array $addresses, array $clients, string $name = 'S', string $flavor = 'ddnet'): DiscoveredServer
    {
        return new DiscoveredServer($addresses, $name, 'm', 'g', '0.6.4', 64, 64, $clients, null, $flavor);
    }

    public function test_servers_without_a_shared_address_are_left_separate(): void
    {
        $a = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('x')]);
        $b = $this->server([new DiscoveredAddress('192.0.2.2', 8303, 6)], [$this->client('y')]);

        $merged = (new ServerMerger())->merge([$a, $b]);

        $this->assertCount(2, $merged);
    }

    public function test_servers_sharing_an_address_merge_into_one_with_unioned_addresses(): void
    {
        // a sixup server seen by two sources: source 1 reports the 0.6 endpoint, source 2 the 0.7
        $first = $this->server(
            [new DiscoveredAddress('192.0.2.1', 8303, 6)],
            [$this->client('alice'), $this->client('bob', 'CLAN')],
            name: 'From Source One',
        );
        $second = $this->server(
            [new DiscoveredAddress('192.0.2.1', 8303, 6), new DiscoveredAddress('192.0.2.1', 8303, 7)],
            [$this->client('bob', 'CLAN'), $this->client('carol')],
            name: 'From Source Two',
        );

        $merged = (new ServerMerger())->merge([$first, $second]);

        $this->assertCount(1, $merged);
        $server = $merged[0];

        // first-in-input wins on metadata
        $this->assertSame('From Source One', $server->name);

        // addresses unioned (6 from both, plus 7 from the second), deduped
        $this->assertCount(2, $server->addresses);
        $this->assertSame([6, 7], array_map(fn ($a) => $a->protocol, $server->addresses));

        // clients deduped by (name, clan): alice, bob/CLAN (once), carol
        $names = array_map(fn ($c) => $c->name, $server->clients);
        $this->assertSame(['alice', 'bob', 'carol'], $names);
    }

    public function test_same_name_different_clan_are_distinct_clients(): void
    {
        $a = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('bob', 'RED')]);
        $b = $this->server([new DiscoveredAddress('192.0.2.1', 8303, 6)], [$this->client('bob', 'BLUE')]);

        $merged = (new ServerMerger())->merge([$a, $b]);

        $this->assertCount(1, $merged);
        $this->assertCount(2, $merged[0]->clients); // distinct clans → not deduped
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ServerMergerTest`
Expected: FAIL — `Class "App\TwStats\Discovery\ServerMerger" not found`.

- [ ] **Step 3: Create the merger**

Create `app/TwStats/Discovery/ServerMerger.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * Combines discovered servers from one or more sources into logical servers: any servers that
 * share an address are the same logical server (mirroring how the DDNet master groups a server's
 * addresses). Earlier servers in the input win on metadata and on client identity, so callers
 * pass higher-priority sources first. Clients are deduped by (name, clan) — a sixup server
 * reports the same humans on its 0.6 and 0.7 lists, and they must not be double-counted.
 *
 * Assumes a server's address set is stable across sources (no entry bridges two pre-existing
 * groups); that holds for the real feeds, where a logical server's endpoints are consistent.
 */
final class ServerMerger
{
    /**
     * @param DiscoveredServer[] $servers
     * @return DiscoveredServer[]
     */
    public function merge(array $servers): array
    {
        /** @var DiscoveredServer[] $groups */
        $groups = [];
        $addressToGroup = []; // address key => group id

        foreach ($servers as $server) {
            $groupId = null;
            foreach ($server->addresses as $address) {
                $key = $this->addressKey($address);
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
                $addressToGroup[$this->addressKey($address)] = $groupId;
            }
        }

        return array_values($groups);
    }

    private function combine(DiscoveredServer $primary, DiscoveredServer $secondary): DiscoveredServer
    {
        $addresses = $primary->addresses;
        $seenAddresses = array_map([$this, 'addressKey'], $addresses);
        foreach ($secondary->addresses as $address) {
            $key = $this->addressKey($address);
            if (!in_array($key, $seenAddresses, true)) {
                $addresses[] = $address;
                $seenAddresses[] = $key;
            }
        }

        $clients = $primary->clients;
        $seenClients = array_map([$this, 'clientKey'], $clients);
        foreach ($secondary->clients as $client) {
            $key = $this->clientKey($client);
            if (!in_array($key, $seenClients, true)) {
                $clients[] = $client;
                $seenClients[] = $key;
            }
        }

        // primary (higher-priority / earlier) source wins on metadata
        return new DiscoveredServer(
            addresses: $addresses,
            name: $primary->name,
            map: $primary->map,
            gametype: $primary->gametype,
            version: $primary->version,
            maxClients: $primary->maxClients,
            maxPlayers: $primary->maxPlayers,
            clients: $clients,
            location: $primary->location,
            flavor: $primary->flavor,
        );
    }

    private function addressKey(DiscoveredAddress $address): string
    {
        return $address->ip . ':' . $address->port . ':' . $address->protocol;
    }

    private function clientKey(DiscoveredClient $client): string
    {
        return $client->name . "\x00" . $client->clan;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ServerMergerTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Discovery/ServerMerger.php tests/Unit/Discovery/ServerMergerTest.php
git commit -m "feat(serverbrowser): add ServerMerger to group servers and dedup players

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `ServerPersister`

**Files:**
- Create: `tests/Feature/Persistence/ServerPersisterTest.php`
- Create: `app/TwStats/Persistence/ServerPersister.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Persistence/ServerPersisterTest.php`:

```php
<?php

namespace Tests\Feature\Persistence;

use App\Models\Server;
use App\Models\ServerAddress;
use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Persistence\ServerPersister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerPersisterTest extends TestCase
{
    use RefreshDatabase;

    private function server(array $addresses, string $name = 'GER10', string $flavor = 'ddnet'): DiscoveredServer
    {
        return new DiscoveredServer($addresses, $name, 'Multeasymap', 'DDraceNetwork', '0.6.4, 19.1', 64, 64, [], 'eu', $flavor);
    }

    public function test_creates_a_logical_server_with_its_addresses_flavor_and_canonical_pointer(): void
    {
        $discovered = $this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 6),
            new DiscoveredAddress('192.0.2.10', 8303, 7),
        ]);

        $server = (new ServerPersister())->persist($discovered);

        $this->assertDatabaseCount('servers', 1);
        $this->assertSame('GER10', $server->name);
        $this->assertSame('ddnet', $server->flavor);
        // the first address is the denormalised canonical pointer kept on servers
        $this->assertSame('192.0.2.10', $server->ip);
        $this->assertSame(8303, $server->port);

        $this->assertSame([6, 7], $server->fresh()->protocols());
        $this->assertSame(6, $server->fresh()->canonicalAddress->protocol);
        $this->assertSame(1, ServerAddress::where('is_canonical', true)->where('server_id', $server->id)->count());
    }

    public function test_reuses_the_same_logical_server_on_a_later_cycle(): void
    {
        $persister = new ServerPersister();
        $discovered = $this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)]);

        $first = $persister->persist($discovered);
        $second = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 6)], name: 'GER10 renamed'));

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('servers', 1);
        $this->assertDatabaseCount('server_addresses', 1);
        $this->assertSame('GER10 renamed', $second->name);
    }

    public function test_matches_an_existing_server_by_any_shared_address(): void
    {
        $persister = new ServerPersister();
        $created = $persister->persist($this->server([
            new DiscoveredAddress('192.0.2.10', 8303, 6),
            new DiscoveredAddress('192.0.2.10', 8303, 7),
        ]));

        // a later discovery that only carries the 0.7 endpoint still resolves to the same server
        $matched = $persister->persist($this->server([new DiscoveredAddress('192.0.2.10', 8303, 7)], name: 'still GER10'));

        $this->assertSame($created->id, $matched->id);
        $this->assertDatabaseCount('servers', 1);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ServerPersisterTest`
Expected: FAIL — `Class "App\TwStats\Persistence\ServerPersister" not found`.

- [ ] **Step 3: Create the persister**

Create `app/TwStats/Persistence/ServerPersister.php`:

```php
<?php

namespace App\TwStats\Persistence;

use App\Models\Server;
use App\Models\ServerAddress;
use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredServer;
use Carbon\Carbon;

/**
 * Writes one merged discovery result to the DB. The address set is the server's identity, so the
 * logical Server is found by any of its addresses (matching how a sixup server is reachable under
 * several protocols); otherwise it is created. ip/port stay on servers as the denormalised
 * canonical pointer for existing consumers; the full protocol-tagged set lives in server_addresses.
 */
class ServerPersister
{
    public function persist(DiscoveredServer $server): Server
    {
        $canonical = $server->addresses[0];

        $existingAddress = ServerAddress::query()
            ->where(function ($query) use ($server) {
                foreach ($server->addresses as $address) {
                    $query->orWhere(function ($match) use ($address) {
                        $match->where('ip', $address->ip)
                            ->where('port', $address->port)
                            ->where('protocol', $address->protocol);
                    });
                }
            })
            ->first();

        $model = $existingAddress?->server ?? new Server();

        $model->setAttribute('name', $server->name);
        $model->setAttribute('version', $server->version);
        $model->setAttribute('flavor', $server->flavor);
        $model->setAttribute('ip', $canonical->ip);
        $model->setAttribute('port', $canonical->port);
        $model->setAttribute('last_seen', Carbon::now());
        $model->save();

        $this->syncAddresses($model, $server->addresses);

        return $model;
    }

    /**
     * @param DiscoveredAddress[] $addresses
     */
    private function syncAddresses(Server $model, array $addresses): void
    {
        // exactly one address is canonical (the first); clear any stale flag before re-marking
        ServerAddress::where('server_id', $model->getAttribute('id'))->update(['is_canonical' => false]);

        foreach ($addresses as $index => $address) {
            ServerAddress::updateOrCreate(
                [
                    'ip' => $address->ip,
                    'port' => $address->port,
                    'protocol' => $address->protocol,
                ],
                [
                    'server_id' => $model->getAttribute('id'),
                    'is_canonical' => $index === 0,
                ],
            );
        }
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ServerPersisterTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Persistence/ServerPersister.php tests/Feature/Persistence/ServerPersisterTest.php
git commit -m "feat(serverbrowser): add ServerPersister keyed on the server address set

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all green — Phases 1+2 plus the new merger + persister tests, no regressions (this phase adds no migrations and touches no existing files).

- [ ] **Step 2: Commit (only if a fixup was needed)**

No code changes expected here; commit any needed fixup with a clear message, otherwise skip.

---

## Self-Review

**Spec coverage (Phase 3a slice of §7/§8/§6):**
- Address-set logical identity, find-by-any-shared-address → `ServerPersister::persist` (Task 2). ✓
- Group servers sharing an address into one → `ServerMerger::merge` (Task 1). ✓
- Player dedup by `(name, clan)` → `ServerMerger` client dedup (Task 1). ✓
- First-wins metadata so callers can express source priority (§8) → `ServerMerger::combine` (Task 1). ✓
- Persist `Server` + `server_addresses` + `flavor` + canonical pointer (§6) → Task 2. ✓
- Out of scope for 3a (Phases 3b/3c): `UpdateData` wiring, player/clan/history/session persistence, cosmetics mapping, and the serverbrowser type display. The merged `clients` list is carried on the returned `DiscoveredServer` for 3b to consume; this phase persists only the server + addresses.

**Placeholder scan:** none — every step has full code + exact commands.

**Type consistency:** `ServerMerger::merge(DiscoveredServer[]): DiscoveredServer[]` and `combine` reconstruct `DiscoveredServer` with the exact Phase 2 constructor signature (`addresses, name, map, gametype, version, maxClients, maxPlayers, clients, location, flavor`). `ServerPersister::persist(DiscoveredServer): Server` uses the Phase 1 `Server` (with `flavor`, `ip`, `port`) and `ServerAddress` (`ip`, `port`, `protocol`, `is_canonical`, `server_id`) plus `Server::protocols()`/`canonicalAddress()`. Address identity key is `ip:port:protocol` in both the merger (string key) and the persister (DB `unique(ip,port,protocol)`), so the two agree on what "the same address" means.
