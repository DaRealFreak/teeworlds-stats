<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\MapDownloadFailed;
use App\TwStats\Connection\MapDownloadResult;
use App\TwStats\Connection\MapDownloadSession;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MapDownloadSession — driven entirely by fake connections whose
 * receive() returns pre-scripted (or request-driven) message byte-strings;
 * no real UDP socket.
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
     * Build a NETMSG_MAP_DETAILS (DDNet extended) message: NETMSG_EX envelope + the map-details
     * UUID, then name, raw sha256[32], crc, size, and the HTTP download url ("" when none).
     */
    private function buildMapDetails(string $name, string $sha256Raw, int $crc, int $size, string $url): string
    {
        return VariableInt::pack((0 << 1) | 1)                                  // NETMSG_EX, system
            . \App\TwStats\Connection\DdnetUuid::compute('map-details@ddnet.tw') // 16-byte message uuid
            . $name . "\x00"
            . $sha256Raw                                                         // 32 raw bytes
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size)
            . $url . "\x00";
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

        // Verify sent messages. CLIENTVER (id=0, NETMSG_EX) precedes INFO, mirroring the real client.
        $sent = $conn->sent;
        self::assertGreaterThanOrEqual(4, count($sent), 'At least CLIENTVER + INFO + two REQUEST_MAP_DATA must be sent');

        $this->assertSentMsgId($sent[0], 0, 'First sent message must be NETMSG_EX/CLIENTVER (id=0)');
        $this->assertSentMsgId($sent[1], 1, 'Second sent message must be NETMSG_INFO (id=1)');
        $this->assertSentMsgId($sent[2], 19, 'Third sent message must be NETMSG_REQUEST_MAP_DATA (id=19) for chunk 0');
        $this->assertSentMsgId($sent[3], 19, 'Fourth sent message must be NETMSG_REQUEST_MAP_DATA (id=19) for chunk 1');
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
    // Test 4 — Truncated / stall: no progress → MapDownloadFailed
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
        // (empty inbox), so the session must hit the overall deadline and throw.

        $session = new MapDownloadSession($conn);

        $this->expectException(MapDownloadFailed::class);
        $this->expectExceptionMessageMatches('/timeout/i');
        $session->download(timeoutMs: 1); // 1 ms → immediate overall deadline
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
    // Test 8 — 40-chunk windowed download (regression: maps > 15 chunks)
    //
    // The 0.6 server sends SvMapWindow (default 15) chunks per REQUEST_MAP_DATA
    // and waits for the next request before sending more.  A 40-chunk map
    // requires ≥ 3 windows.  This test uses WindowedFakeConnection which models
    // the server-side window behaviour: on each REQUEST_MAP_DATA(n) it enqueues
    // a window of up to SvMapWindow chunks starting at n.
    //
    // Under the OLD single-request behaviour the session would drain the first
    // 15-chunk window and then stall (the inbox would be empty because no fresh
    // REQUEST_MAP_DATA was sent to trigger the next window).  The test would
    // time out — which is exactly the live symptom on 106 KB maps.
    // -----------------------------------------------------------------------

    public function testFortyChunkWindowedDownload(): void
    {
        $chunkSize  = 1024;           // 1 KB per chunk
        $numChunks  = 40;
        $totalSize  = $chunkSize * $numChunks;
        $payload    = $this->makePayload($totalSize);
        $crc        = crc32($payload);

        // Build chunk data array: 40 × 1 KB slices.
        $chunks = [];
        for ($i = 0; $i < $numChunks; $i++) {
            $chunks[$i] = substr($payload, $i * $chunkSize, $chunkSize);
        }

        $mapChange = $this->buildMapChange('bigmap', $crc, $totalSize);

        // WindowedFakeConnection drives the server side: REQUEST_MAP_DATA(n)
        // → enqueue up to SvMapWindow MAP_DATA chunks starting at n.
        $conn = new WindowedFakeConnection(
            mapChange: $mapChange,
            chunks: $chunks,
            crc: $crc,
            windowSize: 15,
            buildMapData: fn (int $last, int $crc2, int $idx, string $data) => $this->buildMapData($last, $crc2, $idx, $data),
        );

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result, '40-chunk windowed download must reassemble to the original payload');
        self::assertTrue($conn->disconnected, 'disconnect() must be called after success');

        // The session must have sent REQUEST_MAP_DATA for every chunk (0..39).
        // INFO is sent[0]; REQUEST_MAP_DATA(0) is sent[1]; REQUEST_MAP_DATA(n) follows each accepted chunk.
        $sent = $conn->sent;
        // sent[0] = CLIENTVER, sent[1] = INFO, sent[2..41] = REQUEST_MAP_DATA(0..39)
        self::assertCount(42, $sent, 'Must send CLIENTVER + INFO + 40 REQUEST_MAP_DATA messages (one per accepted chunk)');
        $this->assertSentMsgId($sent[0], 0, 'sent[0] must be NETMSG_EX/CLIENTVER');
        $this->assertSentMsgId($sent[1], 1, 'sent[1] must be NETMSG_INFO');
        for ($i = 0; $i < $numChunks; $i++) {
            $this->assertSentMsgId($sent[$i + 2], 19, "sent[{$i}+2] must be REQUEST_MAP_DATA");
        }
    }

    // -----------------------------------------------------------------------
    // Test 9 — Out-of-order / duplicate MAP_DATA chunks are ignored
    //
    // The server's send window means the client may receive chunk n+1 before
    // it has requested it (overlap from the previous window).  The session must
    // ignore any chunk whose index != expectedChunk and must not append it to
    // the payload.  The correct chunk arriving afterwards must be accepted and
    // the payload must reassemble correctly.
    // -----------------------------------------------------------------------

    public function testOutOfOrderChunksAreIgnored(): void
    {
        // 3-chunk map: chunks 0, 1, 2 (each 8 bytes).
        $chunkSize = 8;
        $payload   = $this->makePayload($chunkSize * 3);
        $crc       = crc32($payload);
        $c0        = substr($payload, 0, $chunkSize);
        $c1        = substr($payload, $chunkSize, $chunkSize);
        $c2        = substr($payload, $chunkSize * 2);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        // Session requests chunk 0 → server sends chunk 0 (correct) but also
        // erroneously sends chunk 2 (ahead of the window — must be ignored).
        $conn->enqueue([
            $this->buildMapData(0, $crc, 0, $c0),   // accepted: expectedChunk=0 → advance to 1
            $this->buildMapData(0, $crc, 2, $c2),   // ignored:  chunk 2 != expectedChunk 1
        ]);
        // Session re-requests chunk 1 → server sends chunk 1 (correct) and
        // then sends chunk 1 again as a duplicate (must also be ignored).
        $conn->enqueue([
            $this->buildMapData(0, $crc, 1, $c1),   // accepted: expectedChunk=1 → advance to 2
            $this->buildMapData(0, $crc, 1, $c1),   // ignored:  duplicate of chunk 1
        ]);
        // Session re-requests chunk 2 → server sends it correctly.
        $conn->enqueue([
            $this->buildMapData(1, $crc, 2, $c2),   // accepted: last=1 → done
        ]);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result, 'Out-of-order chunks must not corrupt the payload');
    }

    // -----------------------------------------------------------------------
    // Test 10 — MAP_DATA bundled in the SAME batch as MAP_CHANGE
    //
    // Regression for the live fng-class bug: real DDNet/fng servers send
    // MAP_CHANGE and the first MAP_DATA chunk(s) in a SINGLE datagram (and
    // CON_READY + snapshots right behind them). The old waitForMapChange()
    // returned the instant it saw MAP_CHANGE and discarded every other message
    // in that same batch — including the MAP_DATA chunk — so the download then
    // stalled with zero bytes received. The session must instead process the
    // bundled MAP_DATA from the same batch.
    // -----------------------------------------------------------------------

    public function testMapDataBundledWithMapChange(): void
    {
        $payload = $this->makePayload(48);
        $crc     = crc32($payload);

        $conn = new FakeConnection();
        // One datagram carrying MAP_CHANGE, the whole map (chunk 0, last=1),
        // and trailing CON_READY noise — exactly the live server burst.
        $conReady = VariableInt::pack((4 << 1) | 1);
        $conn->enqueue([
            $this->buildMapChange('dummy', $crc, strlen($payload)),
            $this->buildMapData(1, $crc, 0, $payload),
            $conReady,
        ]);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result, 'Bundled MAP_DATA must be reassembled, not discarded');
        self::assertTrue($conn->disconnected, 'disconnect() must be called after success');
    }

    // -----------------------------------------------------------------------
    // Test 11 — Multi-chunk window bundled right after MAP_CHANGE
    //
    // Fast-download servers push a whole window of chunks immediately after
    // MAP_CHANGE in the same burst. All chunks in the bundling batch must be
    // consumed in order.
    // -----------------------------------------------------------------------

    public function testMultiChunkWindowBundledWithMapChange(): void
    {
        $chunkSize = 16;
        $payload   = $this->makePayload($chunkSize * 4);
        $crc       = crc32($payload);

        $conn  = new FakeConnection();
        $batch = [$this->buildMapChange('dummy', $crc, strlen($payload))];
        for ($i = 0; $i < 4; $i++) {
            $last    = ($i === 3) ? 1 : 0;
            $batch[] = $this->buildMapData($last, $crc, $i, substr($payload, $i * $chunkSize, $chunkSize));
        }
        $conn->enqueue($batch);

        $session = new MapDownloadSession($conn);
        $result  = $session->download(timeoutMs: 5000);

        self::assertSame($payload, $result, 'All bundled window chunks must be reassembled in order');
    }

    // -----------------------------------------------------------------------
    // Test 12 — downloadWithIdentity() returns the observed name, crc, size, sha256
    // -----------------------------------------------------------------------

    public function testDownloadWithIdentityReturnsObservedFields(): void
    {
        $payload = $this->makePayload(16);
        $crc     = crc32($payload);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('ctf5', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $result = (new MapDownloadSession($conn))->downloadWithIdentity(timeoutMs: 5000);

        self::assertSame($payload, $result->bytes);
        self::assertSame('ctf5', $result->name);
        self::assertSame($this->toInt32($crc), $result->crc);
        self::assertSame(strlen($payload), $result->size);
        self::assertNull($result->sha256, 'vanilla 0.6 MAP_CHANGE announces no sha256');
    }

    // -----------------------------------------------------------------------
    // Test 13 — CLIENTVER is sent before INFO (look like a real DDNet client)
    // -----------------------------------------------------------------------

    public function testSendsClientVerBeforeInfo(): void
    {
        $payload = $this->makePayload(8);
        $crc     = crc32($payload);

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        (new MapDownloadSession($conn))->download(timeoutMs: 5000);

        // sent[0] = CLIENTVER: NETMSG_EX (id 0, system) carrying the clientver@ddnet.tw UUID.
        $clientVer = $conn->sent[0];
        [$typeInt] = VariableInt::unpack($clientVer, 0);
        self::assertSame(0, $typeInt >> 1, 'CLIENTVER is a NETMSG_EX message (id 0)');
        self::assertSame(1, $typeInt & 1, 'CLIENTVER is a system message');
        self::assertSame(
            \App\TwStats\Connection\DdnetUuid::compute('clientver@ddnet.tw'),
            substr($clientVer, 1, 16),
            'CLIENTVER must carry the clientver@ddnet.tw UUID',
        );
        // sent[1] = INFO
        $this->assertSentMsgId($conn->sent[1], 1, 'INFO must follow CLIENTVER');
    }

    // -----------------------------------------------------------------------
    // Test 14 — MAP_DETAILS with a download URL → MapAvailableViaHttp
    // -----------------------------------------------------------------------

    public function testMapDetailsWithUrlThrowsMapAvailableViaHttp(): void
    {
        $sha256Raw = hex2bin(hash('sha256', 'the map'));
        $url       = 'https://maps.example.org/Custom_' . bin2hex($sha256Raw) . '.map';

        $conn = new FakeConnection();
        // MAP_DETAILS (with URL) arrives just before MAP_CHANGE, as the real server sends it.
        $conn->enqueue([$this->buildMapDetails('Custom', $sha256Raw, 123, 4096, $url)]);

        try {
            (new MapDownloadSession($conn))->download(timeoutMs: 5000);
            self::fail('Expected MapAvailableViaHttp');
        } catch (\App\TwStats\Connection\MapAvailableViaHttp $e) {
            self::assertSame($url, $e->url);
            self::assertSame('Custom', $e->name);
            self::assertSame(bin2hex($sha256Raw), $e->sha256);
            self::assertSame(4096, $e->size);
        }
        self::assertTrue($conn->disconnected, 'session must disconnect before handing off to HTTP');
    }

    // -----------------------------------------------------------------------
    // Test 15 — MAP_DETAILS with an empty URL is ignored; UDP download proceeds
    // -----------------------------------------------------------------------

    public function testMapDetailsWithEmptyUrlIsIgnored(): void
    {
        $payload   = $this->makePayload(16);
        $crc       = crc32($payload);
        $sha256Raw = hex2bin(hash('sha256', $payload));

        $conn = new FakeConnection();
        $conn->enqueue([$this->buildMapDetails('dm1', $sha256Raw, $crc, strlen($payload), '')]); // empty url
        $conn->enqueue([$this->buildMapChange('dm1', $crc, strlen($payload))]);
        $conn->enqueue([$this->buildMapData(1, $crc, 0, $payload)]);

        $result = (new MapDownloadSession($conn))->download(timeoutMs: 5000);

        self::assertSame($payload, $result, 'an empty download URL must not abort the UDP transfer');
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

// -----------------------------------------------------------------------
// WindowedFakeConnection — models the 0.6 server-side map window behaviour
// -----------------------------------------------------------------------

/**
 * Models the 0.6 server windowed MAP_DATA delivery.
 *
 * When the session sends REQUEST_MAP_DATA(n), this fake enqueues a window of
 * up to $windowSize MAP_DATA chunks starting at chunk n.  The first call to
 * receive() after sendVital() drains that window.  A second call when the
 * window is empty (but more chunks remain) returns [] — forcing the session
 * to send a fresh REQUEST_MAP_DATA before it can make progress.
 *
 * This directly exercises the stall bug: a session that sends only one initial
 * request would never advance past the first window and would time out.
 */
final class WindowedFakeConnection implements ConnectionInterface
{
    /** @var string[] Raw vital payloads sent by the session. */
    public array $sent = [];

    public bool $disconnected = false;

    /** @var string[][] Queued receive batches, populated by sendVital(). */
    private array $inbox = [];

    /**
     * Whether the MAP_CHANGE has been returned yet (first receive() call).
     * The connection delivers MAP_CHANGE immediately on the first receive()
     * before any REQUEST_MAP_DATA is sent.
     */
    private bool $mapChangeSent = false;

    /** @param string[] $chunks Pre-split chunk byte-strings (index = chunk number). */
    public function __construct(
        private readonly string $mapChange,
        private readonly array $chunks,
        private readonly int $crc,
        private readonly int $windowSize,
        /** @var callable(int,int,int,string):string */
        private readonly mixed $buildMapData,
    ) {
    }

    public function sendVital(string $message): void
    {
        $this->sent[] = $message;

        // Detect REQUEST_MAP_DATA (msgId=19, packed = (19<<1)|1 = 39).
        // VariableInt::pack(39) = chr(39) for values < 64.
        if (strlen($message) < 1) {
            return;
        }

        [$typeInt] = VariableInt::unpack($message, 0);
        $msgId = $typeInt >> 1;

        if ($msgId !== 19) {
            return;
        }

        // Extract the requested chunk index from the VariableInt after the header.
        $headerLen  = strlen(VariableInt::pack($typeInt));
        [$startChunk] = VariableInt::unpack($message, $headerLen);

        $total     = count($this->chunks);
        $window    = [];
        $end       = min($startChunk + $this->windowSize, $total);

        for ($i = $startChunk; $i < $end; $i++) {
            $isLast   = ($i === $total - 1) ? 1 : 0;
            $window[] = ($this->buildMapData)($isLast, $this->crc, $i, $this->chunks[$i]);
        }

        if ($window !== []) {
            $this->inbox[] = $window;
        }
    }

    public function flush(): void
    {
        // No-op.
    }

    /** @return string[] */
    public function receive(int $timeoutMs): array
    {
        // Deliver MAP_CHANGE on the very first receive() call (before any
        // REQUEST_MAP_DATA is sent — mirrors real server ordering where the
        // server sends MAP_CHANGE unsolicited after INFO).
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
