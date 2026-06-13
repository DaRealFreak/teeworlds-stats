<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\MapDownloadFailed;
use App\TwStats\Connection\MapDownloadSession;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MapDownloadSession — driven entirely by a FakeConnection whose
 * receive() returns pre-scripted message byte-strings; no real UDP socket.
 *
 * Message framing (confirmed from ddnet/src/engine/shared/protocol.h):
 *   packed_int = VariableInt::pack((msgId << 1) | 1)   (system flag = bit 0)
 *
 * Confirmed NETMSG numbers:
 *   NETMSG_INFO             = 1
 *   NETMSG_MAP_CHANGE       = 2
 *   NETMSG_MAP_DATA         = 3
 *   NETMSG_REQUEST_MAP_DATA = 19
 *
 * NETMSG_MAP_CHANGE fields (vanilla 0.6): string name, int crc, int size
 * NETMSG_MAP_DATA   fields (0.6):        int last, int crc, int chunk, int size, raw data[size]
 */
class MapDownloadSessionTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Message builders (same encoding used by MapDownloadSession itself)
    // -----------------------------------------------------------------------

    private function packInt(int $msgId): string
    {
        return VariableInt::pack(($msgId << 1) | 1);
    }

    /**
     * Normalise a value to a signed 32-bit integer.
     *
     * PHP's crc32() on 64-bit systems returns the unsigned 32-bit value as a
     * 64-bit positive int (e.g. 3469664904 instead of -825302392). Teeworlds
     * packs CRCs as signed ints via VariableInt; the server-side representation
     * is always signed int32. This helper bridges the gap so test messages use
     * the same bit pattern the server would send.
     */
    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    /**
     * Build a NETMSG_MAP_CHANGE (vanilla 0.6): name, crc, size.
     * crc is normalised to signed int32 to match the server wire representation.
     */
    private function buildMapChange(string $name, int $crc, int $size): string
    {
        return $this->packInt(2)               // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    /**
     * Build a NETMSG_MAP_CHANGE (extended, with sha256 raw bytes between name and crc).
     */
    private function buildMapChangeExtended(string $name, string $sha256Raw, int $crc, int $size): string
    {
        return $this->packInt(2)               // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . $sha256Raw                       // 32 raw bytes
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    /**
     * Build a NETMSG_MAP_DATA chunk.
     * Fields (0.6): last, crc, chunk, size, raw data.
     * crc is normalised to signed int32 to match the server wire representation.
     */
    private function buildMapData(int $last, int $crc, int $chunk, string $data): string
    {
        $size = strlen($data);

        return $this->packInt(3)               // NETMSG_MAP_DATA = 3
            . VariableInt::pack($last)
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($chunk)
            . VariableInt::pack($size)
            . $data;
    }

    /**
     * Build a small deterministic payload of $size bytes.
     */
    private function makePayload(int $size): string
    {
        $payload = '';
        for ($i = 0; $i < $size; $i++) {
            $payload .= chr($i & 0xFF);
        }

        return $payload;
    }

    // -----------------------------------------------------------------------
    // Test 1 — Happy path: two chunks, CRC matches, INFO + REQUEST sent
    // -----------------------------------------------------------------------

    public function testHappyPathTwoChunks(): void
    {
        $payload = $this->makePayload(20);
        $crc     = crc32($payload);
        $size    = strlen($payload);
        $half    = $size / 2; // 10 bytes each chunk

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, $size)]);
        $conn->enqueue([$this->buildMapData(0, $crc, 0, substr($payload, 0, $half))]);  // last=0
        $conn->enqueue([$this->buildMapData(1, $crc, 1, substr($payload, $half))]);      // last=1

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result, 'Reassembled bytes must match the original payload');

        // Verify the connection was disconnected after success.
        self::assertTrue($conn->disconnected, 'disconnect() must be called after success');

        // Verify sent messages.
        $sent = $conn->sent;
        self::assertGreaterThanOrEqual(2, count($sent), 'At least INFO + one REQUEST_MAP_DATA must be sent');

        // First sent message must be NETMSG_INFO (msgId=1).
        $this->assertSentMsgId($sent[0], 1, 'First sent message must be NETMSG_INFO (id=1)');

        // Second sent message must be NETMSG_REQUEST_MAP_DATA (msgId=19) for chunk 0.
        $this->assertSentMsgId($sent[1], 19, 'Second sent message must be NETMSG_REQUEST_MAP_DATA (id=19)');

        // Third: REQUEST_MAP_DATA for chunk 1.
        self::assertArrayHasKey(2, $sent, 'Must send a third vital message for chunk 1');
        $this->assertSentMsgId($sent[2], 19, 'Third sent message must be NETMSG_REQUEST_MAP_DATA (id=19)');
    }

    // -----------------------------------------------------------------------
    // Test 2 — CRC mismatch → MapDownloadFailed
    // -----------------------------------------------------------------------

    public function testCrcMismatchThrows(): void
    {
        $payload     = $this->makePayload(10);
        $correctCrc  = crc32($payload);
        $wrongCrc    = $correctCrc ^ 0x1234; // flip some bits

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $wrongCrc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $wrongCrc, 0, $payload)]);

        $session = new MapDownloadSession($conn);

        $this->expectException(MapDownloadFailed::class);
        $this->expectExceptionMessageMatches('/CRC32 mismatch/i');
        $session->download(timeoutMs: 5000);
    }

    // -----------------------------------------------------------------------
    // Test 3a — SHA-256 verify: correct hash succeeds
    // -----------------------------------------------------------------------

    public function testSha256CorrectSucceeds(): void
    {
        $payload = $this->makePayload(16);
        $crc     = crc32($payload);
        $sha256  = hash('sha256', $payload);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(expectedSha256: $sha256, timeoutMs: 5000);

        self::assertSame($payload, $result);
    }

    // -----------------------------------------------------------------------
    // Test 3b — SHA-256 wrong → MapDownloadFailed
    // -----------------------------------------------------------------------

    public function testSha256WrongThrows(): void
    {
        $payload     = $this->makePayload(16);
        $crc         = crc32($payload);
        $wrongSha256 = hash('sha256', 'not the right bytes');

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $session = new MapDownloadSession($conn);

        $this->expectException(MapDownloadFailed::class);
        $this->expectExceptionMessageMatches('/SHA-256 mismatch/i');
        $session->download(expectedSha256: $wrongSha256, timeoutMs: 5000);
    }

    // -----------------------------------------------------------------------
    // Test 4 — Truncated / timeout: last never arrives → MapDownloadFailed
    // -----------------------------------------------------------------------

    public function testTruncatedDownloadThrows(): void
    {
        $payload = $this->makePayload(20);
        $crc     = crc32($payload);
        $size    = strlen($payload);

        // Only deliver chunk 0 (last=0); no chunk 1, so the session stalls.
        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, $size)]);
        $conn->enqueue([$this->buildMapData(0, $crc, 0, substr($payload, 0, 10))]);
        // After consuming the scripted messages, receive() will return [] indefinitely
        // (empty inbox), so the session must time out.

        $session = new MapDownloadSession($conn);

        $this->expectException(MapDownloadFailed::class);
        $this->expectExceptionMessageMatches('/timeout/i');
        $session->download(timeoutMs: 1); // 1 ms → immediate timeout
    }

    // -----------------------------------------------------------------------
    // Test 5 — Extended MAP_CHANGE with sha256 raw field
    // -----------------------------------------------------------------------

    public function testExtendedMapChangeWithSha256(): void
    {
        $payload   = $this->makePayload(8);
        $crc       = crc32($payload);
        $sha256Hex = hash('sha256', $payload);
        $sha256Raw = hex2bin($sha256Hex);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChangeExtended('ctf5', $sha256Raw, $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $session = new MapDownloadSession($conn);
        // Pass the expected sha256; session must match it against what was received.
        $result = $session->download(expectedSha256: $sha256Hex, timeoutMs: 5000);

        self::assertSame($payload, $result);
    }

    // -----------------------------------------------------------------------
    // Test 6 — MAP_CHANGE followed by non-MAP_DATA noise before real chunk
    // -----------------------------------------------------------------------

    public function testNonMapDataMessagesAreSkipped(): void
    {
        $payload = $this->makePayload(6);
        $crc     = crc32($payload);

        // Inject a CON_READY (msgId=4) message before the MAP_DATA.
        $conReady = VariableInt::pack((4 << 1) | 1); // NETMSG_CON_READY = 4, no fields

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$conReady]);  // noise
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result);
    }

    // -----------------------------------------------------------------------
    // Test 7 — Single chunk with last=1 (minimal download)
    // -----------------------------------------------------------------------

    public function testSingleChunkDownload(): void
    {
        $payload = 'Hello, Teeworlds!';
        $crc     = crc32($payload);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('tutorial', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result);
    }

    // -----------------------------------------------------------------------
    // Helper: assert the msgId packed in the first VariableInt of a sent message
    // -----------------------------------------------------------------------

    private function assertSentMsgId(string $raw, int $expectedMsgId, string $label): void
    {
        [$typeInt] = VariableInt::unpack($raw, 0);
        $actualMsgId = $typeInt >> 1;
        self::assertSame($expectedMsgId, $actualMsgId, $label);
    }
}

// -----------------------------------------------------------------------
// FakeConnection — scripted ConnectionInterface for tests
// -----------------------------------------------------------------------

/**
 * Lightweight fake for ConnectionInterface.
 *
 * - enqueue(array $messages): push a batch of message byte-strings; each
 *   call to enqueue() represents one receive() call return value.
 * - receive() returns the next queued batch (or [] when inbox is empty).
 * - sent: accumulates every payload passed to sendVital().
 * - disconnected: set to true when disconnect() is called.
 */
final class FakeConnection implements ConnectionInterface
{
    /** @var string[] Raw vital payloads sent by the session. */
    public array $sent = [];

    public bool $disconnected = false;

    /** @var array<int, string[]> */
    private array $inbox = [];

    /**
     * Enqueue a batch of messages to be returned by the next receive() call.
     *
     * @param string[] $messages
     */
    public function enqueue(array $messages): void
    {
        $this->inbox[] = $messages;
    }

    public function sendVital(string $message): void
    {
        $this->sent[] = $message;
    }

    public function flush(): void
    {
        // No-op: in tests, flushing is instant; messages are available to receive() immediately.
    }

    /**
     * @return string[]
     */
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
