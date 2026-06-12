# Multi-ecosystem Ingestion — Phase 6: Native Teeworlds 0.6 Source (resilience fallback) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Add a native UDP discovery source for stock Teeworlds 0.6 servers, querying the live `teeworlds.com:8300` master directly, so the 0.6 population survives a DDNet HTTP-master outage.

**Architecture:** Mirror the existing 0.7 source. A new `App\TwStats\Protocol\Six` codec (connless framing + master-list + info parsing) plus a `App\TwStats\Discovery\Teeworlds06Source` orchestrator driven by the injectable `App\TwStats\Net\UdpTransport`. Wire it into `UpdateData` as the **lowest-priority** source (`array_merge(ddnet, seven, six)`), so the existing ip:port merge + first-wins-metadata + cosmetic-clobber guard keep DDNet's richer data winning whenever DDNet is up. When DDNet is down, this source captures the ~1248 responding 0.6 servers on its own.

**Tech Stack:** Laravel 13, PHP 8.3+. Run `vendor/bin/phpunit`.

---

## Background: why this exists, and the protocol

**Why (measured 2026-06-12):** The `teeworlds.com:8300` vanilla-0.6 master is alive and returns ~1256 addresses. The DDNet `servers.json` feed mirrors it to within **1 server out of 1248** today — but DDNet is a community-financed mod and a single point of failure. If its HTTP master disappears, ~all 0.6 coverage vanishes unless we can query the 0.6 master ourselves. This source is that insurance. Its data is normally **discarded by the merge** (DDNet wins on ip:port), so completeness of its player lists is a low bar; correctness of discovery + metadata is the real goal.

**The 0.6 "token-extended" connless protocol (verified against `/var/www/html/ddnet` `src/engine/shared/network.cpp` + `src/engine/server/server.cpp`):**

- A request datagram is: `"xe"` (2-byte `NET_HEADER_EXTENDED`) + 4-byte *extra* token + payload. Payload = `"\xff\xff\xff\xff"` + 4-char command. For a server **info** request the payload additionally ends with a 1-byte info token that the server echoes back. The legacy scraper used `sprintf("xe%s\0\0%s", $reqToken2, $data)` — i.e. the 4-byte extra = 2 random bytes + `"\0\0"`. **The `"xe"` extended header is the signal that makes a 0.6 server answer with *extended* (`iext`) info instead of 16-capped vanilla `inf3`.**
- A response datagram has the same 6-byte framing: `"xe"` + 4-byte extra, then at **offset 6** an 8-byte command (`"\xff\xff\xff\xff"` + 4 chars), then at **offset 14** the payload.
- Commands (each is `"\xff\xff\xff\xff"` + 4 chars):
  - `cou2` GETCOUNT → `siz2` COUNT (2-byte big-endian count at payload offset 0; not needed by this source)
  - `req2` GETLIST → `lis2` LIST (18-byte address entries; see SixListCodec)
  - `gie3` GETINFO → `inf3` INFO (vanilla, ≤16 clients) **or** `iext` INFO_EXTENDED / `iex+` INFO_EXTENDED_MORE
- **Info payload is NUL-delimited ASCII** (`CPacker::AddString` + `ADD_INT` = `str_format("%d")` then `AddString`). Split on `"\x00"`. The first field is always the echoed info token (ASCII decimal); skip it.
  - `inf3` (vanilla): `[token, version, name, map, gametype, flags, numPlayers, maxPlayers, numClients, maxClients, then per-client × (name, clan, country, score, isPlayer)]`. `isPlayer`: 1 = player, 0 = spectator. Capped at 16 clients.
  - `iext` (extended, first packet): `[token, version, name, map, mapCrc, mapSize, gametype, flags, numPlayers, maxPlayers, numClients, maxClients, "" (reserved), then per-client × (name, clan, country, score, isPlayer, "" (reserved))]`.
  - `iex+` (extended continuation): `[token, chunkNumber, "" (reserved), then per-client × (name, clan, country, score, isPlayer, "" (reserved))]`. **No server header** — only more clients. Reassembled by matching the response source ip:port.

**Scope decisions (deliberate, documented):**
- Implement vanilla `inf3` + extended `iext`/`iex+`. **Skip the 64-player legacy `fstd`/`dtsf` path** — it is a deprecated pre-extended hack; every server that speaks it also answers the extended protocol, which the `"xe"` framing already requests. Omitting it avoids the messy variable-int offset quirk for zero real coverage loss.
- Match responses to servers by **source ip:port** (from `recvfrom`), exactly as `Teeworlds07Source` does — no per-server token validation at the app layer. The token is anti-spoofing for a game client; for a public-data scraper, source matching is sufficient and keeps the codec pure.
- A server that is in the master list but never answers an info query yields **no** `DiscoveredServer` (a `DiscoveredServer` needs name/map, which only the info response carries). That matches the legacy "failed servers" behaviour and is fine for a fallback.

**Reference files to read before implementing (do NOT modify the 0.7 ones — mirror them):**
- `app/TwStats/Discovery/Teeworlds07Source.php` — the orchestration shape to mirror (drain loop, chunking, masters).
- `app/TwStats/Net/UdpTransport.php`, `app/TwStats/Net/SocketUdpTransport.php`, `tests/Support/FakeUdpTransport.php` — the transport + test double.
- `app/TwStats/Protocol/Seven/SevenListCodec.php` — the lis2 parser to mirror for 0.6.
- `app/TwStats/Model/DiscoveredServer.php`, `DiscoveredClient.php`, `DiscoveredAddress.php` — the value objects to produce.
- `app/TwStats/Discovery/FlavorClassifier.php` — reuse `FlavorClassifier::classify($version)` (already returns `vanilla_06`/`ddnet`).

---

## Task 1: SixConnless — connless framing

**Files:**
- Create: `app/TwStats/Protocol/Six/SixConnless.php`
- Test: `tests/Unit/Protocol/Six/SixConnlessTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixConnless;
use PHPUnit\Framework\TestCase;

class SixConnlessTest extends TestCase
{
    public function test_get_list_builds_extended_header_plus_req2(): void
    {
        $packet = SixConnless::getList("\x12\x34");

        // "xe" + 4-byte extra (2 token bytes + "\0\0") + "\xff\xff\xff\xff" + "req2"
        $this->assertSame("xe\x12\x34\x00\x00\xff\xff\xff\xffreq2", $packet);
    }

    public function test_get_info_appends_the_one_byte_info_token(): void
    {
        $packet = SixConnless::getInfo("\xaa\xbb", "\x7f");

        $this->assertSame("xe\xaa\xbb\x00\x00\xff\xff\xff\xffgie3\x7f", $packet);
    }

    public function test_parse_extracts_command_and_payload_at_the_right_offsets(): void
    {
        // 6-byte framing + 8-byte command + payload
        $datagram = "xe\x00\x00\x00\x00\xff\xff\xff\xfflis2" . "PAYLOAD";

        $parsed = SixConnless::parse($datagram);

        $this->assertSame('lis2', $parsed['command']);
        $this->assertSame('PAYLOAD', $parsed['payload']);
    }

    public function test_parse_returns_null_for_a_too_short_datagram(): void
    {
        $this->assertNull(SixConnless::parse('short'));
    }

    public function test_parse_returns_null_when_the_extended_header_is_missing(): void
    {
        // 14 bytes but not starting with "xe"
        $this->assertNull(SixConnless::parse("\xff\xff\xff\xff\xff\xff\xff\xff\xff\xfflis2"));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixConnlessTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\TwStats\Protocol\Six;

/**
 * Frames and parses Teeworlds 0.6 "token-extended" connless packets. A request is the 2-byte
 * NET_HEADER_EXTENDED ("xe") + a 4-byte extra token + "\xff\xff\xff\xff" + a 4-char command;
 * the extended header is what makes a 0.6 server answer with extended (iext) info rather than
 * the 16-capped vanilla inf3. A response carries the same 6-byte framing, then an 8-byte
 * command at offset 6 and the payload at offset 14. Mirrors ddnet network.cpp SendPacketConnless.
 */
final class SixConnless
{
    private const HEADER_EXTENDED = 'xe';
    private const EXTRA_SIZE = 4;          // NET_CONNLESS_EXTRA_SIZE
    private const FRAMING_SIZE = 6;        // "xe" + 4-byte extra
    private const COMMAND_SIZE = 8;        // "\xff\xff\xff\xff" + 4 chars
    private const PAYLOAD_OFFSET = 14;     // FRAMING_SIZE + COMMAND_SIZE

    public const GETLIST = "\xff\xff\xff\xffreq2";
    public const GETINFO = "\xff\xff\xff\xffgie3";
    public const LIST = 'lis2';
    public const INFO = 'inf3';
    public const INFO_EXTENDED = 'iext';
    public const INFO_EXTENDED_MORE = 'iex+';

    /**
     * GETLIST request. $token is 2 caller-supplied random bytes that the master echoes back.
     */
    public static function getList(string $token): string
    {
        return self::frame($token, self::GETLIST);
    }

    /**
     * GETINFO request. $infoToken is the 1 byte a server echoes in its inf3/iext token field.
     */
    public static function getInfo(string $token, string $infoToken): string
    {
        return self::frame($token, self::GETINFO . $infoToken);
    }

    private static function frame(string $token, string $payload): string
    {
        // 4-byte extra = 2 token bytes + "\0\0", matching the legacy scraper and CNetBase extra
        return self::HEADER_EXTENDED . str_pad(substr($token, 0, 2), self::EXTRA_SIZE, "\x00") . $payload;
    }

    /**
     * @return array{command: string, payload: string}|null the 4-char command and the bytes
     *         after the 14-byte framing+command prefix, or null if this is not a 0.6 extended packet
     */
    public static function parse(string $datagram): ?array
    {
        if (strlen($datagram) < self::PAYLOAD_OFFSET) {
            return null;
        }

        if (!str_starts_with($datagram, self::HEADER_EXTENDED)) {
            return null;
        }

        return [
            'command' => substr($datagram, self::FRAMING_SIZE + 4, 4),
            'payload' => substr($datagram, self::PAYLOAD_OFFSET),
        ];
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixConnlessTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Protocol/Six/SixConnless.php tests/Unit/Protocol/Six/SixConnlessTest.php
git commit -m "feat(serverbrowser): add 0.6 connless framing codec

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: SixListCodec — parse the lis2 master payload

**Files:**
- Create: `app/TwStats/Protocol/Six/SixListCodec.php`
- Test: `tests/Unit/Protocol/Six/SixListCodecTest.php`

The 0.6 `lis2` entry format is identical to 0.7 (18 bytes: 16-byte IP, IPv4-mapped when prefixed with `::ffff:`, + 2-byte big-endian port). The only difference from `SevenListCodec` is the protocol tag (6, not 7). Kept as a separate codec to match the per-protocol namespace split; the slight duplication is acceptable.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixListCodec;
use PHPUnit\Framework\TestCase;

class SixListCodecTest extends TestCase
{
    public function test_parses_an_ipv4_mapped_entry_with_protocol_six(): void
    {
        // ::ffff:1.2.3.4 : 8303
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . "\x01\x02\x03\x04" . "\x20\x6f";

        $addresses = (new SixListCodec())->parse($entry);

        $this->assertCount(1, $addresses);
        $this->assertSame('1.2.3.4', $addresses[0]->ip);
        $this->assertSame(8303, $addresses[0]->port);
        $this->assertSame(6, $addresses[0]->protocol);
    }

    public function test_parses_multiple_entries_and_ignores_a_trailing_partial(): void
    {
        $a = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x0a\x00\x00\x01\x20\x6f";
        $b = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x0a\x00\x00\x02\x20\x70";

        $addresses = (new SixListCodec())->parse($a . $b . "\x00\x01\x02");

        $this->assertCount(2, $addresses);
        $this->assertSame('10.0.0.1', $addresses[0]->ip);
        $this->assertSame('10.0.0.2', $addresses[1]->ip);
        $this->assertSame(8304, $addresses[1]->port);
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixListCodecTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredAddress;

/**
 * Parses a Teeworlds 0.6 `lis2` master payload into game-server addresses. Each entry is a
 * 16-byte IP (IPv4-mapped when bytes 0-11 are the `::ffff:` prefix) + a 2-byte big-endian port.
 * Same wire format as 0.7's lis2; tagged protocol 6.
 */
final class SixListCodec
{
    private const ENTRY_SIZE = 18;
    private const IPV4_PREFIX = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff";

    /**
     * @return DiscoveredAddress[]
     */
    public function parse(string $payload): array
    {
        $addresses = [];

        for ($offset = 0; $offset + self::ENTRY_SIZE <= strlen($payload); $offset += self::ENTRY_SIZE) {
            $ipBytes = substr($payload, $offset, 16);
            $port = (ord($payload[$offset + 16]) << 8) | ord($payload[$offset + 17]);

            if (str_starts_with($ipBytes, self::IPV4_PREFIX)) {
                $ip = inet_ntop(substr($ipBytes, 12, 4));
            } else {
                $ip = inet_ntop($ipBytes);
            }

            if ($ip === false) {
                continue;
            }

            $addresses[] = new DiscoveredAddress($ip, $port, 6);
        }

        return $addresses;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixListCodecTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Protocol/Six/SixListCodec.php tests/Unit/Protocol/Six/SixListCodecTest.php
git commit -m "feat(serverbrowser): add 0.6 master list (lis2) codec

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: SixInfoCodec — parse inf3 / iext / iex+ payloads

**Files:**
- Create: `app/TwStats/Protocol/Six/SixInfoPacket.php`
- Create: `app/TwStats/Protocol/Six/SixInfoCodec.php`
- Test: `tests/Unit/Protocol/Six/SixInfoCodecTest.php`

The codec stays pure: it parses one info payload into a `SixInfoPacket` (an optional header + the clients carried by that single packet). `Teeworlds06Source` (Task 4) owns reassembly — seeding a server from the first packet that has a header (`inf3` or `iext`) and appending clients from `iex+` continuations, keyed by source address.

`SixInfoPacket` is an internal protocol artifact (not a domain value object), so it lives in `Protocol/Six`, not `Model`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;
use PHPUnit\Framework\TestCase;

class SixInfoCodecTest extends TestCase
{
    /** Build a NUL-delimited payload from already-stringified fields. */
    private function payload(array $fields): string
    {
        return implode("\x00", $fields) . "\x00";
    }

    public function test_parses_a_vanilla_inf3_header_and_clients(): void
    {
        $payload = $this->payload([
            '7777',                 // token (echoed; ignored)
            '0.6.4',                // version
            'My Server',            // name
            'dm1',                  // map
            'dm',                   // gametype
            '0',                    // flags
            '2',                    // numPlayers
            '16',                   // maxPlayers
            '2',                    // numClients
            '16',                   // maxClients
            'alice', 'TeeClan', '49', '7', '1',   // client 1 (player)
            'bob', '', '0', '0', '0',             // client 2 (spectator)
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO);

        $this->assertNotNull($packet);
        $this->assertTrue($packet->hasHeader);
        $this->assertSame('My Server', $packet->name);
        $this->assertSame('dm1', $packet->map);
        $this->assertSame('dm', $packet->gametype);
        $this->assertSame('0.6.4', $packet->version);
        $this->assertSame(16, $packet->maxClients);
        $this->assertSame(16, $packet->maxPlayers);
        $this->assertCount(2, $packet->clients);
        $this->assertSame('alice', $packet->clients[0]->name);
        $this->assertSame('TeeClan', $packet->clients[0]->clan);
        $this->assertSame(49, $packet->clients[0]->country);
        $this->assertSame(7, $packet->clients[0]->score);
        $this->assertTrue($packet->clients[0]->isPlayer);
        $this->assertFalse($packet->clients[1]->isPlayer);
        // 0.6 UDP carries no afk/skin
        $this->assertNull($packet->clients[0]->afk);
        $this->assertNull($packet->clients[0]->skin);
    }

    public function test_parses_an_extended_iext_header_with_reserved_fields(): void
    {
        $payload = $this->payload([
            '123',                  // token
            '0.6.4',                // version
            'Big Server',           // name
            'ctf5',                 // map
            '999',                  // mapCrc
            '512',                  // mapSize
            'ctf',                  // gametype
            '0',                    // flags
            '1',                    // numPlayers
            '64',                   // maxPlayers
            '1',                    // numClients
            '64',                   // maxClients
            '',                     // reserved
            'carol', 'X', '50', '3', '1', '',     // client + reserved
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO_EXTENDED);

        $this->assertNotNull($packet);
        $this->assertTrue($packet->hasHeader);
        $this->assertSame('Big Server', $packet->name);
        $this->assertSame('ctf5', $packet->map);
        $this->assertSame(64, $packet->maxClients);
        $this->assertCount(1, $packet->clients);
        $this->assertSame('carol', $packet->clients[0]->name);
        $this->assertTrue($packet->clients[0]->isPlayer);
    }

    public function test_parses_an_iex_plus_continuation_as_clients_only(): void
    {
        // continuation: token, chunkNumber, reserved, then clients (+reserved each)
        $payload = $this->payload([
            '123',                  // token
            '1',                    // chunk number
            '',                     // reserved
            'dave', 'Y', '0', '1', '1', '',
            'erin', '', '0', '2', '0', '',
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO_EXTENDED_MORE);

        $this->assertNotNull($packet);
        $this->assertFalse($packet->hasHeader);
        $this->assertCount(2, $packet->clients);
        $this->assertSame('dave', $packet->clients[0]->name);
        $this->assertTrue($packet->clients[0]->isPlayer);
        $this->assertFalse($packet->clients[1]->isPlayer);
    }

    public function test_returns_null_when_the_header_is_truncated(): void
    {
        $payload = $this->payload(['123', '0.6.4', 'name']); // missing most header fields

        $this->assertNull((new SixInfoCodec())->parse($payload, SixConnless::INFO));
    }

    public function test_returns_null_for_an_unknown_command(): void
    {
        $this->assertNull((new SixInfoCodec())->parse('whatever', 'zzzz'));
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixInfoCodecTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement SixInfoPacket**

```php
<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredClient;

/**
 * One parsed 0.6 info datagram. A header-bearing packet (inf3 / iext first packet) carries the
 * server fields; an iex+ continuation carries only more clients (hasHeader === false). The source
 * reassembles continuations onto the header packet by source address.
 */
final class SixInfoPacket
{
    /**
     * @param DiscoveredClient[] $clients
     */
    public function __construct(
        public readonly bool $hasHeader,
        public readonly ?string $version,
        public readonly ?string $name,
        public readonly ?string $map,
        public readonly ?string $gametype,
        public readonly ?int $maxPlayers,
        public readonly ?int $maxClients,
        public readonly array $clients,
    ) {
    }
}
```

- [ ] **Step 4: Implement SixInfoCodec**

```php
<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredClient;

/**
 * Parses a Teeworlds 0.6 info payload (the NUL-delimited bytes after the 14-byte framing+command)
 * into a SixInfoPacket. 0.6 packs every field — numbers included — as a NUL-terminated ASCII
 * string (ddnet server.cpp ADD_INT = str_format("%d") + AddString). The leading field is the
 * echoed info token and is dropped. Three layouts:
 *   inf3  vanilla:   version, name, map, gametype, flags, nums..., then (name, clan, country, score, isPlayer)*
 *   iext  extended:  inserts mapCrc/mapSize after map and a trailing reserved field per client
 *   iex+  continuation: chunkNumber, reserved, then the same per-client tuples (no server header)
 * 0.6 UDP carries no skin/afk, so those cosmetic fields are left null.
 */
final class SixInfoCodec
{
    public function parse(string $payload, string $command): ?SixInfoPacket
    {
        $fields = explode("\x00", $payload);
        // every field is NUL-terminated, so a well-formed payload ends with an empty trailing element
        if ($fields !== [] && end($fields) === '') {
            array_pop($fields);
        }

        array_shift($fields); // drop the echoed token

        return match ($command) {
            SixConnless::INFO => $this->parseHeaderPacket($fields, extended: false),
            SixConnless::INFO_EXTENDED => $this->parseHeaderPacket($fields, extended: true),
            SixConnless::INFO_EXTENDED_MORE => $this->parseContinuation($fields),
            default => null,
        };
    }

    private function parseHeaderPacket(array $fields, bool $extended): ?SixInfoPacket
    {
        // version, name, map, [mapCrc, mapSize], gametype, flags, numPlayers, maxPlayers, numClients, maxClients
        $headerCount = $extended ? 12 : 10;
        if (count($fields) < $headerCount) {
            return null;
        }

        $version = array_shift($fields);
        $name = array_shift($fields);
        $map = array_shift($fields);
        if ($extended) {
            array_shift($fields); // mapCrc
            array_shift($fields); // mapSize
        }
        $gametype = array_shift($fields);
        array_shift($fields);               // flags
        array_shift($fields);               // numPlayers
        $maxPlayers = (int) array_shift($fields);
        array_shift($fields);               // numClients
        $maxClients = (int) array_shift($fields);

        if ($extended) {
            array_shift($fields); // reserved
        }

        return new SixInfoPacket(
            hasHeader: true,
            version: $version,
            name: $name,
            map: $map,
            gametype: $gametype,
            maxPlayers: $maxPlayers,
            maxClients: $maxClients,
            clients: $this->parseClients($fields, $extended),
        );
    }

    private function parseContinuation(array $fields): ?SixInfoPacket
    {
        if (count($fields) < 2) {
            return null;
        }

        array_shift($fields); // chunk number
        array_shift($fields); // reserved

        return new SixInfoPacket(
            hasHeader: false,
            version: null,
            name: null,
            map: null,
            gametype: null,
            maxPlayers: null,
            maxClients: null,
            clients: $this->parseClients($fields, extended: true),
        );
    }

    /**
     * @return DiscoveredClient[]
     */
    private function parseClients(array $fields, bool $extended): array
    {
        $tuple = $extended ? 6 : 5; // extended carries a trailing reserved field per client
        $clients = [];

        while (count($fields) >= $tuple) {
            $name = array_shift($fields);
            $clan = array_shift($fields);
            $country = (int) array_shift($fields);
            $score = (int) array_shift($fields);
            $isPlayer = ((int) array_shift($fields)) === 1;
            if ($extended) {
                array_shift($fields); // reserved
            }

            $clients[] = new DiscoveredClient(
                name: $name,
                clan: $clan,
                country: $country,
                score: $score,
                isPlayer: $isPlayer,
                afk: null,
                skin: null,
                colorBody: null,
                colorFeet: null,
                skinParts: null,
            );
        }

        return $clients;
    }
}
```

- [ ] **Step 5: Run the test, verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Protocol/Six/SixInfoCodecTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/TwStats/Protocol/Six/SixInfoPacket.php app/TwStats/Protocol/Six/SixInfoCodec.php tests/Unit/Protocol/Six/SixInfoCodecTest.php
git commit -m "feat(serverbrowser): add 0.6 info (inf3/iext/iex+) codec

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Teeworlds06Source — orchestrate discovery over UdpTransport

**Files:**
- Create: `app/TwStats/Discovery/Teeworlds06Source.php`
- Test: `tests/Unit/Discovery/Teeworlds06SourceTest.php`

Two drain phases (0.6 needs no token handshake, unlike 0.7): (1) send `req2` to every master, drain `lis2` into an address set; (2) chunk the addresses, send `gie3`+infoToken to each, drain `inf3`/`iext`/`iex+`, accumulate by source address into one `DiscoveredServer` per server. Mirror `Teeworlds07Source`'s drain loop, `MAX_DRAIN_MS` cap, and `defaultMasters()` (but port **8300**).

- [ ] **Step 1: Write the failing test** (uses `FakeUdpTransport`; inject masters so no DNS is needed)

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\Teeworlds06Source;
use App\TwStats\Protocol\Six\SixConnless;
use Tests\Support\FakeUdpTransport;
use PHPUnit\Framework\TestCase;

class Teeworlds06SourceTest extends TestCase
{
    private function lis2(array $ipPorts): string
    {
        $payload = '';
        foreach ($ipPorts as [$ip, $port]) {
            $payload .= "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($ip) . chr($port >> 8) . chr($port & 0xff);
        }

        // wrap as a 0.6 lis2 response: "xe" + extra + command + payload
        return "xe\x00\x00\x00\x00\xff\xff\xff\xfflis2" . $payload;
    }

    private function inf3(array $fields): string
    {
        return "xe\x00\x00\x00\x00\xff\xff\xff\xffinf3" . implode("\x00", $fields) . "\x00";
    }

    public function test_discovers_servers_from_the_master_list_then_queries_them(): void
    {
        $transport = new FakeUdpTransport();

        // phase 1: master returns two servers
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.7', 8303], ['198.51.100.8', 8303]]));
        $transport->queueGap();

        // phase 2: each server answers inf3
        $transport->queue('198.51.100.7', 8303, $this->inf3([
            '1', '0.6.4', 'Alpha', 'dm1', 'dm', '0', '1', '16', '1', '16',
            'alice', '', '0', '5', '1',
        ]));
        $transport->queue('198.51.100.8', 8303, $this->inf3([
            '1', '0.6.4', 'Beta', 'ctf1', 'ctf', '0', '0', '16', '0', '16',
        ]));
        $transport->queueGap();

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $servers = $source->fetch();

        $this->assertCount(2, $servers);

        $names = array_map(fn ($s) => $s->name, $servers);
        sort($names);
        $this->assertSame(['Alpha', 'Beta'], $names);

        $alpha = array_values(array_filter($servers, fn ($s) => $s->name === 'Alpha'))[0];
        $this->assertSame('198.51.100.7', $alpha->addresses[0]->ip);
        $this->assertSame(6, $alpha->addresses[0]->protocol);
        $this->assertSame('vanilla_06', $alpha->flavor);
        $this->assertCount(1, $alpha->clients);
        $this->assertSame('alice', $alpha->clients[0]->name);

        // a GETLIST went to the master and a GETINFO went to each server
        $this->assertSame(SixConnless::getList(substr($transport->sent[0]['data'], 2, 2)), $transport->sent[0]['data']);
        $sentTo = array_map(fn ($s) => $s['ip'], $transport->sent);
        $this->assertContains('198.51.100.7', $sentTo);
        $this->assertContains('198.51.100.8', $sentTo);
    }

    public function test_reassembles_extended_iext_plus_iex_plus_by_source_address(): void
    {
        $transport = new FakeUdpTransport();
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.9', 8303]]));
        $transport->queueGap();

        $iext = "xe\x00\x00\x00\x00\xff\xff\xff\xffiext" . implode("\x00", [
            '1', '0.6.4', 'Huge', 'map', '0', '0', 'mod', '0', '2', '64', '2', '64', '',
            'p1', '', '0', '0', '1', '',
        ]) . "\x00";
        $iexPlus = "xe\x00\x00\x00\x00\xff\xff\xff\xffiex+" . implode("\x00", [
            '1', '1', '',
            'p2', '', '0', '0', '1', '',
        ]) . "\x00";

        $transport->queue('198.51.100.9', 8303, $iext);
        $transport->queue('198.51.100.9', 8303, $iexPlus);
        $transport->queueGap();

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $servers = $source->fetch();

        $this->assertCount(1, $servers);
        $this->assertSame('Huge', $servers[0]->name);
        $this->assertSame(64, $servers[0]->maxClients);
        $names = array_map(fn ($c) => $c->name, $servers[0]->clients);
        $this->assertSame(['p1', 'p2'], $names);
    }

    public function test_a_listed_server_that_never_answers_yields_no_server(): void
    {
        $transport = new FakeUdpTransport();
        $transport->queue('203.0.113.1', 8300, $this->lis2([['198.51.100.7', 8303]]));
        $transport->queueGap();
        $transport->queueGap(); // phase 2: silence

        $source = new Teeworlds06Source($transport, [['ip' => '203.0.113.1', 'port' => 8300]]);
        $this->assertSame([], $source->fetch());
    }
}
```

- [ ] **Step 2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Discovery/Teeworlds06SourceTest.php`
Expected: FAIL (class not found).

- [ ] **Step 3: Implement**

```php
<?php

namespace App\TwStats\Discovery;

use App\TwStats\Model\DiscoveredAddress;
use App\TwStats\Model\DiscoveredServer;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;
use App\TwStats\Protocol\Six\SixInfoPacket;
use App\TwStats\Protocol\Six\SixListCodec;

/**
 * Discovers stock Teeworlds 0.6 servers by querying the teeworlds.com:8300 master directly — the
 * resilience fallback for the 0.6 population if DDNet's HTTP master (its normal, richer source)
 * ever disappears. 0.6 is stateless: no token handshake, just two drain phases — master list
 * (req2 -> lis2), then per-server info (gie3 -> inf3/iext/iex+) reassembled by source address.
 */
final class Teeworlds06Source
{
    private const MASTER_PORT = 8300;
    private const LIST_TOKEN = "\x12\x34";   // echoed by the master; any 2 bytes work
    private const INFO_TOKEN = "\x01";        // echoed by servers in the info token field
    private const DRAIN_TIMEOUT_MS = 700;
    private const MAX_DRAIN_MS = 8000;        // hard cap per drain so a flooding peer can't hang the scrape
    private const INFO_CHUNK = 256;

    /** @var array<int, array{ip: string, port: int}> */
    private array $masters;

    /**
     * @param array<int, array{ip: string, port: int}>|null $masters
     */
    public function __construct(
        private readonly UdpTransport $transport = new SocketUdpTransport(),
        ?array $masters = null,
    ) {
        $this->masters = $masters ?? self::defaultMasters();
    }

    /**
     * @return DiscoveredServer[]
     */
    public function fetch(): array
    {
        $addresses = $this->fetchServerList();

        return $this->queryServers($addresses);
    }

    /**
     * @param callable(array{ip: string, port: int, data: string}): void $onPacket
     */
    private function drain(callable $onPacket): void
    {
        $deadline = hrtime(true) + self::MAX_DRAIN_MS * 1_000_000;
        while (hrtime(true) < $deadline) {
            $packet = $this->transport->receive(self::DRAIN_TIMEOUT_MS);
            if ($packet === null) {
                return;
            }
            $onPacket($packet);
        }
    }

    /**
     * @return array<int, array{ip: string, port: int}>
     */
    private function fetchServerList(): array
    {
        foreach ($this->masters as $master) {
            $this->transport->send($master['ip'], $master['port'], SixConnless::getList(self::LIST_TOKEN));
        }

        $listCodec = new SixListCodec();
        $addresses = [];
        $this->drain(function (array $packet) use (&$addresses, $listCodec) {
            $parsed = SixConnless::parse($packet['data']);
            if ($parsed === null || $parsed['command'] !== SixConnless::LIST) {
                return;
            }
            foreach ($listCodec->parse($parsed['payload']) as $address) {
                $addresses[$address->ip . ':' . $address->port] = ['ip' => $address->ip, 'port' => $address->port];
            }
        });

        return array_values($addresses);
    }

    /**
     * @param array<int, array{ip: string, port: int}> $addresses
     * @return DiscoveredServer[]
     */
    private function queryServers(array $addresses): array
    {
        $infoCodec = new SixInfoCodec();
        $servers = [];

        foreach (array_chunk($addresses, self::INFO_CHUNK) as $chunk) {
            foreach ($chunk as $address) {
                $this->transport->send($address['ip'], $address['port'], SixConnless::getInfo(self::INFO_TOKEN, self::INFO_TOKEN));
            }

            // accumulate per source: the first header packet seeds the server, iex+ appends clients
            $pending = [];
            $this->drain(function (array $packet) use (&$pending, $infoCodec) {
                $parsed = SixConnless::parse($packet['data']);
                if ($parsed === null) {
                    return;
                }
                $info = $infoCodec->parse($parsed['payload'], $parsed['command']);
                if ($info === null) {
                    return;
                }
                $key = $packet['ip'] . ':' . $packet['port'];
                $pending[$key] ??= ['ip' => $packet['ip'], 'port' => $packet['port'], 'header' => null, 'clients' => []];
                if ($info->hasHeader && $pending[$key]['header'] === null) {
                    $pending[$key]['header'] = $info;
                }
                array_push($pending[$key]['clients'], ...$info->clients);
            });

            foreach ($pending as $entry) {
                /** @var SixInfoPacket|null $header */
                $header = $entry['header'];
                if ($header === null) {
                    continue; // continuations only, no header packet seen — cannot form a server
                }

                $servers[] = new DiscoveredServer(
                    addresses: [new DiscoveredAddress($entry['ip'], $entry['port'], 6)],
                    name: $header->name,
                    map: $header->map,
                    gametype: $header->gametype,
                    version: $header->version,
                    maxClients: $header->maxClients,
                    maxPlayers: $header->maxPlayers,
                    clients: $entry['clients'],
                    location: null,
                    flavor: FlavorClassifier::classify($header->version),
                );
            }
        }

        return $servers;
    }

    /**
     * @return array<int, array{ip: string, port: int}>
     */
    private static function defaultMasters(): array
    {
        $masters = [];
        foreach (['master1.teeworlds.com', 'master2.teeworlds.com', 'master3.teeworlds.com', 'master4.teeworlds.com'] as $host) {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $masters[] = ['ip' => $ip, 'port' => self::MASTER_PORT];
            }
        }

        return $masters;
    }
}
```

- [ ] **Step 4: Run the test, verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Discovery/Teeworlds06SourceTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Discovery/Teeworlds06Source.php tests/Unit/Discovery/Teeworlds06SourceTest.php
git commit -m "feat(serverbrowser): add native Teeworlds 0.6 discovery source

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Wire the 0.6 source into UpdateData

**Files:**
- Modify: `app/Console/Commands/UpdateData.php` (constructor + `handle()`)
- Test: `tests/Feature/UpdateDataTest.php` (extend if it exercises source merging; otherwise add a focused assertion)

The 0.6 source is the **lowest-priority** source: append it last so DDNet (richest) and then 0.7 win the ip:port merge. The existing cosmetic-clobber guard (`if ($client->afk !== null)`) already prevents the 0.6 source's null cosmetics from wiping a DDNet skin snapshot, because 0.6 `DiscoveredClient::afk` is null.

**Important — test isolation:** `UpdateDataTest` resolves `UpdateData` through the container, which **autowires** any typed source not explicitly bound. Today every test binds `Teeworlds07Source` to an empty fake (`bindEmptySevenSource()`) precisely so it makes no live UDP call. Adding a `Teeworlds06Source` constructor arg means an unbound test would autowire a **real** one that hits `teeworlds.com:8300` and blocks up to 8 s. So this task **must** bind an empty 0.6 source in every test that runs `data:update`, exactly mirroring the 0.7 pattern.

- [ ] **Step 1: Add the import and constructor injection**

In `app/Console/Commands/UpdateData.php`, add to the imports:

```php
use App\TwStats\Discovery\Teeworlds06Source;
```

Add the constructor parameter (after `$teeworldsSevenSource`):

```php
        private readonly Teeworlds07Source $teeworldsSevenSource = new Teeworlds07Source(),
        private readonly Teeworlds06Source $teeworldsSixSource = new Teeworlds06Source(),
        private readonly ServerMerger $serverMerger = new ServerMerger(),
```

- [ ] **Step 2: Append the 0.6 source to the merge in `handle()`**

Replace the discovery line and its comment:

```php
        // DDNet first: its servers.json carries real limits, players and cosmetics, so it wins the
        // merge over a UDP sighting of the same server. The 0.7 and native 0.6 sources then add the
        // stock Teeworlds servers that register only to teeworlds.com's master — the 0.6 source is
        // the fallback that keeps the 0.6 population reachable if DDNet's HTTP master ever goes down.
        $discovered = array_merge(
            $this->ddnetHttpSource->fetch(),
            $this->teeworldsSevenSource->fetch(),
            $this->teeworldsSixSource->fetch(),
        );
        $servers = $this->serverMerger->merge($discovered);
```

- [ ] **Step 3: Add a `bindEmptySixSource()` helper and call it everywhere `data:update` runs**

In `tests/Feature/UpdateDataTest.php`, add the import:

```php
use App\TwStats\Discovery\Teeworlds06Source;
```

Add the helper next to `bindEmptySevenSource()`:

```php
    private function bindEmptySixSource(): void
    {
        // no masters + empty transport → the 0.6 source contributes nothing (no live UDP in tests)
        $this->app->instance(Teeworlds06Source::class, new Teeworlds06Source(new FakeUdpTransport(), masters: []));
    }
```

Then call `$this->bindEmptySixSource();` in **every** existing test method that calls `$this->artisan('data:update')` — there are five: `test_it_ingests_the_ddnet_master_into_logical_servers_and_addresses`, `test_it_persists_players_with_their_cosmetic_snapshot`, `test_it_records_server_and_player_histories_and_opens_sessions`, `test_it_ingests_a_vanilla_07_server_from_the_seven_source`, and `test_a_seven_source_observation_does_not_wipe_a_ddnet_cosmetic_snapshot`. Place the call alongside the existing `bindEmptySevenSource()`/`bindSevenSourceWithPlayer()` call in each.

- [ ] **Step 4: Run the full suite, verify green**

Run: `vendor/bin/phpunit`
Expected: all green, and fast — no test should hang (proof the live 0.6 source is never reached under test). If any test slows to multiple seconds, a `bindEmptySixSource()` call is missing.

- [ ] **Step 5: Add a positive test that the 0.6 source is ingested with protocol 6**

Add to `tests/Feature/UpdateDataTest.php`, mirroring `bindSevenSourceWithPlayer` but for 0.6 (no token handshake; reuse the `lis2`/`inf3` framing from `Teeworlds06SourceTest`):

```php
    private function bindSixSourceWithServer(string $serverName, string $playerName): void
    {
        $masterIp = '10.9.0.2';
        $serverIp = '198.51.100.6';
        $transport = new FakeUdpTransport();

        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . inet_pton($serverIp) . "\x20\x6f"; // :8303
        $list = "xe\x00\x00\x00\x00\xff\xff\xff\xfflis2" . $entry;
        $info = "xe\x00\x00\x00\x00\xff\xff\xff\xffinf3" . implode("\x00", [
            '1', '0.6.4', $serverName, 'dm1', 'dm', '0', '1', '16', '1', '16',
            $playerName, '', '0', '3', '1',
        ]) . "\x00";

        $transport->queue($masterIp, 8300, $list); $transport->queueGap();
        $transport->queue($serverIp, 8303, $info); $transport->queueGap();

        $this->app->instance(Teeworlds06Source::class, new Teeworlds06Source($transport, masters: [['ip' => $masterIp, 'port' => 8300]]));
    }

    public function test_it_ingests_a_vanilla_06_server_from_the_six_source(): void
    {
        Http::fake(['master1.ddnet.org/*' => Http::response('{"servers":[]}', 200)]);
        $this->bindEmptySevenSource();
        $this->bindSixSourceWithServer('Vanilla 0.6 DM', 'Sixplayer');

        $this->artisan('data:update')->assertSuccessful();

        $this->assertDatabaseHas('servers', ['name' => 'Vanilla 0.6 DM', 'flavor' => 'vanilla_06']);
        $this->assertDatabaseHas('players', ['name' => 'Sixplayer']);
        $this->assertDatabaseHas('server_addresses', ['ip' => '198.51.100.6', 'port' => 8303, 'protocol' => 6]);
    }
```

- [ ] **Step 6: Run the full suite, verify green**

Run: `vendor/bin/phpunit`
Expected: all green, including the new 0.6 ingestion test.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/UpdateData.php tests/Feature/UpdateDataTest.php
git commit -m "feat(serverbrowser): scrape native Teeworlds 0.6 as the DDNet fallback

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Live smoke test + docs/memory update

**Files:**
- Modify: `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (note the 0.6 source is now built)
- Update memory: `project_twstats_multi_ecosystem_ingestion.md`

- [ ] **Step 1: Live smoke test** (manual; the container can reach the masters)

Run: `php artisan migrate:fresh --force` then `timeout 180 php artisan data:update`, then:

```bash
php artisan tinker --execute="echo App\Models\ServerAddress::where('protocol',6)->count().' proto-6 addresses; '.App\Models\Server::where('flavor','vanilla_06')->count().' vanilla_06 servers'.PHP_EOL;"
```
Expected: a non-trivial proto-6 address count and a plausible `vanilla_06` server count, with no errors. (Confirms the source runs live against `teeworlds.com:8300`.) Because DDNet wins the merge for shared servers, most proto-6 addresses will sit on servers whose canonical row is DDNet-flavored — the 0.6 source's value shows as additional `server_addresses` rows + any servers unique to the 0.6 master.

- [ ] **Step 2: Note completion in the design doc**

In the design spec, update the "native 0.6 source" line to record that it is now built as the DDNet fallback (was deferred in Phase 5).

- [ ] **Step 3: Update the project memory** with: Phase 6 built the native 0.6 source (`Protocol\Six` + `Teeworlds06Source`), wired last in the merge as the DDNet-outage fallback; 64-legacy `fstd`/`dtsf` deliberately omitted.

- [ ] **Step 4: Commit**

```bash
git add docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md
git commit -m "docs(serverbrowser): record the native 0.6 source as the DDNet fallback

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Native 0.6 discovery against the live `teeworlds.com:8300` master → Tasks 1-4. ✓
- No 16-cap: extended `iext`/`iex+` requested via the `"xe"` framing and reassembled → Task 3 + Task 4. ✓
- Lowest-priority merge so DDNet stays primary; no cosmetic clobber → Task 5 (relies on existing ip:port merge + `afk !== null` guard). ✓
- Resilience intent (survive a DDNet outage) → documented in Task 4 class doc + Task 6 memory. ✓

**Placeholder scan:** none. Every code step is complete.

**Type consistency:** `SixConnless::getList(string): string`, `getInfo(string,string): string`, `parse(string): ?array{command,payload}`; `SixListCodec::parse(string): DiscoveredAddress[]` (protocol 6); `SixInfoCodec::parse(string,string): ?SixInfoPacket`; `SixInfoPacket` readonly fields used consistently in `Teeworlds06Source::queryServers`. `DiscoveredServer`/`DiscoveredClient` constructor argument names match `app/TwStats/Model/*`. `UdpTransport`/`FakeUdpTransport` signatures match the 0.7 usage.

**Scope:** single subsystem (the 0.6 source), additive — no existing class is restructured; the only edit to live code is appending one source to `UpdateData`.
