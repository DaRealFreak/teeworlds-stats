# Multi-ecosystem Ingestion — Phase 4b: Teeworlds07Source (UDP I/O) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Wrap the (already live-validated) Teeworlds 0.7 query flow — token handshake → `req2`/`lis2` master list → `gie3`/`inf3` per-server info — in a `Teeworlds07Source` that yields `DiscoveredServer[]`, with the UDP socket behind an injectable transport so the orchestration is unit-testable without a network.

**Architecture:** A `UdpTransport` interface (`send`, `receive`) with a real `SocketUdpTransport` (non-blocking UDP, mirroring the proven probe) and a test `FakeUdpTransport`. `Teeworlds07Source` runs four phases: (1) token-handshake the masters, (2) `req2` → collect `lis2` → dedup server addresses, (3) token-handshake each server (chunked), (4) `gie3` → collect `inf3` → `SevenInfoCodec` → `DiscoveredServer`. It reuses the Phase 4a codec end-to-end. The flow was validated live against `master1.teeworlds.com` (51 vanilla_07 servers discovered, 3/3 sampled servers info-parsed correctly), so this phase is formalization, not discovery.

**Tech Stack:** PHP 8.5, raw UDP sockets (`socket_*`), the Phase 4a `App\TwStats\Protocol\Seven` codec, Phase 2 `DiscoveredServer`/`DiscoveredAddress`. Orchestration unit-tested with a fake transport in `tests/Unit/Discovery`. Run `vendor/bin/phpunit` in the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` §4.2. Reference flow (proven): token request (padded) → `parseTokenResponse`; `SevenConnless::connless(serverToken, myToken, SERVERBROWSE_GETLIST)` → `SevenListCodec`; `connless(serverToken, myToken, SERVERBROWSE_GETINFO . VariableInt::pack(browseToken))` → strip 8-byte `inf3` token → `SevenInfoCodec`.

**Scope note:** Phase 4b. 4c wires this into `UpdateData`'s source list (DDNet-first priority) + adds the cosmetic-clobber guard. The `SocketUdpTransport` is thin integration code (untested directly, like the legacy `NetworkController`); the orchestration in `Teeworlds07Source` is fully unit-tested via `FakeUdpTransport`.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/TwStats/Net/UdpTransport.php` | Interface: `send(ip, port, data)`, `receive(timeoutMs): ?array{ip,port,data}`. |
| `app/TwStats/Net/SocketUdpTransport.php` | Real non-blocking UDP socket implementation. |
| `app/TwStats/Discovery/Teeworlds07Source.php` | 4-phase 0.7 discovery → `DiscoveredServer[]`, transport injected. |
| `tests/Support/FakeUdpTransport.php` | Test double: scripted inbox (with `null` gap markers) + recorded sends. |
| `tests/Unit/Discovery/Teeworlds07SourceTest.php` | Drives the source with a fake transport through the full handshake→list→info flow. |

---

## Task 1: `UdpTransport` interface + `SocketUdpTransport`

**Files:**
- Create: `app/TwStats/Net/UdpTransport.php`, `app/TwStats/Net/SocketUdpTransport.php`

(No unit test — real sockets are integration-only, validated by the Task 3 live smoke. The interface is exercised through `Teeworlds07Source` in Task 2.)

- [ ] **Step 1: Create the interface**

Create `app/TwStats/Net/UdpTransport.php`:

```php
<?php

namespace App\TwStats\Net;

/**
 * A minimal connectionless UDP transport. Abstracted so the 0.7 discovery orchestration can be
 * unit-tested with a scripted fake instead of real sockets.
 */
interface UdpTransport
{
    public function send(string $ip, int $port, string $data): void;

    /**
     * Receive the next datagram, or null if none arrives within the timeout.
     *
     * @return array{ip: string, port: int, data: string}|null
     */
    public function receive(int $timeoutMs): ?array;
}
```

- [ ] **Step 2: Create the socket implementation**

Create `app/TwStats/Net/SocketUdpTransport.php`:

```php
<?php

namespace App\TwStats\Net;

/**
 * Non-blocking UDP via the socket extension. send() is fire-and-forget; receive() blocks up to
 * the given timeout for one datagram. Mirrors the connectionless I/O the legacy scraper uses,
 * and the flow proven live against the teeworlds.com 0.7 masters.
 */
final class SocketUdpTransport implements UdpTransport
{
    private \Socket $socket;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        // a short blocking read keeps the receive-drain loops responsive without a busy-wait
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]);
    }

    public function __destruct()
    {
        socket_close($this->socket);
    }

    public function send(string $ip, int $port, string $data): void
    {
        @socket_sendto($this->socket, $data, strlen($data), 0, $ip, $port);
    }

    public function receive(int $timeoutMs): ?array
    {
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => intdiv($timeoutMs, 1000),
            'usec' => ($timeoutMs % 1000) * 1000,
        ]);

        $data = '';
        $ip = '';
        $port = 0;
        $received = @socket_recvfrom($this->socket, $data, 4096, 0, $ip, $port);

        if ($received === false || $received <= 0) {
            return null;
        }

        return ['ip' => $ip, 'port' => (int) $port, 'data' => $data];
    }
}
```

- [ ] **Step 3: Confirm it parses (no test yet)**

Run: `php -r "require 'vendor/autoload.php'; new App\TwStats\Net\SocketUdpTransport(); echo 'ok'.PHP_EOL;"`
Expected: prints `ok` (the socket is created and torn down cleanly).

- [ ] **Step 4: Commit**

```bash
git add app/TwStats/Net/UdpTransport.php app/TwStats/Net/SocketUdpTransport.php
git commit -m "feat(serverbrowser): add UDP transport abstraction for 0.7 discovery

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `Teeworlds07Source` + `FakeUdpTransport` + orchestration test

**Files:**
- Create: `tests/Support/FakeUdpTransport.php`
- Create: `tests/Unit/Discovery/Teeworlds07SourceTest.php`
- Create: `app/TwStats/Discovery/Teeworlds07Source.php`

- [ ] **Step 1: Create the fake transport**

Create `tests/Support/FakeUdpTransport.php`:

```php
<?php

namespace Tests\Support;

use App\TwStats\Net\UdpTransport;

/**
 * Scripted UDP transport for testing the 0.7 discovery orchestration. queue() enqueues a datagram
 * to be returned by receive(); queueGap() enqueues a null (a "timeout") so a receive-drain loop
 * ends where the real network would fall silent. Sends are recorded for assertions.
 */
final class FakeUdpTransport implements UdpTransport
{
    /** @var array<int, array{ip: string, port: int, data: string}> */
    public array $sent = [];

    /** @var array<int, array{ip: string, port: int, data: string}|null> */
    private array $inbox = [];

    public function queue(string $ip, int $port, string $data): void
    {
        $this->inbox[] = ['ip' => $ip, 'port' => $port, 'data' => $data];
    }

    public function queueGap(): void
    {
        $this->inbox[] = null;
    }

    public function send(string $ip, int $port, string $data): void
    {
        $this->sent[] = ['ip' => $ip, 'port' => $port, 'data' => $data];
    }

    public function receive(int $timeoutMs): ?array
    {
        if ($this->inbox === []) {
            return null;
        }

        return array_shift($this->inbox);
    }
}
```

Ensure the `Tests\Support\` namespace autoloads: confirm `composer.json`'s `autoload-dev.psr-4` maps `Tests\\` to `tests/`. If it does not, add `"Tests\\": "tests/"` under `autoload-dev.psr-4` and run `composer dump-autoload`. (The existing tests are in `Tests\Feature`/`Tests\Unit`, so this mapping already exists; `tests/Support/FakeUdpTransport.php` with namespace `Tests\Support` will autoload.)

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Discovery/Teeworlds07SourceTest.php`:

```php
<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\Teeworlds07Source;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeUdpTransport;

class Teeworlds07SourceTest extends TestCase
{
    private function tokenResponse(int $token): string
    {
        // 7-byte control header + [NET_CTRLMSG_TOKEN=5] + token
        return "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . pack('N', $token);
    }

    private function listPacket(int $token, int $myToken, string $entries): string
    {
        return SevenConnless::connless($token, $myToken, "\xff\xff\xff\xfflis2" . $entries);
    }

    private function infoPacket(int $token, int $myToken): string
    {
        $int = fn (int $v) => VariableInt::pack($v);
        $str = fn (string $s) => $s . "\x00";
        $payload = $int(0) // browse token echo
            . $str('0.7.5') . $str('Vanilla DM') . $str('host') . $str('dm1') . $str('DM')
            . $int(0) . $int(1) . $int(1) . $int(8) . $int(1) . $int(8)
            . $str('Bob') . $str('') . $int(840) . $int(3) . $int(0);

        return SevenConnless::connless($token, $myToken, "\xff\xff\xff\xffinf3" . $payload);
    }

    public function test_discovers_servers_from_the_master_and_parses_their_info(): void
    {
        $transport = new FakeUdpTransport();
        // a single master, a single game server it lists
        $masterIp = '10.9.0.1';
        $serverIp = '192.0.2.50';

        // the source uses a fixed client token in tests (see Teeworlds07Source::CLIENT_TOKEN)
        $myToken = Teeworlds07Source::CLIENT_TOKEN;

        // phase 1: master token handshake
        $transport->queue($masterIp, 8283, $this->tokenResponse(0xA1A1A1A1));
        $transport->queueGap();
        // phase 2: master returns a list with one ipv4-mapped server (192.0.2.50:8303)
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\xc0\x00\x02\x32\x20\x6f";
        $transport->queue($masterIp, 8283, $this->listPacket(0xA1A1A1A1, $myToken, $entry));
        $transport->queueGap();
        // phase 3: server token handshake
        $transport->queue($serverIp, 8303, $this->tokenResponse(0xB2B2B2B2));
        $transport->queueGap();
        // phase 4: server info
        $transport->queue($serverIp, 8303, $this->infoPacket(0xB2B2B2B2, $myToken));
        $transport->queueGap();

        $source = new Teeworlds07Source($transport, masters: [['ip' => $masterIp, 'port' => 8283]]);
        $servers = $source->fetch();

        $this->assertCount(1, $servers);
        $server = $servers[0];
        $this->assertSame('Vanilla DM', $server->name);
        $this->assertSame('dm1', $server->map);
        $this->assertSame('vanilla_07', $server->flavor);
        $this->assertSame('192.0.2.50', $server->addresses[0]->ip);
        $this->assertSame(8303, $server->addresses[0]->port);
        $this->assertSame(7, $server->addresses[0]->protocol);
        $this->assertCount(1, $server->clients);
        $this->assertSame('Bob', $server->clients[0]->name);
        $this->assertNull($server->clients[0]->afk);
    }

    public function test_returns_empty_when_no_master_answers(): void
    {
        $transport = new FakeUdpTransport(); // nothing queued → every receive is a timeout
        $source = new Teeworlds07Source($transport, masters: [['ip' => '10.9.0.1', 'port' => 8283]]);

        $this->assertSame([], $source->fetch());
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter Teeworlds07SourceTest`
Expected: FAIL — `Teeworlds07Source` not found.

- [ ] **Step 4: Create `Teeworlds07Source`**

Create `app/TwStats/Discovery/Teeworlds07Source.php`:

```php
<?php

namespace App\TwStats\Discovery;

use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Net\UdpTransport;
use App\TwStats\Protocol\Seven\SevenConnless;
use App\TwStats\Protocol\Seven\SevenInfoCodec;
use App\TwStats\Protocol\Seven\SevenListCodec;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Discovers stock Teeworlds 0.7 servers — the population that registers only to teeworlds.com's
 * master and is therefore absent from the DDNet HTTP feed. Each query is a two-step 0.7 exchange:
 * obtain the peer's connless token via a NET_CTRLMSG_TOKEN handshake, then send the connless
 * request carrying that token. Runs in four drain phases: master handshake, master list (req2 →
 * lis2), per-server handshake, per-server info (gie3 → inf3). A dead master/server just drops out.
 */
final class Teeworlds07Source
{
    public const CLIENT_TOKEN = 0x5453_7473; // "TSts" — our fixed connless response token
    private const SERVERBROWSE_GETLIST = "\xff\xff\xff\xffreq2";
    private const SERVERBROWSE_LIST = "\xff\xff\xff\xfflis2";
    private const SERVERBROWSE_GETINFO = "\xff\xff\xff\xffgie3";
    private const SERVERBROWSE_INFO = "\xff\xff\xff\xffinf3";
    private const TOKEN_SIZE = 8; // the 8-byte SERVERBROWSE_* identifier prefix
    private const BROWSE_TOKEN = 0x1234;
    private const DRAIN_TIMEOUT_MS = 700;
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
        $masterTokens = $this->handshake($this->masters);
        $serverAddresses = $this->fetchServerList($masterTokens);

        return $this->queryServers($serverAddresses);
    }

    /**
     * @param array<int, array{ip: string, port: int}> $targets
     * @return array<string, array{ip: string, port: int, token: int}> keyed by "ip:port"
     */
    private function handshake(array $targets): array
    {
        foreach ($targets as $target) {
            $this->transport->send($target['ip'], $target['port'], SevenConnless::tokenRequest(self::CLIENT_TOKEN));
        }

        $tokens = [];
        while (($packet = $this->transport->receive(self::DRAIN_TIMEOUT_MS)) !== null) {
            $token = SevenConnless::parseTokenResponse($packet['data']);
            if ($token !== null) {
                $tokens[$packet['ip'] . ':' . $packet['port']] = [
                    'ip' => $packet['ip'],
                    'port' => $packet['port'],
                    'token' => $token,
                ];
            }
        }

        return $tokens;
    }

    /**
     * @param array<string, array{ip: string, port: int, token: int}> $masterTokens
     * @return array<int, array{ip: string, port: int}>
     */
    private function fetchServerList(array $masterTokens): array
    {
        foreach ($masterTokens as $master) {
            $packet = SevenConnless::connless($master['token'], self::CLIENT_TOKEN, self::SERVERBROWSE_GETLIST);
            $this->transport->send($master['ip'], $master['port'], $packet);
        }

        $listCodec = new SevenListCodec();
        $addresses = [];
        while (($packet = $this->transport->receive(self::DRAIN_TIMEOUT_MS)) !== null) {
            $parsed = SevenConnless::parseConnless($packet['data']);
            if ($parsed === null || !str_starts_with($parsed['data'], self::SERVERBROWSE_LIST)) {
                continue;
            }
            foreach ($listCodec->parse(substr($parsed['data'], self::TOKEN_SIZE)) as $address) {
                $addresses[$address->ip . ':' . $address->port] = ['ip' => $address->ip, 'port' => $address->port];
            }
        }

        return array_values($addresses);
    }

    /**
     * @param array<int, array{ip: string, port: int}> $addresses
     * @return DiscoveredServer[]
     */
    private function queryServers(array $addresses): array
    {
        $infoCodec = new SevenInfoCodec();
        $query = self::SERVERBROWSE_GETINFO . VariableInt::pack(self::BROWSE_TOKEN);
        $servers = [];

        foreach (array_chunk($addresses, self::INFO_CHUNK) as $chunk) {
            $tokens = $this->handshake($chunk);

            foreach ($tokens as $server) {
                $packet = SevenConnless::connless($server['token'], self::CLIENT_TOKEN, $query);
                $this->transport->send($server['ip'], $server['port'], $packet);
            }

            while (($packet = $this->transport->receive(self::DRAIN_TIMEOUT_MS)) !== null) {
                $parsed = SevenConnless::parseConnless($packet['data']);
                if ($parsed === null || !str_starts_with($parsed['data'], self::SERVERBROWSE_INFO)) {
                    continue;
                }
                $address = new DiscoveredAddress($packet['ip'], $packet['port'], 7);
                $server = $infoCodec->parse(substr($parsed['data'], self::TOKEN_SIZE), $address);
                if ($server !== null) {
                    $servers[] = $server;
                }
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
                $masters[] = ['ip' => $ip, 'port' => 8283];
            }
        }

        return $masters;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter Teeworlds07SourceTest`
Expected: PASS (2 tests green). Then run the full suite `vendor/bin/phpunit`.

- [ ] **Step 6: Commit**

```bash
git add app/TwStats/Discovery/Teeworlds07Source.php tests/Support/FakeUdpTransport.php \
        tests/Unit/Discovery/Teeworlds07SourceTest.php
git commit -m "feat(serverbrowser): add Teeworlds07Source 0.7 UDP discovery

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Live smoke validation + full suite

**Files:** none (verification only).

- [ ] **Step 1: Full suite**

Run: `vendor/bin/phpunit`
Expected: all green (Phases 1–4a + the new orchestration tests).

- [ ] **Step 2: Live smoke against the real masters**

Run this one-off (the real `SocketUdpTransport`, the live teeworlds.com masters):

```bash
php -r "require 'vendor/autoload.php'; \$s = new App\TwStats\Discovery\Teeworlds07Source(); \$r = \$s->fetch(); echo count(\$r).' servers'.PHP_EOL; foreach (array_slice(\$r,0,5) as \$x) echo '  '.\$x->addresses[0]->ip.':'.\$x->addresses[0]->port.' | '.\$x->name.' | '.\$x->map.' | '.\$x->flavor.' | '.count(\$x->clients).'/'.\$x->maxClients.PHP_EOL;"
```

Expected: a non-zero server count with plausible `vanilla_07` servers (the live probe already returned 51 from master1 alone). If it returns 0, the masters may be transiently down — note it; the source is designed to degrade to `[]`. (Do not add this to the test suite — it depends on the live network.)

- [ ] **Step 3: Commit (only if a fixup was needed)**

No code changes expected; commit any fixup, otherwise skip.

---

## Self-Review

**Spec coverage (Phase 4b slice of §4.2):**
- 0.7 UDP master discovery (`req2`/`lis2`) + per-server info (`gie3`/`inf3`) with the token handshake → `Teeworlds07Source` (Task 2). ✓
- Reuses the Phase 4a codec end-to-end; produces `DiscoveredServer`s tagged protocol 7, flavor via `FlavorClassifier`. ✓
- Testable orchestration via `UdpTransport`/`FakeUdpTransport`; real socket I/O isolated in `SocketUdpTransport` (Task 1). ✓
- Graceful degradation: a silent master/server simply yields no entry; `fetch()` returns `[]` if nothing answers. ✓
- Out of scope (4c): wiring into `UpdateData`'s source list (DDNet first), and the cosmetic-clobber guard in `updatePlayers`.

**Placeholder scan:** none — full code + a fake-transport test that walks the whole four-phase flow.

**Type consistency:** `UdpTransport::receive(): ?array{ip,port,data}`; `Teeworlds07Source::fetch(): DiscoveredServer[]` reusing `SevenConnless`/`SevenListCodec`/`SevenInfoCodec`/`VariableInt` (Phase 4a) and `DiscoveredAddress`/`DiscoveredServer` (Phase 2). The 8-byte `SERVERBROWSE_*` token is stripped before handing the payload to the codecs (`substr(..., 8)`), matching how Phase 4a's tests construct their fixtures. `CLIENT_TOKEN` is a fixed constant so the fake-transport test can build matching connless response tokens.
