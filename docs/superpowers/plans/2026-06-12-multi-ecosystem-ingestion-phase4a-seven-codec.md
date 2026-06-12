# Multi-ecosystem Ingestion — Phase 4a: Teeworlds 0.7 Connless Codec — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Implement the pure, byte-level Teeworlds 0.7 connless protocol codec — the Teeworlds variable-int encoding, a payload unpacker, the connless-packet + token-control framing, and the 0.7 server-info and master-list parsers — all fixture-tested with no network I/O.

**Architecture:** A new `App\TwStats\Protocol\Seven` namespace. `VariableInt` packs/unpacks the Teeworlds `ESDDDDDD` integer format. `Unpacker` walks a byte string yielding ints (variable-int) and NUL-terminated strings. `SevenConnless` builds the 9-byte connless header (`0x21` + token + response-token) and the 12-byte token-request control packet, and parses their responses. `SevenInfoCodec` parses an `inf3` 0.7 payload into a `DiscoveredServer` (reusing the Phase 2 value objects + `FlavorClassifier`); `SevenListCodec` parses a `lis2` payload into game-server addresses. Phase 4b (the `Teeworlds07Source` UDP I/O) consumes this codec; this phase has zero sockets.

**Tech Stack:** PHP 8.5 (readonly value objects, named args), pure unit tests in `tests/Unit/Protocol/Seven` (`PHPUnit\Framework\TestCase`, no DB). Run `vendor/bin/phpunit` in the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§4.2, §5.2, §5.3). Reference implementation: `/var/www/html/teeworlds/src/engine/shared/{compression.cpp,network.cpp,packer.cpp}` and `server.cpp` `GenerateServerInfo`. Reuses Phase 2 `DiscoveredServer`/`DiscoveredClient`/`DiscoveredAddress` + `FlavorClassifier`.

**Protocol facts (verified against the reference):**
- **Variable-int** (`compression.cpp` `CVariableInt`): first byte `E S DDDDDD` (bit7 extend, bit6 sign, bits0-5 data); continuation bytes `E DDDDDDD` (bit7 extend, bits0-6 data). Decode masks `[0x7F,0x7F,0x7F,0x0F]`, shifts `[6,13,20,27]`; if sign bit set, result = `~magnitude`.
- **Connless packet** (`network.cpp:146-165`, 9-byte header): `byte0 = (8<<2)|1 = 0x21`, `bytes1-4 = token (BE)`, `bytes5-8 = response_token (BE)`, then data. Recv reads `flags=(b0&0xfc)>>2`, `token=b1..4`, `response_token=b5..8`, data from byte 9.
- **Token request** (control, `network.cpp:354-381`, 7-byte header): `byte0=(1<<2)=0x04`, `byte1=0` (ack), `byte2=0` (numchunks), `bytes3-6=NET_TOKEN_NONE=0xFFFFFFFF`, then payload `[0x05 (NET_CTRLMSG_TOKEN)] + [my_token BE]`.
- **Token response** (control): payload `[0x05] + [server_token BE]`; read `server_token` from payload bytes 1-4.
- **`inf3` 0.7 info payload** (`server.cpp` `GenerateServerInfo`), after the 8-byte `SERVERBROWSE_INFO` token + a variable-int browse-token echo: strings (NUL-terminated) `version, name, hostname, map, gametype`; then variable-ints `flags, skill_level, num_players, max_players, num_clients, max_clients`; then per client `name(str), clan(str), country(int), score(int), player_flag(int)` where `player_flag` 0=player / 1=spectator / 2=bot.
- **`lis2` master payload**, after the 8-byte `SERVERBROWSE_LIST` token: N × 18 bytes = 16-byte IP + 2-byte port (BE); an IPv4-mapped address has the `00…00 ff ff` prefix in bytes 0-11.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/TwStats/Protocol/Seven/VariableInt.php` | `unpack(bytes, offset): [int, nextOffset]` and `pack(int): string` for the Teeworlds varint. |
| `app/TwStats/Protocol/Seven/Unpacker.php` | Cursor over a byte string: `getInt()`, `getString()`, `getRaw(n)`, `error()`. |
| `app/TwStats/Protocol/Seven/SevenConnless.php` | Build connless query + token request; parse connless response (strip header) + token response. |
| `app/TwStats/Protocol/Seven/SevenInfoCodec.php` | Parse an `inf3` 0.7 payload + a known address → `DiscoveredServer`. |
| `app/TwStats/Protocol/Seven/SevenListCodec.php` | Parse a `lis2` payload → `DiscoveredAddress[]` (protocol 7). |
| `tests/Unit/Protocol/Seven/*Test.php` | Unit tests for each, with constructed byte fixtures. |

---

## Task 1: `VariableInt` + `Unpacker`

**Files:**
- Create: `tests/Unit/Protocol/Seven/VariableIntTest.php`, `tests/Unit/Protocol/Seven/UnpackerTest.php`
- Create: `app/TwStats/Protocol/Seven/VariableInt.php`, `app/TwStats/Protocol/Seven/Unpacker.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Protocol/Seven/VariableIntTest.php`:

```php
<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class VariableIntTest extends TestCase
{
    public static function vectors(): array
    {
        // value => packed bytes (verified against CVariableInt::Pack)
        return [
            'zero'       => [0, "\x00"],
            'one'        => [1, "\x01"],
            'minus_one'  => [-1, "\x40"],
            'max_6bit'   => [63, "\x3f"],
            'minus_64'   => [-64, "\x7f"],
            'needs_ext'  => [64, "\x80\x01"],
            'big'        => [8191, "\xbf\x7f"],
        ];
    }

    /** @dataProvider vectors */
    public function test_pack_matches_reference(int $value, string $bytes): void
    {
        $this->assertSame(bin2hex($bytes), bin2hex(VariableInt::pack($value)));
    }

    /** @dataProvider vectors */
    public function test_unpack_round_trips(int $value, string $bytes): void
    {
        [$decoded, $offset] = VariableInt::unpack($bytes, 0);
        $this->assertSame($value, $decoded);
        $this->assertSame(strlen($bytes), $offset);
    }

    public function test_unpack_advances_offset_across_a_sequence(): void
    {
        $buffer = VariableInt::pack(64) . VariableInt::pack(-1) . VariableInt::pack(5);
        $offset = 0;
        [$a, $offset] = VariableInt::unpack($buffer, $offset);
        [$b, $offset] = VariableInt::unpack($buffer, $offset);
        [$c, $offset] = VariableInt::unpack($buffer, $offset);
        $this->assertSame([64, -1, 5], [$a, $b, $c]);
        $this->assertSame(strlen($buffer), $offset);
    }
}
```

Create `tests/Unit/Protocol/Seven/UnpackerTest.php`:

```php
<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\Unpacker;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class UnpackerTest extends TestCase
{
    public function test_reads_ints_strings_and_raw_in_order(): void
    {
        $buffer = VariableInt::pack(7) . "hello\x00" . VariableInt::pack(-2) . "rawbytes";
        $unpacker = new Unpacker($buffer);

        $this->assertSame(7, $unpacker->getInt());
        $this->assertSame('hello', $unpacker->getString());
        $this->assertSame(-2, $unpacker->getInt());
        $this->assertSame('rawbytes', $unpacker->getRaw(8));
        $this->assertFalse($unpacker->error());
    }

    public function test_reading_past_the_end_sets_the_error_flag(): void
    {
        $unpacker = new Unpacker("\x05"); // one int, then nothing
        $this->assertSame(5, $unpacker->getInt());
        $unpacker->getString(); // no NUL terminator left
        $this->assertTrue($unpacker->error());
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "VariableIntTest|UnpackerTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `VariableInt`**

Create `app/TwStats/Protocol/Seven/VariableInt.php`:

```php
<?php

namespace App\TwStats\Protocol\Seven;

/**
 * The Teeworlds variable-length integer used by the 0.7 packer. First byte holds an extend
 * bit (0x80), a sign bit (0x40) and 6 data bits; continuation bytes hold an extend bit and
 * 7 data bits. A set sign bit means the value is the bitwise complement of the magnitude.
 * Mirrors CVariableInt in the reference (compression.cpp).
 */
final class VariableInt
{
    public static function pack(int $value): string
    {
        $first = 0;
        if ($value < 0) {
            $first |= 0x40; // sign
            $value = ~$value;
        }

        $first |= $value & 0x3F;
        $value >>= 6;

        $bytes = '';
        while ($value !== 0) {
            $first |= 0x80; // extend
            $bytes .= chr($first);
            $first = $value & 0x7F;
            $value >>= 7;
        }
        $bytes .= chr($first);

        return $bytes;
    }

    /**
     * @return array{0: int, 1: int} the decoded value and the offset just past it
     */
    public static function unpack(string $buffer, int $offset): array
    {
        $byte = ord($buffer[$offset]);
        $sign = ($byte >> 6) & 1;
        $value = $byte & 0x3F;

        $masks = [0x7F, 0x7F, 0x7F, 0x0F];
        $shifts = [6, 13, 20, 27];

        for ($i = 0; $i < 4; $i++) {
            if (!($byte & 0x80)) {
                break;
            }
            $offset++;
            $byte = ord($buffer[$offset]);
            $value |= ($byte & $masks[$i]) << $shifts[$i];
        }

        $offset++;
        if ($sign) {
            $value = ~$value;
        }

        // keep PHP's wide int in 32-bit two's-complement range, matching the C int
        $value &= 0xFFFFFFFF;
        if ($value & 0x80000000) {
            $value -= 0x100000000;
        }

        return [$value, $offset];
    }
}
```

- [ ] **Step 4: Create `Unpacker`**

Create `app/TwStats/Protocol/Seven/Unpacker.php`:

```php
<?php

namespace App\TwStats\Protocol\Seven;

/**
 * Reads a 0.7 message payload field by field. Like the reference CUnpacker, any read past the
 * end of the buffer raises a sticky error flag rather than throwing, so a truncated/garbage
 * packet is dropped by the caller instead of aborting the scrape.
 */
final class Unpacker
{
    private int $offset = 0;
    private bool $error = false;

    public function __construct(private readonly string $buffer)
    {
    }

    public function getInt(): int
    {
        if ($this->error || $this->offset >= strlen($this->buffer)) {
            $this->error = true;
            return 0;
        }

        try {
            [$value, $this->offset] = VariableInt::unpack($this->buffer, $this->offset);
        } catch (\Throwable) {
            $this->error = true;
            return 0;
        }

        if ($this->offset > strlen($this->buffer)) {
            $this->error = true;
        }

        return $value;
    }

    public function getString(): string
    {
        if ($this->error) {
            return '';
        }

        $end = strpos($this->buffer, "\x00", $this->offset);
        if ($end === false) {
            $this->error = true;
            return '';
        }

        $string = substr($this->buffer, $this->offset, $end - $this->offset);
        $this->offset = $end + 1;

        return $string;
    }

    public function getRaw(int $length): string
    {
        if ($this->error || $this->offset + $length > strlen($this->buffer)) {
            $this->error = true;
            return '';
        }

        $raw = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $raw;
    }

    public function error(): bool
    {
        return $this->error;
    }

    public function remaining(): int
    {
        return max(0, strlen($this->buffer) - $this->offset);
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "VariableIntTest|UnpackerTest"`
Expected: PASS (all green). Then run the full suite `vendor/bin/phpunit`.

- [ ] **Step 6: Commit**

```bash
git add app/TwStats/Protocol/Seven/VariableInt.php app/TwStats/Protocol/Seven/Unpacker.php \
        tests/Unit/Protocol/Seven/VariableIntTest.php tests/Unit/Protocol/Seven/UnpackerTest.php
git commit -m "feat(serverbrowser): add Teeworlds 0.7 variable-int + payload unpacker

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `SevenConnless` (packet + token framing)

**Files:**
- Create: `tests/Unit/Protocol/Seven/SevenConnlessTest.php`
- Create: `app/TwStats/Protocol/Seven/SevenConnless.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Protocol/Seven/SevenConnlessTest.php`:

```php
<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\SevenConnless;
use PHPUnit\Framework\TestCase;

class SevenConnlessTest extends TestCase
{
    public function test_builds_a_token_request_with_the_none_token_and_client_token(): void
    {
        $request = SevenConnless::tokenRequest(0x11223344);

        // 7-byte control header + control byte + 4-byte client token
        $this->assertSame('0400'.'00'.'ffffffff'.'05'.'11223344', bin2hex($request));
    }

    public function test_parses_a_token_response_payload(): void
    {
        // a control packet: header (flags=CONTROL), then [NET_CTRLMSG_TOKEN=5][server token]
        $packet = "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . "\xaa\xbb\xcc\xdd";

        $this->assertSame(0xAABBCCDD, SevenConnless::parseTokenResponse($packet));
    }

    public function test_returns_null_when_a_packet_is_not_a_token_response(): void
    {
        $this->assertNull(SevenConnless::parseTokenResponse("\x04\x00\x00\x00\x00\x00\x00\x00")); // not enough / wrong ctrl
        $this->assertNull(SevenConnless::parseTokenResponse('')); // empty
    }

    public function test_builds_a_connless_packet_with_the_0x21_header(): void
    {
        $packet = SevenConnless::connless(0xAABBCCDD, 0x11223344, 'DATA');

        $this->assertSame('21'.'aabbccdd'.'11223344'.bin2hex('DATA'), bin2hex($packet));
    }

    public function test_parses_a_connless_response_into_its_data(): void
    {
        $packet = "\x21" . "\xaa\xbb\xcc\xdd" . "\x11\x22\x33\x44" . 'PAYLOAD';

        $parsed = SevenConnless::parseConnless($packet);

        $this->assertNotNull($parsed);
        $this->assertSame(0xAABBCCDD, $parsed['token']);
        $this->assertSame(0x11223344, $parsed['response_token']);
        $this->assertSame('PAYLOAD', $parsed['data']);
    }

    public function test_rejects_a_non_connless_or_truncated_packet(): void
    {
        $this->assertNull(SevenConnless::parseConnless("\x04\x00\x00")); // too short / not connless
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter SevenConnlessTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Create `SevenConnless`**

Create `app/TwStats/Protocol/Seven/SevenConnless.php`:

```php
<?php

namespace App\TwStats\Protocol\Seven;

/**
 * Frames and parses Teeworlds 0.7 connless packets and the token-handshake control packets.
 * 0.7 connless packets carry a 9-byte header (flag/version byte + 4-byte server token + 4-byte
 * response token); a server only answers once the client has obtained its token via a
 * NET_CTRLMSG_TOKEN control exchange. Mirrors network.cpp.
 */
final class SevenConnless
{
    private const PACKETFLAG_CONNLESS = 8;
    private const PACKETFLAG_CONTROL = 1;
    private const PACKETVERSION = 1;
    private const CTRLMSG_TOKEN = 5;
    private const TOKEN_NONE = 0xFFFFFFFF;

    private const CONNLESS_HEADER = 9;
    private const PACKET_HEADER = 7;

    /**
     * the 7-byte control header + [NET_CTRLMSG_TOKEN][my token] that asks a server for its token
     */
    public static function tokenRequest(int $myToken): string
    {
        $header = chr((self::PACKETFLAG_CONTROL << 2) & 0xFC)
            . "\x00"  // ack low
            . "\x00"  // num chunks
            . self::packToken(self::TOKEN_NONE);

        return $header . chr(self::CTRLMSG_TOKEN) . self::packToken($myToken);
    }

    /**
     * extract the server's token from a NET_CTRLMSG_TOKEN control response, or null if the
     * packet is not a control-token packet
     */
    public static function parseTokenResponse(string $packet): ?int
    {
        if (strlen($packet) < self::PACKET_HEADER + 5) {
            return null;
        }

        $flags = (ord($packet[0]) & 0xFC) >> 2;
        if (!($flags & self::PACKETFLAG_CONTROL)) {
            return null;
        }

        $payload = substr($packet, self::PACKET_HEADER);
        if ($payload === '' || ord($payload[0]) !== self::CTRLMSG_TOKEN) {
            return null;
        }

        return self::unpackToken(substr($payload, 1, 4));
    }

    /**
     * a connless packet: 0x21 header + server token + response (client) token + data
     */
    public static function connless(int $serverToken, int $myToken, string $data): string
    {
        $first = (self::PACKETFLAG_CONNLESS << 2) & 0xFC | (self::PACKETVERSION & 0x03);

        return chr($first) . self::packToken($serverToken) . self::packToken($myToken) . $data;
    }

    /**
     * @return array{token: int, response_token: int, data: string}|null
     */
    public static function parseConnless(string $packet): ?array
    {
        if (strlen($packet) < self::CONNLESS_HEADER) {
            return null;
        }

        $flags = (ord($packet[0]) & 0xFC) >> 2;
        if (!($flags & self::PACKETFLAG_CONNLESS)) {
            return null;
        }

        return [
            'token' => self::unpackToken(substr($packet, 1, 4)),
            'response_token' => self::unpackToken(substr($packet, 5, 4)),
            'data' => substr($packet, self::CONNLESS_HEADER),
        ];
    }

    private static function packToken(int $token): string
    {
        return pack('N', $token & 0xFFFFFFFF);
    }

    private static function unpackToken(string $bytes): int
    {
        return unpack('N', $bytes)[1];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter SevenConnlessTest`
Expected: PASS (all green). Then `vendor/bin/phpunit`.

- [ ] **Step 5: Commit**

```bash
git add app/TwStats/Protocol/Seven/SevenConnless.php tests/Unit/Protocol/Seven/SevenConnlessTest.php
git commit -m "feat(serverbrowser): add Teeworlds 0.7 connless + token packet framing

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: `SevenInfoCodec` + `SevenListCodec`

**Files:**
- Create: `tests/Unit/Protocol/Seven/SevenInfoCodecTest.php`, `tests/Unit/Protocol/Seven/SevenListCodecTest.php`
- Create: `app/TwStats/Protocol/Seven/SevenInfoCodec.php`, `app/TwStats/Protocol/Seven/SevenListCodec.php`

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Protocol/Seven/SevenInfoCodecTest.php`:

```php
<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Protocol\Seven\SevenInfoCodec;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class SevenInfoCodecTest extends TestCase
{
    /** build a valid inf3 0.7 payload (everything after the SERVERBROWSE_INFO token) */
    private function payload(): string
    {
        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";

        return $int(1234)            // browse token echo
            . $str('0.7.5')          // version
            . $str('My 0.7 Server')  // name
            . $str('localhost')      // hostname
            . $str('ctf1')           // map
            . $str('CTF')            // gametype
            . $int(0)                // flags
            . $int(1)                // skill level
            . $int(1)                // num players
            . $int(16)               // max players
            . $int(1)                // num clients
            . $int(16)               // max clients
            // one client: name, clan, country, score, player_flag (0 = player)
            . $str('Alice') . $str('CLAN') . $int(276) . $int(5) . $int(0);
    }

    public function test_parses_the_0_7_info_layout_into_a_discovered_server(): void
    {
        $address = new DiscoveredAddress('192.0.2.50', 8303, 7);

        $server = (new SevenInfoCodec())->parse($this->payload(), $address);

        $this->assertNotNull($server);
        $this->assertSame('My 0.7 Server', $server->name);
        $this->assertSame('ctf1', $server->map);
        $this->assertSame('CTF', $server->gametype);
        $this->assertSame('0.7.5', $server->version);
        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame(16, $server->maxClients);
        $this->assertSame(16, $server->maxPlayers);
        $this->assertSame([$address], $server->addresses);

        $this->assertCount(1, $server->clients);
        $client = $server->clients[0];
        $this->assertSame('Alice', $client->name);
        $this->assertSame('CLAN', $client->clan);
        $this->assertSame(276, $client->country);
        $this->assertSame(5, $client->score);
        $this->assertTrue($client->isPlayer);          // player_flag 0 → player
        $this->assertNull($client->afk);               // 0.7 carries no afk/skin
        $this->assertNull($client->skin);
        $this->assertNull($client->skinParts);
    }

    public function test_returns_null_on_a_truncated_payload(): void
    {
        $address = new DiscoveredAddress('192.0.2.50', 8303, 7);
        $this->assertNull((new SevenInfoCodec())->parse("\x01\x02\x03", $address));
    }
}
```

Create `tests/Unit/Protocol/Seven/SevenListCodecTest.php`:

```php
<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\SevenListCodec;
use PHPUnit\Framework\TestCase;

class SevenListCodecTest extends TestCase
{
    public function test_parses_ipv4_mapped_and_ipv6_entries(): void
    {
        // IPv4 192.0.2.50:8303 as an IPv4-mapped 16-byte address + port 8303 (0x206f)
        $ipv4 = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . "\xc0\x00\x02\x32" . "\x20\x6f";
        // IPv6 2001:db8::5:8310 (port 0x2076)
        $ipv6 = "\x20\x01\x0d\xb8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x05" . "\x20\x76";

        $addresses = (new SevenListCodec())->parse($ipv4 . $ipv6);

        $this->assertCount(2, $addresses);
        $this->assertSame('192.0.2.50', $addresses[0]->ip);
        $this->assertSame(8303, $addresses[0]->port);
        $this->assertSame(7, $addresses[0]->protocol);
        $this->assertSame('2001:db8::5', $addresses[1]->ip);
        $this->assertSame(8310, $addresses[1]->port);
        $this->assertSame(7, $addresses[1]->protocol);
    }

    public function test_ignores_a_trailing_partial_entry(): void
    {
        $this->assertSame([], (new SevenListCodec())->parse("\x00\x00\x01")); // < 18 bytes
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter "SevenInfoCodecTest|SevenListCodecTest"`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `SevenInfoCodec`**

Create `app/TwStats/Protocol/Seven/SevenInfoCodec.php`:

```php
<?php

namespace App\TwStats\Protocol\Seven;

use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredClient;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Discovery\FlavorClassifier;

/**
 * Parses a Teeworlds 0.7 `inf3` info payload (everything after the SERVERBROWSE_INFO token)
 * into a DiscoveredServer for the given address. The 0.7 layout differs from 0.6/DDNet: it
 * inserts `hostname` and `skill_level`, and the per-client flag is 0=player / 1=spectator /
 * 2=bot. 0.7 carries no skin/afk, so those cosmetic fields are left null/unknown.
 */
final class SevenInfoCodec
{
    public function parse(string $payload, DiscoveredAddress $address): ?DiscoveredServer
    {
        $unpacker = new Unpacker($payload);

        $unpacker->getInt();             // browse-token echo (matched by the caller at the socket layer)
        $version = $unpacker->getString();
        $name = $unpacker->getString();
        $unpacker->getString();          // hostname (0.7 only; not tracked)
        $map = $unpacker->getString();
        $gametype = $unpacker->getString();
        $unpacker->getInt();             // flags
        $unpacker->getInt();             // skill level (0.7 only)
        $numPlayers = $unpacker->getInt();
        $maxPlayers = $unpacker->getInt();
        $numClients = $unpacker->getInt();
        $maxClients = $unpacker->getInt();

        if ($unpacker->error()) {
            return null;
        }

        $clients = [];
        for ($i = 0; $i < $numClients; $i++) {
            $clientName = $unpacker->getString();
            $clan = $unpacker->getString();
            $country = $unpacker->getInt();
            $score = $unpacker->getInt();
            $playerFlag = $unpacker->getInt();

            if ($unpacker->error()) {
                return null;
            }

            $clients[] = new DiscoveredClient(
                name: $clientName,
                clan: $clan,
                country: $country,
                score: $score,
                isPlayer: $playerFlag === 0,
                afk: null,       // 0.7 reports no afk
                skin: null,
                colorBody: null,
                colorFeet: null,
                skinParts: null,
            );
        }

        return new DiscoveredServer(
            addresses: [$address],
            name: $name,
            map: $map,
            gametype: $gametype,
            version: $version,
            maxClients: $maxClients,
            maxPlayers: $maxPlayers,
            clients: $clients,
            location: null,
            flavor: FlavorClassifier::classify($version),
        );
    }
}
```

- [ ] **Step 4: Create `SevenListCodec`**

Create `app/TwStats/Protocol/Seven/SevenListCodec.php`:

```php
<?php

namespace App\TwStats\Protocol\Seven;

use App\TwStats\Discovery\DiscoveredAddress;

/**
 * Parses a Teeworlds 0.7 `lis2` master payload into game-server addresses. Each entry is a
 * 16-byte IP (IPv4-mapped when the bytes 0-11 are the `::ffff:` prefix) + a 2-byte big-endian
 * port. All entries are 0.7 endpoints (protocol 7). Mirrors CMastersrvAddr.
 */
final class SevenListCodec
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

            $addresses[] = new DiscoveredAddress($ip, $port, 7);
        }

        return $addresses;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit --filter "SevenInfoCodecTest|SevenListCodecTest"`
Expected: PASS (all green). Then `vendor/bin/phpunit`.

NOTE: `SevenInfoCodec` constructs `DiscoveredClient` with `afk: null`. `DiscoveredClient::$afk` is currently typed `bool` (Phase 2). This task widens it to `?bool` — make that change as part of Step 5 if the constructor rejects `null`: in `app/TwStats/Discovery/DiscoveredClient.php` change `public readonly bool $afk,` to `public readonly ?bool $afk,` and update its docblock to note `null` means "unknown (UDP sources carry no afk)". The DDNet parser already passes a real bool, so it is unaffected. Re-run the full suite after the change.

- [ ] **Step 6: Commit**

```bash
git add app/TwStats/Protocol/Seven/SevenInfoCodec.php app/TwStats/Protocol/Seven/SevenListCodec.php \
        app/TwStats/Discovery/DiscoveredClient.php \
        tests/Unit/Protocol/Seven/SevenInfoCodecTest.php tests/Unit/Protocol/Seven/SevenListCodecTest.php
git commit -m "feat(serverbrowser): parse Teeworlds 0.7 info and master-list payloads

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Full-suite verification

- [ ] **Step 1:** Run `vendor/bin/phpunit` — expect all green (Phases 1–3 + the new 0.7 codec; `afk` widening to `?bool` must not break the DDNet parser tests, which pass a real bool).
- [ ] **Step 2:** Commit any needed fixup; otherwise skip.

---

## Self-Review

**Spec coverage (Phase 4a slice of §4.2/§5.2/§5.3):**
- 0.7 variable-int + payload unpacking → Task 1. ✓
- Connless framing + token handshake packets → Task 2. ✓
- 0.7 info payload parser (hostname + skill_level + inverted player flag) → `SevenInfoCodec` (Task 3). ✓
- master `lis2` address parser → `SevenListCodec` (Task 3). ✓
- `afk` widened to `?bool` so UDP sources express "unknown" — needed for the Phase 4c merge guard. ✓
- Out of scope (4b/4c): the UDP socket I/O (`Teeworlds07Source`), wiring into `UpdateData`, and the cosmetic-clobber guard in `updatePlayers`.

**Placeholder scan:** none — full code + byte fixtures throughout.

**Type consistency:** `VariableInt::unpack(): [int,int]`, `Unpacker::getInt/getString/getRaw`, `SevenConnless` static builders/parsers returning the documented shapes, `SevenInfoCodec::parse(string, DiscoveredAddress): ?DiscoveredServer`, `SevenListCodec::parse(string): DiscoveredAddress[]`. `DiscoveredClient.afk` becomes `?bool` (DDNet parser passes bool, 0.7 passes null). `FlavorClassifier::classify(version)` reused (a sixup server's `0.7↔0.6.4, …` version still classifies `ddnet` via the comma).
