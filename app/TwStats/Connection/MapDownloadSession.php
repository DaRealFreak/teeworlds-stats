<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Protocol\Seven\Unpacker;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Drives a Teeworlds 0.6 in-game map download over an established connection.
 *
 * Protocol flow (mirroring ddnet/src/engine/client/client.cpp):
 *
 *  1. Client → NETMSG_INFO  (version string + password)
 *  2. Server → NETMSG_MAP_CHANGE  (name, [sha256,] crc, size)
 *  3. Client → NETMSG_REQUEST_MAP_DATA(0)
 *  4. Server → NETMSG_MAP_DATA (last, crc, chunk, size, data…)
 *     … repeat 3–4 until last == 1 or total bytes == announced size
 *
 * Important real-server behaviour (verified live against vanilla/DDNet/fng 0.6
 * servers): the server frequently bundles MAP_CHANGE and the first MAP_DATA
 * chunk(s) — and even CON_READY — into a SINGLE datagram, and fast-download
 * servers push a whole window of chunks right behind MAP_CHANGE.  download()
 * therefore processes every message of every receive() batch uniformly; it must
 * not return the instant MAP_CHANGE is seen (that discards the bundled chunks
 * and stalls the download — the historical fng-class symptom).
 *
 * Message framing: every NETMSG payload begins with a VariableInt whose value
 * encodes both the message ID and the system flag:
 *
 *   packed_int = (msgId << 1) | system_flag   (system_flag = 1 for system msgs)
 *
 * Confirmed NETMSG numbers from ddnet/src/engine/shared/protocol.h:
 *   NETMSG_EX              = 0
 *   NETMSG_INFO            = 1  (client → server)
 *   NETMSG_MAP_CHANGE      = 2  (server → client)
 *   NETMSG_MAP_DATA        = 3  (server → client)
 *   NETMSG_CON_READY       = 4  (server → client)
 *   NETMSG_REQUEST_MAP_DATA = 19 (client → server)
 *
 * NETMSG_MAP_CHANGE fields (0.6 vanilla, client.cpp ~1680):
 *   string mapName, int crc, int size
 *   (extended variant with sha256 is detected via MapDetails on the DDNet
 *   client side; in our scraper context the server sends the vanilla form,
 *   but we accept a 32-byte sha256 raw field between mapName and crc when
 *   the remaining bytes are long enough — matching the DDNet server_data path)
 *
 * NETMSG_MAP_DATA fields (client.cpp ~1783–1786):
 *   int last, int crc, int chunk, int size, raw data[size]
 *
 * NETMSG_INFO fields (client.cpp ~255–258):
 *   string version ("0.6 626fce9a778df4d4"), string password ("")
 *
 * CRC: Teeworlds servers use standard CRC32. PHP's crc32() returns a signed
 * 32-bit integer; the server packs an int (also signed). Comparison is done
 * after normalising both sides to signed 32-bit.
 *
 * Live-verified notes:
 *   - Extended MAP_CHANGE sha256 field detection (sniff remaining() after the
 *     name to decide vanilla vs extended) holds against real DDNet/fng servers.
 *   - DDNet/fng 0.6 servers serve maps over the classic 0.6 wire (they reject
 *     0.7 connections); GameConnection routes FLAVOR_DDNET through 0.6 for this
 *     reason.  A pure 0.7 (sixup) server streams MAP_DATA without per-chunk
 *     requests and would still need a separate session variant.
 */
final class MapDownloadSession implements MapDownloadSessionInterface
{
    use MapDownloadVerification;

    // -----------------------------------------------------------------------
    // Protocol constants confirmed from protocol.h
    // -----------------------------------------------------------------------

    /** NETMSG_INFO = 1 */
    private const NETMSG_INFO = 1;

    /** NETMSG_MAP_CHANGE = 2 */
    private const NETMSG_MAP_CHANGE = 2;

    /** NETMSG_MAP_DATA = 3 */
    private const NETMSG_MAP_DATA = 3;

    /** NETMSG_REQUEST_MAP_DATA = 19 */
    private const NETMSG_REQUEST_MAP_DATA = 19;

    /** NETMSG_EX = 0 — the envelope for DDNet UUID-extended messages (CLIENTVER, MAP_DETAILS). */
    private const NETMSG_EX = 0;

    /**
     * DDNET_VERSION_NUMBER / GAME_RELEASE_VERSION from ddnet/src/game/version.h. Sent in NETMSG_CLIENTVER
     * so we present as a current, real DDNet client — anti-bot servers gate the in-game session on this.
     */
    private const DDNET_VERSION = 20000;
    private const DDNET_VERSION_STR = 'DDNet 20.0';

    /**
     * 0.6 GAME_NETVERSION from ddnet/src/game/version.h.
     * Confirmed: "0.6 626fce9a778df4d4" (not the string in the task spec).
     */
    private const GAME_NETVERSION = '0.6 626fce9a778df4d4';

    /**
     * SHA-256 raw bytes length (32 bytes) used in the extended MAP_CHANGE.
     */
    private const SHA256_BYTES = 32;

    // -----------------------------------------------------------------------
    // Constructor
    // -----------------------------------------------------------------------

    /** m_ConnectionId in CClient::SendInfo: a random per-connection UUID echoed in CLIENTVER. */
    private readonly string $connectionId;

    public function __construct(private readonly ConnectionInterface $conn, ?string $connectionId = null)
    {
        $this->connectionId = $connectionId ?? self::randomUuidBytes();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Stall window: if no new chunk is accepted within this many milliseconds
     * the download is considered hung and a MapDownloadFailed is thrown.  This
     * lets large maps download as long as the server keeps sending progress,
     * while still failing fast when the peer goes silent.
     */
    private const STALL_WINDOW_MS = 8000;

    /**
     * Download and return only the raw map bytes. Thin wrapper over downloadWithIdentity() for
     * callers that do not need the observed identity (preserves the original return contract).
     */
    public function download(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): string {
        return $this->downloadWithIdentity($expectedSha256, $expectedCrc, $timeoutMs)->bytes;
    }

    /**
     * Execute the in-game map download and return the raw map bytes together with the
     * identity the server announced in MAP_CHANGE.
     *
     * @param string|null $expectedSha256  Hex SHA-256 to verify against (optional).
     * @param int|null    $expectedCrc     CRC32 to verify against (optional, in addition
     *                                     to the server-announced CRC).
     * @param int         $timeoutMs       Overall hard-cap deadline in milliseconds.
     *                                     A stall window (STALL_WINDOW_MS) also applies:
     *                                     the download fails if no chunk is accepted for
     *                                     that duration even before the overall cap.
     *
     * @return MapDownloadResult  Verified bytes plus the server-announced identity.
     * @throws MapDownloadFailed  On timeout, malformed messages, or integrity failure.
     */
    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
        bool $ignoreAdvertisedHttp = false,
    ): MapDownloadResult {
        $now      = hrtime(true) / 1_000_000;
        $deadline = $now + $timeoutMs;

        // Step 1 — Identify as a real DDNet client (NETMSG_CLIENTVER), then send NETMSG_INFO.
        // Many DDNet servers gate the in-game session on a valid clientver (an anti-bot "known
        // client" check) and stay silent on INFO otherwise; the real client always sends it first.
        $this->conn->sendVital($this->buildClientVer());
        $this->conn->sendVital($this->buildInfo());
        $this->conn->flush();

        // Step 2–4 — One uniform receive loop over the whole message stream.
        //
        // MAP_CHANGE and the first MAP_DATA chunk(s) routinely arrive in the SAME
        // datagram: DDNet/fng servers bundle MAP_CHANGE + the map + CON_READY in
        // one burst, and fast-download servers push a whole window of chunks right
        // behind MAP_CHANGE.  Returning the instant MAP_CHANGE is seen would
        // discard every later message in that batch — including the bundled
        // MAP_DATA — leaving the download stalled at zero bytes (the live
        // fng-class symptom).  We therefore process every message of every batch
        // in order, handling MAP_CHANGE and MAP_DATA as they come.
        $haveMapChange = false;
        $mapCrc        = 0;
        $mapSize       = 0;
        $mapSha256     = null;
        $mapName       = '';
        $payload       = '';
        $expectedChunk = 0;
        $stallDeadline = $now + self::STALL_WINDOW_MS;

        while (true) {
            $now       = hrtime(true) / 1_000_000;
            $remaining = (int) min($deadline - $now, $stallDeadline - $now);

            if ($remaining <= 0) {
                if (!$haveMapChange) {
                    throw new MapDownloadFailed('Timeout waiting for NETMSG_MAP_CHANGE');
                }
                if ($stallDeadline - $now <= 0) {
                    throw new MapDownloadFailed('Timeout during map data transfer (no progress)');
                }
                throw new MapDownloadFailed('Timeout during map data transfer');
            }

            $messages = $this->conn->receive((int) min($remaining, 200));

            foreach ($messages as $msg) {
                if (!$haveMapChange) {
                    // A DDNet server announces an HTTP download URL for the map in NETMSG_MAP_DETAILS,
                    // sent just before MAP_CHANGE. Fast-download servers serve their (often custom) maps
                    // from that mirror instead of streaming MAP_DATA over UDP, so when a non-empty URL is
                    // offered we abandon the UDP transfer and let the caller fetch + verify it over HTTP.
                    // $ignoreAdvertisedHttp is the caller's fallback after that HTTP fetch failed (e.g. a
                    // Cloudflare-blocked mirror): mirror the real client's "http failed, falling back to
                    // gameserver" path and keep streaming MAP_DATA over UDP instead of bailing here.
                    if (!$ignoreAdvertisedHttp) {
                        $details = $this->parseMapDetails($msg);
                        if ($details !== null && $details['url'] !== '') {
                            $this->conn->disconnect();

                            throw new MapAvailableViaHttp($details['url'], $details['sha256'], $details['size'], $details['name']);
                        }
                    } elseif ($this->parseMapDetails($msg) !== null) {
                        // MAP_DETAILS carries no MAP_CHANGE payload; skip it and wait for MAP_CHANGE.
                        continue;
                    }

                    $parsed = $this->parseMapChange($msg);
                    if ($parsed === null) {
                        // Before MAP_CHANGE, ignore everything else.  We cannot
                        // validate a MAP_DATA chunk without the announced crc/size,
                        // and the reliable layer guarantees MAP_CHANGE is delivered
                        // before its data chunks.
                        continue;
                    }

                    [$mapName, $mapCrc, $mapSize, $mapSha256] = $parsed;
                    $haveMapChange = true;
                    $payload       = '';
                    $expectedChunk = 0;
                    $stallDeadline = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;

                    // Drive the transfer by requesting chunk 0, mirroring the DDNet
                    // client which sends REQUEST_MAP_DATA(0) immediately on
                    // MAP_CHANGE.  Servers that already pushed chunk 0 in this same
                    // burst simply ignore the duplicate request; servers that wait
                    // for a request need it.
                    $this->sendRequestMapData(0);

                    continue;
                }

                $chunk = $this->parseMapData($msg);
                if ($chunk === null) {
                    continue;
                }

                ['last' => $last, 'chunk' => $idx, 'data' => $data] = $chunk;

                // Ignore chunks outside the expected sequence position (duplicates
                // or overlap from the server's previous send window).
                if ($idx !== $expectedChunk) {
                    continue;
                }

                $payload      .= $data;
                $expectedChunk++;
                $stallDeadline = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;

                if ($last === 1 || strlen($payload) >= $mapSize) {
                    // Step 5 — Integrity checks, then close.
                    $this->verifyIntegrity($payload, $mapSize, $mapCrc, $mapSha256, $expectedSha256, $expectedCrc);
                    $this->conn->disconnect();

                    return new MapDownloadResult($payload, $mapName, $mapCrc, $mapSize, $mapSha256);
                }

                // Advance the window: request the next chunk (drives both the
                // vanilla per-chunk flow and DDNet fast-download windowing).
                $this->sendRequestMapData($expectedChunk);
            }

            // Pump the reliable layer: an unacked vital chunk (the initial INFO, or a map-data
            // request) is retransmitted once it is overdue. Without this, a single dropped packet on
            // a lossy/high-latency link (e.g. distant servers) deadlocks the download — we would wait
            // forever for a reply to a request the server never received. flush() is a no-op when
            // nothing is due, so this only costs work when a retransmit is actually owed.
            $this->conn->flush();
        }
    }

    // -----------------------------------------------------------------------
    // Build outgoing messages
    // -----------------------------------------------------------------------

    /**
     * Build a NETMSG_INFO payload: (NETMSG_INFO<<1)|1, version\0, password\0.
     */
    private function buildInfo(): string
    {
        $header  = VariableInt::pack((self::NETMSG_INFO << 1) | 1);
        $version = self::GAME_NETVERSION . "\x00";
        $password = "\x00"; // empty string NUL-terminated

        return $header . $version . $password;
    }

    /**
     * Build a NETMSG_CLIENTVER ("clientver@ddnet.tw") payload — a system extended (UUID) message:
     * (NETMSG_EX<<1)|1, the 16-byte message UUID, then the body the real client sends in
     * CClient::SendInfo: connection id (16 bytes), DDNet version int, NUL-terminated version string.
     */
    private function buildClientVer(): string
    {
        return VariableInt::pack((self::NETMSG_EX << 1) | 1)
            . DdnetUuid::compute('clientver@ddnet.tw')
            . $this->connectionId
            . VariableInt::pack(self::DDNET_VERSION)
            . self::DDNET_VERSION_STR . "\x00";
    }

    /**
     * Try to parse msg as NETMSG_MAP_DETAILS ("map-details@ddnet.tw"). Returns null if it is not that
     * extended message or is malformed.
     *
     * Field order (server.cpp SendMap): packed NETMSG_EX, 16-byte uuid, string name, raw sha256[32],
     * int crc, int size, string url ("" when the server has no HTTP mirror).
     *
     * @return array{name: string, sha256: string, size: int, url: string}|null
     */
    private function parseMapDetails(string $msg): ?array
    {
        $u = new Unpacker($msg);

        $typeInt = $u->getInt();
        // a system extended message is NETMSG_EX (id 0) with the system flag set.
        if ($u->error() || ($typeInt >> 1) !== self::NETMSG_EX || ($typeInt & 1) !== 1) {
            return null;
        }

        $uuid = $u->getRaw(16);
        if ($u->error() || $uuid !== DdnetUuid::compute('map-details@ddnet.tw')) {
            return null;
        }

        $name = $u->getString();
        $sha  = $u->getRaw(self::SHA256_BYTES);
        $u->getInt();  // crc (already known from MAP_CHANGE / our request)
        $size = $u->getInt();
        $url  = $u->getString();
        if ($u->error()) {
            return null;
        }

        return ['name' => $name, 'sha256' => bin2hex($sha), 'size' => $size, 'url' => $url];
    }

    /** A random 16-byte v4 UUID for the per-connection client id (mirrors RandomUuid()). */
    private static function randomUuidBytes(): string
    {
        $u = random_bytes(16);
        $u[6] = chr((ord($u[6]) & 0x0f) | 0x40); // version 4
        $u[8] = chr((ord($u[8]) & 0x3f) | 0x80); // variant 1

        return $u;
    }

    /**
     * Build a NETMSG_REQUEST_MAP_DATA payload: (19<<1)|1, chunkIndex.
     */
    private function buildRequestMapData(int $chunk): string
    {
        $header = VariableInt::pack((self::NETMSG_REQUEST_MAP_DATA << 1) | 1);
        $chunkV = VariableInt::pack($chunk);

        return $header . $chunkV;
    }

    // -----------------------------------------------------------------------
    // Parse MAP_CHANGE
    // -----------------------------------------------------------------------

    /**
     * Try to parse msg as NETMSG_MAP_CHANGE.
     * Returns null if the message is not MAP_CHANGE or is malformed.
     *
     * Field order (vanilla 0.6):
     *   packed_int (msgId/system), string name, int crc, int size
     *
     * Extended (DDNet MAP_CHANGE):
     *   packed_int, string name, raw sha256[32], int crc, int size
     *
     * Detection heuristic: after reading the name we check remaining bytes.
     * A vanilla MAP_CHANGE has exactly two ints left; an extended one has
     * 32 raw sha256 bytes + two ints. We use remaining() > (estimated vanilla
     * size) as the signal. In practice we try to read the sha256 when there
     * are at least 32 + 2 bytes remaining after the name.
     *
     * @return array{string, int, int, string|null}|null
     */
    private function parseMapChange(string $msg): ?array
    {
        $u = new Unpacker($msg);

        $typeInt = $u->getInt();
        if ($u->error()) {
            return null;
        }

        $msgId  = $typeInt >> 1;
        $system = $typeInt & 1;

        if ($msgId !== self::NETMSG_MAP_CHANGE || $system !== 1) {
            return null;
        }

        $name = $u->getString();
        if ($u->error()) {
            return null;
        }

        // Detect extended MAP_CHANGE: if there are at least 32+2 bytes remaining
        // (sha256 + two minimal VariableInts) we try to read a sha256.
        // The minimum remaining for vanilla is 2 bytes (two single-byte VarInts).
        $sha256Hex = null;
        if ($u->remaining() >= self::SHA256_BYTES + 2) {
            $sha256Raw = $u->getRaw(self::SHA256_BYTES);
            if (!$u->error()) {
                $sha256Hex = bin2hex($sha256Raw);
            }
        }

        $crc  = $u->getInt();
        $size = $u->getInt();

        if ($u->error()) {
            return null;
        }

        if ($size < 0) {
            return null;
        }

        return [$name, $crc, $size, $sha256Hex];
    }

    // -----------------------------------------------------------------------
    // Request MAP_DATA
    // -----------------------------------------------------------------------

    private function sendRequestMapData(int $chunk): void
    {
        $this->conn->sendVital($this->buildRequestMapData($chunk));
        $this->conn->flush();
    }

    /**
     * Try to parse msg as NETMSG_MAP_DATA.
     * Returns null if not MAP_DATA or malformed.
     *
     * Field order (0.6, client.cpp ~1783–1786):
     *   packed_int, int last, int crc, int chunk, int size, raw data[size]
     *
     * @return array{last:int, crc:int, chunk:int, data:string}|null
     */
    private function parseMapData(string $msg): ?array
    {
        $u = new Unpacker($msg);

        $typeInt = $u->getInt();
        if ($u->error()) {
            return null;
        }

        $msgId  = $typeInt >> 1;
        $system = $typeInt & 1;

        if ($msgId !== self::NETMSG_MAP_DATA || $system !== 1) {
            return null;
        }

        $last  = $u->getInt();
        $crc   = $u->getInt();
        $chunk = $u->getInt();
        $size  = $u->getInt();

        if ($u->error() || $size <= 0) {
            return null;
        }

        $data = $u->getRaw($size);
        if ($u->error()) {
            return null;
        }

        return ['last' => $last, 'crc' => $crc, 'chunk' => $chunk, 'data' => $data];
    }

}
