<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\Service\GameServerMapSource;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\DdnetUuid;
use App\TwStats\Protocol\Seven\VariableInt;
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
        return new GameServerMapSource(connectionFactory: fn (string $ip, int $port, string $flavor) => $conn);
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
}
