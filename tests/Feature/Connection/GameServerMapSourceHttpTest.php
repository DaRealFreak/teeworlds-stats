<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\Events\MapAcquisitionAttempted;
use App\Service\GameServerMapSource;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\DdnetUuid;
use App\TwStats\Protocol\Seven\VariableInt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Avenue 2: when a fast-download server advertises an HTTP download URL in MAP_DETAILS, the source
 * fetches the map over HTTP instead of UDP — gated by an SSRF guard.
 */
class GameServerMapSourceHttpTest extends TestCase
{
    /** A connection whose receive() yields a single scripted MAP_DETAILS message, then nothing. */
    private function fakeConnection(string $mapDetails): ConnectionInterface
    {
        return new class($mapDetails) implements ConnectionInterface {
            public bool $disconnected = false;
            /** @var array<int, string[]> */
            private array $inbox;

            public function __construct(string $mapDetails)
            {
                $this->inbox = [[$mapDetails]];
            }

            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return $this->inbox === [] ? [] : array_shift($this->inbox); }
            public function disconnect(): void { $this->disconnected = true; }
            public function isOnline(): bool { return !$this->disconnected; }
        };
    }

    private function mapDetails(string $name, string $sha256Hex, int $size, string $url): string
    {
        return VariableInt::pack((0 << 1) | 1)
            . DdnetUuid::compute('map-details@ddnet.tw')
            . $name . "\x00"
            . hex2bin($sha256Hex)
            . VariableInt::pack(123)        // crc (unused here)
            . VariableInt::pack($size)
            . $url . "\x00";
    }

    private function sourceReturning(ConnectionInterface $conn): GameServerMapSource
    {
        $dial = 0;

        // The first dial returns the scripted MAP_DETAILS connection. A second dial means the UDP
        // fallback fired after the HTTP fetch failed; these fixtures script no UDP transfer, so fail it
        // fast instead of letting an exhausted fake spin to the deadline.
        return new GameServerMapSource(connectionFactory: function () use ($conn, &$dial) {
            return $dial++ === 0 ? $conn : throw new \App\TwStats\Connection\ConnectionFailed('no udp fallback in this fixture');
        });
    }

    /** A source that hands out the given connections in order, one per dial (for HTTP→UDP fallback). */
    private function sourceDialling(ConnectionInterface ...$conns): GameServerMapSource
    {
        $dial = 0;

        return new GameServerMapSource(connectionFactory: function () use ($conns, &$dial) {
            return $conns[$dial++] ?? throw new \App\TwStats\Connection\ConnectionFailed('no more scripted dials');
        });
    }

    /** A connection whose single receive() batch carries the given pre-built messages (e.g. a UDP transfer). */
    private function connectionYielding(string ...$messages): ConnectionInterface
    {
        return new class($messages) implements ConnectionInterface {
            public bool $disconnected = false;
            /** @var array<int, string[]> */
            private array $inbox;

            /** @param string[] $batch */
            public function __construct(array $batch)
            {
                $this->inbox = [$batch];
            }

            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return $this->inbox === [] ? [] : array_shift($this->inbox); }
            public function disconnect(): void { $this->disconnected = true; }
            public function isOnline(): bool { return !$this->disconnected; }
        };
    }

    /** Normalise to signed int32 — the wire representation the server packs CRCs in. */
    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    private function mapChange(string $name, int $crc, int $size): string
    {
        return VariableInt::pack((2 << 1) | 1)          // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    private function mapData(int $last, int $crc, int $chunk, string $data): string
    {
        return VariableInt::pack((3 << 1) | 1)          // NETMSG_MAP_DATA = 3
            . VariableInt::pack($last)
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($chunk)
            . VariableInt::pack(strlen($data))
            . $data;
    }

    public function test_falls_back_to_udp_when_the_advertised_http_mirror_is_blocked(): void
    {
        $payload = 'DATA' . str_repeat("\x00", 40); // a valid map datafile (DATA magic + header)
        $crc     = crc32($payload);
        $sha     = hash('sha256', 'whatever the mirror announces'); // mirror's announced hash; HTTP never succeeds
        $url     = 'https://1.1.1.1/Custom_' . $sha . '.map';

        // Cloudflare-style block: the advertised mirror 403s our server-side client (the in-game
        // client, which downloads over UDP, never trips this).
        Http::fake([$url => Http::response('<!DOCTYPE html>forbidden', 403)]);

        $details = $this->fakeConnection($this->mapDetails('Custom', $sha, strlen($payload), $url));
        $udp     = $this->connectionYielding($this->mapChange('Custom', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        $result = $this->sourceDialling($details, $udp)
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'Custom', 5000);

        self::assertSame($payload, $result, 'a blocked HTTP mirror must fall back to the UDP map stream');
    }

    public function test_default_overall_timeout_is_120s_and_honours_the_env(): void
    {
        $timeoutMs = new \ReflectionProperty(GameServerMapSource::class, 'timeoutMs');

        self::assertSame(120000, $timeoutMs->getValue(new GameServerMapSource()), 'default overall deadline is 120s');

        putenv('MAP_UDP_TIMEOUT_S=45');
        $_ENV['MAP_UDP_TIMEOUT_S'] = '45';
        try {
            self::assertSame(45000, $timeoutMs->getValue(new GameServerMapSource()), 'MAP_UDP_TIMEOUT_S (seconds) overrides the default');
        } finally {
            putenv('MAP_UDP_TIMEOUT_S');
            unset($_ENV['MAP_UDP_TIMEOUT_S']);
        }
    }

    public function test_skips_the_udp_fallback_when_the_server_has_cycled_to_another_map(): void
    {
        Event::fake([MapAcquisitionAttempted::class]);

        // The host now advertises a DIFFERENT map (BlueSky) than the one we asked for (RaiNyMore): it
        // has cycled. We must NOT re-dial to download the wrong map — record a clean name_mismatch.
        $sha = hash('sha256', 'BlueSky bytes');
        $url = 'https://1.1.1.1/BlueSky_' . $sha . '.map';
        Http::fake(); // the mirror must never be hit either

        // Only one connection is supplied; a wrongful UDP fallback would dial a second time, which
        // sourceDialling answers with a ConnectionFailed — so a single name_mismatch event proves the skip.
        $details = $this->fakeConnection($this->mapDetails('BlueSky', $sha, 4096, $url));

        $result = $this->sourceDialling($details)
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'RaiNyMore', 5000);

        self::assertNull($result);
        Http::assertNothingSent();
        Event::assertDispatchedTimes(MapAcquisitionAttempted::class, 1);
        Event::assertDispatched(MapAcquisitionAttempted::class, fn ($e) => $e->source === 'udp' && $e->outcome === 'failure' && $e->reason === 'name_mismatch');
    }

    public function test_classifies_a_caller_crc_mismatch_as_crc_mismatch(): void
    {
        Event::fake([MapAcquisitionAttempted::class]);

        // The host serves a self-consistent map, but its CRC is not the one we expected (the classic
        // cycled-server-with-a-crc case) → the session raises a caller-expectation CRC mismatch.
        $payload = 'DATA' . str_repeat("\x00", 40);
        $crc     = crc32($payload);
        $wrongExpectedCrc = $this->toInt32($crc ^ 0x1234);
        $udp = $this->connectionYielding($this->mapChange('RaiNyMore', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        $result = $this->sourceDialling($udp)
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, $wrongExpectedCrc, 'RaiNyMore', 5000);

        self::assertNull($result);
        Event::assertDispatched(MapAcquisitionAttempted::class, fn ($e) => $e->source === 'udp' && $e->reason === 'crc_mismatch');
    }

    public function test_classifies_a_transfer_timeout_as_timeout(): void
    {
        Event::fake([MapAcquisitionAttempted::class]);

        // The host never sends MAP_CHANGE → the session times out; the reason must be 'timeout', not the
        // generic 'download_failed', so the dashboard separates slow/broken hosts from cycled ones.
        $silent = $this->connectionYielding(); // no scripted messages

        $result = $this->sourceDialling($silent)
            ->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'RaiNyMore', 1);

        self::assertNull($result);
        Event::assertDispatched(MapAcquisitionAttempted::class, fn ($e) => $e->source === 'udp' && $e->reason === 'timeout');
    }

    public function test_fetches_the_map_from_the_servers_advertised_https_url(): void
    {
        $bytes = 'DATA' . str_repeat("\x00", 512);
        $sha   = hash('sha256', $bytes);
        $url   = 'https://1.1.1.1/Custom_' . $sha . '.map'; // literal public IP → no DNS, passes SSRF guard

        Http::fake([$url => Http::response($bytes, 200)]);

        $conn   = $this->fakeConnection($this->mapDetails('Custom', $sha, strlen($bytes), $url));
        $result = $this->sourceReturning($conn)->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'Custom', 5000);

        self::assertSame($bytes, $result, 'the map must be fetched from the advertised URL');
    }

    public function test_rejects_bytes_that_fail_the_announced_sha256(): void
    {
        $sha = hash('sha256', 'the real map');
        $url = 'https://1.1.1.1/Custom_' . $sha . '.map';

        Http::fake([$url => Http::response('DATA' . str_repeat("\x01", 64), 200)]); // wrong content

        $conn   = $this->fakeConnection($this->mapDetails('Custom', $sha, 68, $url));
        $result = $this->sourceReturning($conn)->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'Custom', 5000);

        self::assertNull($result);
    }

    public function test_ssrf_guard_refuses_a_private_or_loopback_url(): void
    {
        $sha = hash('sha256', 'DATA');
        $url = 'https://127.0.0.1/evil_' . $sha . '.map'; // loopback → must never be requested

        Http::fake();

        $conn   = $this->fakeConnection($this->mapDetails('Custom', $sha, 4, $url));
        $result = $this->sourceReturning($conn)->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'Custom', 5000);

        self::assertNull($result);
        Http::assertNothingSent();
    }

    public function test_ssrf_guard_refuses_a_plain_http_url(): void
    {
        $sha = hash('sha256', 'DATA');
        $url = 'http://1.1.1.1/Custom_' . $sha . '.map'; // not https

        Http::fake();

        $conn   = $this->fakeConnection($this->mapDetails('Custom', $sha, 4, $url));
        $result = $this->sourceReturning($conn)->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'Custom', 5000);

        self::assertNull($result);
        Http::assertNothingSent();
    }

    public function test_accepts_a_udp_map_whose_wire_name_differs_only_in_case(): void
    {
        // Live-observed (server 92.5.2.254:8303, GORES): the browser-info / DDNet-feed map name we store
        // is lower-cased ("savannux") while the in-game MAP_CHANGE announces the real filename ("Savannux").
        // The bytes are byte-perfect and the crc matches, so a case-only difference must NOT be rejected —
        // a case-sensitive gate previously failed every attempt against such hosts (100% name_mismatch).
        $payload = 'DATA' . str_repeat("\x00", 40);
        $crc     = crc32($payload);
        $udp     = $this->connectionYielding($this->mapChange('Savannux', $crc, strlen($payload)), $this->mapData(1, $crc, 0, $payload));

        $result = $this->sourceDialling($udp)
            ->downloadFromHost('9.9.9.9', 8303, 'vanilla_06', null, $this->toInt32($crc), 'savannux', 5000);

        self::assertSame($payload, $result, 'a wire name that differs only in case must still match the expected map');
    }

    public function test_accepts_an_advertised_http_map_whose_name_differs_only_in_case(): void
    {
        // Same case-folding tolerance on the fast-download (MAP_DETAILS → HTTP) gate: the advertised name
        // may differ in case from the stored one without meaning the host has cycled.
        $bytes = 'DATA' . str_repeat("\x00", 512);
        $sha   = hash('sha256', $bytes);
        $url   = 'https://1.1.1.1/Savannux_' . $sha . '.map';

        Http::fake([$url => Http::response($bytes, 200)]);

        $conn   = $this->fakeConnection($this->mapDetails('Savannux', $sha, strlen($bytes), $url));
        $result = $this->sourceReturning($conn)->downloadFromHost('9.9.9.9', 8303, 'ddnet', null, null, 'savannux', 5000);

        self::assertSame($bytes, $result, 'an advertised name differing only in case must still be fetched');
    }
}
