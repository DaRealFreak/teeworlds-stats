<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\SevenMapDownloadSession;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class SevenMapDownloadSessionTest extends TestCase
{
    private function packInt(int $msgId): string
    {
        return VariableInt::pack(($msgId << 1) | 1);
    }

    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    /** 0.7 MAP_CHANGE: name, crc, size, chunksPerRequest, chunkSize, raw sha256[32]. */
    private function buildMapChange07(string $name, int $crc, int $size, int $chunksPerRequest, int $chunkSize, string $sha256Raw): string
    {
        return $this->packInt(2)
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size)
            . VariableInt::pack($chunksPerRequest)
            . VariableInt::pack($chunkSize)
            . $sha256Raw;
    }

    /** 0.7 MAP_DATA: raw bytes only. */
    private function buildMapData07(string $data): string
    {
        return $this->packInt(3) . $data;
    }

    private function makePayload(int $size): string
    {
        $payload = '';
        for ($i = 0; $i < $size; $i++) {
            $payload .= chr($i & 0xFF);
        }

        return $payload;
    }

    public function testWindowedDownloadReassemblesAndReportsIdentity(): void
    {
        $chunkSize = 16;
        $payload   = $this->makePayload($chunkSize * 5); // 5 chunks
        $crc       = crc32($payload);
        $sha256Hex = hash('sha256', $payload);
        $sha256Raw = hex2bin($sha256Hex);

        $chunks = [];
        for ($i = 0; $i < 5; $i++) {
            $chunks[$i] = substr($payload, $i * $chunkSize, $chunkSize);
        }

        // Window size 2 → server delivers 2 chunks per REQUEST, forcing the session to re-request.
        $conn = new SevenWindowedFakeConnection(
            mapChange: $this->buildMapChange07('ctf5', $crc, strlen($payload), 2, $chunkSize, $sha256Raw),
            chunks: $chunks,
            windowSize: 2,
            buildMapData: fn (string $data) => $this->buildMapData07($data),
        );

        $result = (new SevenMapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 5000);

        self::assertSame($payload, $result->bytes, '0.7 windowed download must reassemble the payload');
        self::assertSame('ctf5', $result->name);
        self::assertSame($sha256Hex, $result->sha256);
        self::assertTrue($conn->disconnected, 'disconnect() must be called after success');

        // First sent message is NETMSG_INFO (id=1) carrying the 0.7 version string.
        [$typeInt] = VariableInt::unpack($conn->sent[0], 0);
        self::assertSame(1, $typeInt >> 1, 'first sent message must be NETMSG_INFO');
        self::assertStringContainsString('0.7 802f1be60a05665f', $conn->sent[0]);

        // Subsequent sends are REQUEST_MAP_DATA (id=23); 5 chunks / window 2 → 3 requests.
        $requests = array_filter(
            array_slice($conn->sent, 1),
            static function (string $m): bool {
                [$t] = VariableInt::unpack($m, 0);
                return ($t >> 1) === 23;
            },
        );
        self::assertCount(3, $requests, 'must send one REQUEST_MAP_DATA per window (ceil(5/2)=3)');
    }

    public function testBundledMapChangeAndFirstWindowInOneBatch(): void
    {
        $chunkSize = 16;
        $payload   = $this->makePayload($chunkSize * 3);
        $crc       = crc32($payload);
        $sha256Hex = hash('sha256', $payload);
        $sha256Raw = hex2bin($sha256Hex);

        // One burst: MAP_CHANGE + all three raw chunks in a single receive() batch.
        $batch = [$this->buildMapChange07('dm', $crc, strlen($payload), 8, $chunkSize, $sha256Raw)];
        for ($i = 0; $i < 3; $i++) {
            $batch[] = $this->buildMapData07(substr($payload, $i * $chunkSize, $chunkSize));
        }

        $conn = new SevenBatchFakeConnection([$batch]);

        $result = (new SevenMapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 5000);

        $this->assertSame($payload, $result->bytes, 'bundled MAP_CHANGE + first window must reassemble');
        $this->assertSame($sha256Hex, $result->sha256);
        $this->assertTrue($conn->disconnected, 'disconnect() must be called after success');
    }

    public function testSizeMismatchThrows(): void
    {
        $payload   = $this->makePayload(16);
        $crc       = crc32($payload);
        $sha256Raw = hex2bin(hash('sha256', $payload));

        // Announce a larger size than will ever arrive → stall → timeout.
        $conn = new SevenWindowedFakeConnection(
            mapChange: $this->buildMapChange07('dm1', $crc, 999999, 4, 16, $sha256Raw),
            chunks: [$payload],
            windowSize: 4,
            buildMapData: fn (string $data) => $this->buildMapData07($data),
        );

        $this->expectException(\App\TwStats\Connection\MapDownloadFailed::class);
        (new SevenMapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 1);
    }
}

/**
 * Models a 0.7 server's windowed delivery: MAP_CHANGE on the first receive(); thereafter each
 * REQUEST_MAP_DATA releases up to windowSize raw MAP_DATA chunks. Empty between windows so the
 * session must re-request to make progress.
 */
final class SevenWindowedFakeConnection implements ConnectionInterface
{
    /** @var string[] */
    public array $sent = [];

    public bool $disconnected = false;

    /** @var string[][] */
    private array $inbox = [];

    private bool $mapChangeSent = false;

    private int $nextChunk = 0;

    /** @param string[] $chunks */
    public function __construct(
        private readonly string $mapChange,
        private readonly array $chunks,
        private readonly int $windowSize,
        private readonly mixed $buildMapData,
    ) {
    }

    public function sendVital(string $message): void
    {
        $this->sent[] = $message;

        [$typeInt] = VariableInt::unpack($message, 0);
        if (($typeInt >> 1) !== 23) { // NETMSG_REQUEST_MAP_DATA
            return;
        }

        $window = [];
        $end    = min($this->nextChunk + $this->windowSize, count($this->chunks));
        for (; $this->nextChunk < $end; $this->nextChunk++) {
            $window[] = ($this->buildMapData)($this->chunks[$this->nextChunk]);
        }
        if ($window !== []) {
            $this->inbox[] = $window;
        }
    }

    public function flush(): void
    {
    }

    /** @return string[] */
    public function receive(int $timeoutMs): array
    {
        if (!$this->mapChangeSent) {
            $this->mapChangeSent = true;

            return [$this->mapChange];
        }

        if ($this->inbox === []) {
            return [];
        }

        return array_shift($this->inbox);
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public function isOnline(): bool
    {
        return !$this->disconnected;
    }
}

/**
 * Returns pre-scripted receive() batches verbatim (each enqueue() = one receive() result), modelling a
 * server that bundles MAP_CHANGE and the first window of MAP_DATA chunks into a single datagram.
 */
final class SevenBatchFakeConnection implements ConnectionInterface
{
    public array $sent = [];

    public bool $disconnected = false;

    /** @var string[][] */
    private array $inbox;

    /** @param string[][] $batches */
    public function __construct(array $batches)
    {
        $this->inbox = $batches;
    }

    public function sendVital(string $message): void
    {
        $this->sent[] = $message;
    }

    public function flush(): void
    {
    }

    /** @return string[] */
    public function receive(int $timeoutMs): array
    {
        if ($this->inbox === []) {
            return [];
        }

        return array_shift($this->inbox);
    }

    public function disconnect(): void
    {
        $this->disconnected = true;
    }

    public function isOnline(): bool
    {
        return !$this->disconnected;
    }
}
