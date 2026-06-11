# Multi-ecosystem Ingestion — Phase 2: DDNet HTTP Source — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a self-contained `DdnetHttpSource` that fetches the DDNet master `servers.json` (with mirror failover) and parses it into normalized, immutable value objects — including both skin encodings, `afk`, and a derived `flavor`.

**Architecture:** A new `App\TwStats\Discovery` namespace holds plain readonly value objects (`DiscoveredAddress`, `DiscoveredClient`, `DiscoveredServer`), a pure `DdnetServerListParser` (JSON string → value objects, tolerant of malformed entries), a `FlavorClassifier` (version string → server type), and the `DdnetHttpSource` adapter (HTTP fetch + failover, delegating to the parser). This phase produces a *source* only — it does NOT touch `UpdateData`, the DB, or the other adapters (that's the Phase 3 merge engine).

**Tech Stack:** Laravel 13, Guzzle via the `Illuminate\Support\Facades\Http` facade (already installed), PHP 8.5 (readonly promoted properties, named args, `new` in initializers). Tests: pure unit tests for the value objects/parser/classifier (`tests/Unit/Discovery`), a feature test with `Http::fake()` for the source (`tests/Feature/Discovery`). Run `vendor/bin/phpunit` inside the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§4.1 DdnetHttpSource, §5.5 extended client attributes, §9 classification, §13 treat the feed defensively).

**Scope note:** Phase 2 of five. The parsed `DiscoveredServer[]` is consumed by the Phase 3 merge/dedup engine (not built here). `flavor` is computed per-DDNet-source here; the final cross-source flavor decision is Phase 3.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/TwStats/Discovery/DiscoveredAddress.php` | Immutable `(ip, port, protocol)`; `fromUrl()` parses a `tw-0.6+udp://…` / `tw-0.7+udp://…` master URL. |
| `app/TwStats/Discovery/FlavorClassifier.php` | Pure `classify(version)` → `ddnet` / `vanilla_06` / `vanilla_07`. |
| `app/TwStats/Discovery/DiscoveredClient.php` | Immutable per-client data incl. skin/colors/`afk`/`skinParts`. |
| `app/TwStats/Discovery/DiscoveredServer.php` | Immutable logical server: addresses + info + clients + location + flavor. |
| `app/TwStats/Discovery/DdnetServerListParser.php` | Pure `parse(json)` → `DiscoveredServer[]`, skipping malformed entries. |
| `app/TwStats/Discovery/DdnetHttpSource.php` | HTTP GET across mirrors with failover; delegates to the parser. |
| `tests/Fixtures/ddnet_servers.json` | Golden `servers.json` covering dual-stack, both skin encodings, afk, and malformed entries. |
| `tests/Unit/Discovery/DiscoveredAddressTest.php` | URL parsing (ipv4, ipv6, invalid). |
| `tests/Unit/Discovery/FlavorClassifierTest.php` | Version → flavor. |
| `tests/Unit/Discovery/DdnetServerListParserTest.php` | Fixture → value objects (end-to-end parse). |
| `tests/Feature/Discovery/DdnetHttpSourceTest.php` | Mirror failover via `Http::fake()`. |

---

## Task 1: `DiscoveredAddress` value object + `fromUrl()`

**Files:**
- Create: `tests/Unit/Discovery/DiscoveredAddressTest.php`
- Create: `app/TwStats/Discovery/DiscoveredAddress.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Discovery/DiscoveredAddressTest.php`:

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\DiscoveredAddress;
use PHPUnit\Framework\TestCase;

class DiscoveredAddressTest extends TestCase
{
    public function test_parses_an_ipv4_0_6_master_url(): void
    {
        $address = DiscoveredAddress::fromUrl('tw-0.6+udp://192.0.2.10:8303');

        $this->assertNotNull($address);
        $this->assertSame('192.0.2.10', $address->ip);
        $this->assertSame(8303, $address->port);
        $this->assertSame(6, $address->protocol);
    }

    public function test_parses_an_ipv6_0_7_master_url_and_strips_brackets(): void
    {
        $address = DiscoveredAddress::fromUrl('tw-0.7+udp://[2001:db8::5]:8310');

        $this->assertNotNull($address);
        $this->assertSame('2001:db8::5', $address->ip);
        $this->assertSame(8310, $address->port);
        $this->assertSame(7, $address->protocol);
    }

    public function test_returns_null_for_non_teeworlds_or_malformed_urls(): void
    {
        $this->assertNull(DiscoveredAddress::fromUrl('http://example.com'));
        $this->assertNull(DiscoveredAddress::fromUrl('tw-0.5+udp://192.0.2.10:8303'));
        $this->assertNull(DiscoveredAddress::fromUrl('tw-0.6+udp://192.0.2.10'));
        $this->assertNull(DiscoveredAddress::fromUrl(''));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter DiscoveredAddressTest`
Expected: FAIL — `Class "App\TwStats\Discovery\DiscoveredAddress" not found`.

- [ ] **Step 3: Create the value object**

Create `app/TwStats/Discovery/DiscoveredAddress.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * One protocol-tagged endpoint of a discovered server. Immutable; produced by the source
 * adapters and consumed by the Phase 3 merge engine.
 */
final class DiscoveredAddress
{
    public function __construct(
        public readonly string $ip,
        public readonly int $port,
        public readonly int $protocol, // 6 or 7
    ) {
    }

    /**
     * parse a DDNet master address such as "tw-0.6+udp://192.0.2.10:8303" or
     * "tw-0.7+udp://[2001:db8::5]:8310". Only the UDP Teeworlds 0.6/0.7 protocols are
     * tracked; anything else (or a malformed url) yields null so the caller can skip it.
     */
    public static function fromUrl(string $url): ?self
    {
        if (!preg_match('#^tw-0\.([67])\+udp://(.+):(\d+)$#', $url, $m)) {
            return null;
        }

        $host = $m[2];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return new self($host, (int) $m[3], (int) $m[1]);
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter DiscoveredAddressTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Discovery/DiscoveredAddress.php tests/Unit/Discovery/DiscoveredAddressTest.php
git commit -m "feat(serverbrowser): add DiscoveredAddress value object with master-url parsing

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `FlavorClassifier`

**Files:**
- Create: `tests/Unit/Discovery/FlavorClassifierTest.php`
- Create: `app/TwStats/Discovery/FlavorClassifier.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Discovery/FlavorClassifierTest.php`:

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\FlavorClassifier;
use PHPUnit\Framework\TestCase;

class FlavorClassifierTest extends TestCase
{
    public function test_ddnet_when_version_carries_a_build_after_the_engine_version(): void
    {
        $this->assertSame('ddnet', FlavorClassifier::classify('0.6.4, 19.1'));
        $this->assertSame('ddnet', FlavorClassifier::classify('0.6.5, 18.8'));
    }

    public function test_vanilla_07_for_an_07_engine_version(): void
    {
        $this->assertSame('vanilla_07', FlavorClassifier::classify('0.7.5'));
    }

    public function test_vanilla_06_for_a_plain_06_engine_version(): void
    {
        $this->assertSame('vanilla_06', FlavorClassifier::classify('0.6.4'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter FlavorClassifierTest`
Expected: FAIL — `Class "App\TwStats\Discovery\FlavorClassifier" not found`.

- [ ] **Step 3: Create the classifier**

Create `app/TwStats/Discovery/FlavorClassifier.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * Derives the server-type label from the reported version string. DDNet servers append
 * their build to the engine version (e.g. "0.6.4, 19.1"); vanilla servers report only the
 * engine version ("0.6.4" / "0.7.5"). Heuristic — revisit if DDNet changes version reporting.
 */
final class FlavorClassifier
{
    public const FLAVOR_DDNET = 'ddnet';
    public const FLAVOR_VANILLA_06 = 'vanilla_06';
    public const FLAVOR_VANILLA_07 = 'vanilla_07';

    public static function classify(string $version): string
    {
        if (str_contains($version, ',') || stripos($version, 'ddnet') !== false) {
            return self::FLAVOR_DDNET;
        }

        if (str_starts_with($version, '0.7')) {
            return self::FLAVOR_VANILLA_07;
        }

        return self::FLAVOR_VANILLA_06;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter FlavorClassifierTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Discovery/FlavorClassifier.php tests/Unit/Discovery/FlavorClassifierTest.php
git commit -m "feat(serverbrowser): add FlavorClassifier for server-type detection

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: client/server value objects + `DdnetServerListParser` + fixture

**Files:**
- Create: `tests/Fixtures/ddnet_servers.json`
- Create: `tests/Unit/Discovery/DdnetServerListParserTest.php`
- Create: `app/TwStats/Discovery/DiscoveredClient.php`
- Create: `app/TwStats/Discovery/DiscoveredServer.php`
- Create: `app/TwStats/Discovery/DdnetServerListParser.php`

- [ ] **Step 1: Create the fixture**

Create `tests/Fixtures/ddnet_servers.json`:

```json
{
  "servers": [
    {
      "addresses": ["tw-0.6+udp://192.0.2.10:8303", "tw-0.7+udp://192.0.2.10:8303"],
      "location": "eu",
      "info": {
        "name": "DDNet GER10",
        "map": {"name": "Multeasymap"},
        "game_type": "DDraceNetwork",
        "version": "0.6.4, 19.1",
        "max_clients": 64,
        "max_players": 64,
        "clients": [
          {"name": "vin", "clan": "", "country": -102, "score": -1, "is_player": true, "afk": false,
           "skin": {"name": "glow_cammo", "color_body": 16726016, "color_feet": 16745499}},
          {"name": "glow", "clan": "GLOW", "country": -1, "score": 120, "is_player": true, "afk": true,
           "skin": {"body": {"name": "standard", "color": 65408}, "marking": {"name": "duodonny", "color": 65408}}}
        ]
      }
    },
    {
      "addresses": ["tw-0.7+udp://[2001:db8::5]:8310"],
      "location": "na",
      "info": {
        "name": "Vanilla 0.7 CTF",
        "map": {"name": "ctf1"},
        "game_type": "CTF",
        "version": "0.7.5",
        "max_clients": 16,
        "max_players": 16,
        "clients": [
          {"name": "Bob", "clan": "", "country": 840, "score": 3, "is_player": true}
        ]
      }
    },
    {
      "addresses": ["tw-0.6+udp://198.51.100.7:8303"],
      "info": {
        "name": "Vanilla 0.6 DM",
        "map": {"name": "dm1"},
        "game_type": "DM",
        "version": "0.6.4",
        "max_clients": 16,
        "max_players": 16,
        "clients": []
      }
    },
    {
      "addresses": ["tw-0.6+udp://203.0.113.9:8303"]
    },
    {
      "addresses": ["http://not-a-tw-url"],
      "info": {"name": "x", "map": {"name": "y"}, "game_type": "z", "version": "0.6.4", "max_clients": 8, "max_players": 8, "clients": []}
    }
  ]
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Discovery/DdnetServerListParserTest.php`:

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\DdnetServerListParser;
use PHPUnit\Framework\TestCase;

class DdnetServerListParserTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(__DIR__ . '/../../Fixtures/ddnet_servers.json');
    }

    public function test_skips_malformed_entries_and_parses_the_valid_servers(): void
    {
        $servers = (new DdnetServerListParser())->parse($this->fixture());

        // 5 entries: 1 missing info, 1 with no valid tw address → 3 valid servers
        $this->assertCount(3, $servers);
    }

    public function test_parses_a_dual_stack_ddnet_server_with_both_skin_encodings(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[0];

        $this->assertSame('DDNet GER10', $server->name);
        $this->assertSame('Multeasymap', $server->map);
        $this->assertSame('DDraceNetwork', $server->gametype);
        $this->assertSame('0.6.4, 19.1', $server->version);
        $this->assertSame(64, $server->maxClients);
        $this->assertSame(64, $server->maxPlayers);
        $this->assertSame('eu', $server->location);
        $this->assertSame('ddnet', $server->flavor);

        $this->assertCount(2, $server->addresses);
        $this->assertSame('192.0.2.10', $server->addresses[0]->ip);
        $this->assertSame(6, $server->addresses[0]->protocol);
        $this->assertSame(7, $server->addresses[1]->protocol);

        $this->assertCount(2, $server->clients);

        // client 1 — 0.6 skin + custom colors, not afk
        $vin = $server->clients[0];
        $this->assertSame('vin', $vin->name);
        $this->assertSame(-102, $vin->country);
        $this->assertSame(-1, $vin->score);
        $this->assertTrue($vin->isPlayer);
        $this->assertFalse($vin->afk);
        $this->assertSame('glow_cammo', $vin->skin);
        $this->assertSame(16726016, $vin->colorBody);
        $this->assertSame(16745499, $vin->colorFeet);
        $this->assertNull($vin->skinParts);

        // client 2 — 0.7 six-part skin, afk
        $glow = $server->clients[1];
        $this->assertSame('GLOW', $glow->clan);
        $this->assertTrue($glow->afk);
        $this->assertNull($glow->skin);
        $this->assertSame(['name' => 'standard', 'color' => 65408], $glow->skinParts['body']);
    }

    public function test_parses_a_vanilla_07_server_with_an_ipv6_address(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[1];

        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame('2001:db8::5', $server->addresses[0]->ip);
        $this->assertSame(7, $server->addresses[0]->protocol);
        $this->assertCount(1, $server->clients);
        $this->assertNull($server->clients[0]->skin);
        $this->assertFalse($server->clients[0]->afk); // afk absent → false
    }

    public function test_parses_a_vanilla_06_server_with_no_clients(): void
    {
        $server = (new DdnetServerListParser())->parse($this->fixture())[2];

        $this->assertSame('vanilla_06', $server->flavor);
        $this->assertSame([], $server->clients);
    }

    public function test_returns_empty_for_unusable_json(): void
    {
        $parser = new DdnetServerListParser();

        $this->assertSame([], $parser->parse('not json'));
        $this->assertSame([], $parser->parse('{"nope": 1}'));
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter DdnetServerListParserTest`
Expected: FAIL — `Class "App\TwStats\Discovery\DdnetServerListParser" not found`.

- [ ] **Step 4: Create `DiscoveredClient`**

Create `app/TwStats/Discovery/DiscoveredClient.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * One client (player or spectator) on a discovered server. Skin/colors/afk come only from
 * the DDNet HTTP feed; for UDP-only sources they are null/false.
 */
final class DiscoveredClient
{
    public function __construct(
        public readonly string $name,
        public readonly string $clan,
        public readonly int $country,
        public readonly int $score,
        public readonly bool $isPlayer,
        public readonly bool $afk,
        public readonly ?string $skin,      // 0.6 skin name
        public readonly ?int $colorBody,    // 0.6 custom tee colors (null = default)
        public readonly ?int $colorFeet,
        public readonly ?array $skinParts,  // 0.7 six-part skin {body:{name,color}, ...}
    ) {
    }
}
```

- [ ] **Step 5: Create `DiscoveredServer`**

Create `app/TwStats/Discovery/DiscoveredServer.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * A normalized logical server produced by a source adapter. The address set is its identity
 * (mirroring DDNet's servers.json grouping); the Phase 3 merge engine dedups across sources.
 */
final class DiscoveredServer
{
    /**
     * @param DiscoveredAddress[] $addresses
     * @param DiscoveredClient[] $clients
     */
    public function __construct(
        public readonly array $addresses,
        public readonly string $name,
        public readonly string $map,
        public readonly string $gametype,
        public readonly string $version,
        public readonly int $maxClients,
        public readonly int $maxPlayers,
        public readonly array $clients,
        public readonly ?string $location,
        public readonly string $flavor,
    ) {
    }
}
```

- [ ] **Step 6: Create the parser**

Create `app/TwStats/Discovery/DdnetServerListParser.php`:

```php
<?php

namespace App\TwStats\Discovery;

/**
 * Parses a DDNet master `servers.json` body into normalized servers. The feed is an external
 * contract, so every entry/field is validated and anything malformed is skipped rather than
 * aborting the whole list.
 */
class DdnetServerListParser
{
    /**
     * @return DiscoveredServer[]
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['servers']) || !is_array($decoded['servers'])) {
            return [];
        }

        $servers = [];
        foreach ($decoded['servers'] as $entry) {
            $server = $this->parseServer($entry);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    private function parseServer(mixed $entry): ?DiscoveredServer
    {
        if (!is_array($entry) || !isset($entry['addresses'], $entry['info'])
            || !is_array($entry['addresses']) || !is_array($entry['info'])) {
            return null;
        }

        $addresses = [];
        foreach ($entry['addresses'] as $url) {
            if (is_string($url) && ($address = DiscoveredAddress::fromUrl($url)) !== null) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            return null;
        }

        $info = $entry['info'];
        foreach (['name', 'map', 'game_type', 'version', 'max_clients', 'max_players', 'clients'] as $key) {
            if (!array_key_exists($key, $info)) {
                return null;
            }
        }
        if (!is_array($info['map']) || !isset($info['map']['name']) || !is_array($info['clients'])) {
            return null;
        }

        $clients = [];
        foreach ($info['clients'] as $client) {
            if (is_array($client) && ($parsed = $this->parseClient($client)) !== null) {
                $clients[] = $parsed;
            }
        }

        return new DiscoveredServer(
            addresses: $addresses,
            name: (string) $info['name'],
            map: (string) $info['map']['name'],
            gametype: (string) $info['game_type'],
            version: (string) $info['version'],
            maxClients: (int) $info['max_clients'],
            maxPlayers: (int) $info['max_players'],
            clients: $clients,
            location: isset($entry['location']) && is_string($entry['location']) ? $entry['location'] : null,
            flavor: FlavorClassifier::classify((string) $info['version']),
        );
    }

    private function parseClient(array $client): ?DiscoveredClient
    {
        foreach (['name', 'clan', 'country', 'score', 'is_player'] as $key) {
            if (!array_key_exists($key, $client)) {
                return null;
            }
        }

        [$skin, $colorBody, $colorFeet, $skinParts] = $this->parseSkin($client['skin'] ?? null);

        return new DiscoveredClient(
            name: (string) $client['name'],
            clan: (string) $client['clan'],
            country: (int) $client['country'],
            score: (int) $client['score'],
            isPlayer: (bool) $client['is_player'],
            afk: (bool) ($client['afk'] ?? false),
            skin: $skin,
            colorBody: $colorBody,
            colorFeet: $colorFeet,
            skinParts: $skinParts,
        );
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int, 3: ?array}
     */
    private function parseSkin(mixed $skin): array
    {
        if (!is_array($skin)) {
            return [null, null, null, null];
        }

        // 0.6 skin: a single skin name plus optional custom tee colors
        if (isset($skin['name']) && is_string($skin['name'])) {
            $colorBody = isset($skin['color_body']) && is_int($skin['color_body']) ? $skin['color_body'] : null;
            $colorFeet = isset($skin['color_feet']) && is_int($skin['color_feet']) ? $skin['color_feet'] : null;

            return [$skin['name'], $colorBody, $colorFeet, null];
        }

        // 0.7 skin: six parts {body:{name,color}, ...}
        if (isset($skin['body']) && is_array($skin['body'])) {
            return [null, null, null, $skin];
        }

        return [null, null, null, null];
    }
}
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter DdnetServerListParserTest`
Expected: PASS (5 tests green).

- [ ] **Step 8: Commit**

```bash
git add app/TwStats/Discovery/DiscoveredClient.php app/TwStats/Discovery/DiscoveredServer.php \
        app/TwStats/Discovery/DdnetServerListParser.php \
        tests/Fixtures/ddnet_servers.json tests/Unit/Discovery/DdnetServerListParserTest.php
git commit -m "feat(serverbrowser): parse DDNet servers.json into normalized value objects

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: `DdnetHttpSource` (fetch + mirror failover)

**Files:**
- Create: `tests/Feature/Discovery/DdnetHttpSourceTest.php`
- Create: `app/TwStats/Discovery/DdnetHttpSource.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Discovery/DdnetHttpSourceTest.php`:

```php
<?php

namespace Tests\Feature\Discovery;

use App\TwStats\Discovery\DdnetHttpSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DdnetHttpSourceTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ddnet_servers.json'));
    }

    public function test_fetches_and_parses_from_the_first_responding_mirror(): void
    {
        Http::fake([
            'https://a/*' => Http::response($this->fixture(), 200),
            'https://b/*' => Http::response('', 500),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);
        $servers = $source->fetch();

        $this->assertCount(3, $servers);
        $this->assertSame('DDNet GER10', $servers[0]->name);
    }

    public function test_fails_over_to_the_next_mirror_when_one_errors(): void
    {
        Http::fake([
            'https://a/*' => Http::response('', 500),
            'https://b/*' => Http::response($this->fixture(), 200),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);
        $servers = $source->fetch();

        $this->assertCount(3, $servers);
    }

    public function test_returns_empty_when_every_mirror_fails(): void
    {
        Http::fake([
            'https://a/*' => Http::response('', 500),
            'https://b/*' => Http::response('', 503),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);

        $this->assertSame([], $source->fetch());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter DdnetHttpSourceTest`
Expected: FAIL — `Class "App\TwStats\Discovery\DdnetHttpSource" not found`.

- [ ] **Step 3: Create the source**

Create `app/TwStats/Discovery/DdnetHttpSource.php`:

```php
<?php

namespace App\TwStats\Discovery;

use Illuminate\Support\Facades\Http;

/**
 * Discovers servers from the DDNet master `servers.json` over HTTP. This is the only source
 * that carries real (un-capped) limits, full player lists, and skins, and it groups a logical
 * server's addresses for us. Mirrors are tried in turn; if all fail the scrape cycle proceeds
 * with the other (UDP) sources, so a total failure returns [] rather than throwing.
 */
class DdnetHttpSource
{
    public const MIRRORS = [
        'https://master1.ddnet.org/ddnet/15/servers.json',
        'https://master2.ddnet.org/ddnet/15/servers.json',
        'https://master3.ddnet.org/ddnet/15/servers.json',
        'https://master4.ddnet.org/ddnet/15/servers.json',
    ];

    /**
     * @param string[] $mirrors
     */
    public function __construct(
        private readonly DdnetServerListParser $parser = new DdnetServerListParser(),
        private readonly array $mirrors = self::MIRRORS,
    ) {
    }

    /**
     * @return DiscoveredServer[]
     */
    public function fetch(): array
    {
        foreach ($this->mirrors as $url) {
            try {
                $response = Http::timeout(10)->get($url);
                if ($response->successful()) {
                    return $this->parser->parse($response->body());
                }
            } catch (\Throwable) {
                // mirror unreachable — fall through to the next one
            }
        }

        return [];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter DdnetHttpSourceTest`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Discovery/DdnetHttpSource.php tests/Feature/Discovery/DdnetHttpSourceTest.php
git commit -m "feat(serverbrowser): add DdnetHttpSource with master mirror failover

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: all green — the Phase 1 tests (40) plus the new Phase 2 unit + feature tests, with no regressions. (Phase 2 adds no migrations and touches no existing files, so regressions are not expected.)

- [ ] **Step 2: Commit (only if a fixup was needed)**

No code changes expected here; if Step 1 surfaced a needed fixup, commit it with a clear message. Otherwise skip.

---

## Self-Review

**Spec coverage (Phase 2 slice of §4.1/§5.5/§9/§13):**
- HTTP fetch of `servers.json` with mirror failover → Task 4 (`DdnetHttpSource`). ✓
- Parse `addresses[]` (protocol-tagged) → `DiscoveredAddress::fromUrl` (Task 1) + parser (Task 3). ✓
- Parse `info` (name/map/game_type/version/max_clients/max_players/clients) → Task 3. ✓
- Extended client attributes — both skin encodings + `afk` (§5.5) → `DiscoveredClient` + `parseSkin` (Task 3), covered by fixture + assertions. ✓
- Server-type classification (§9) → `FlavorClassifier` (Task 2), surfaced on `DiscoveredServer.flavor`. ✓
- Defensive parsing (§13) — skip malformed entries/fields, tolerate bad JSON → `parse`/`parseServer`/`parseClient`/`parseSkin` (Task 3), covered by the two malformed fixture entries + the bad-JSON test. ✓
- Out of scope for Phase 2 (Phase 3): merge/dedup across sources, persistence, `UpdateData` wiring, cross-source info-resolution. The `location` continent code is captured on `DiscoveredServer` for later use but not yet persisted.

**Placeholder scan:** none — every step has full code + exact commands.

**Type consistency:** value-object property names are stable across tasks — `DiscoveredAddress(ip, port, protocol)`; `DiscoveredClient(name, clan, country, score, isPlayer, afk, skin, colorBody, colorFeet, skinParts)`; `DiscoveredServer(addresses, name, map, gametype, version, maxClients, maxPlayers, clients, location, flavor)`. The parser constructs these with named arguments matching those exact names. `FlavorClassifier::classify(string): string` returns the `FLAVOR_*` constant values (`ddnet`/`vanilla_06`/`vanilla_07`) asserted in both the classifier test and the parser test. `DdnetHttpSource` depends only on `DdnetServerListParser::parse(string): DiscoveredServer[]`.
