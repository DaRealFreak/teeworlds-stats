<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Protocol\Seven\Unpacker;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Drives a native Teeworlds 0.7 (sixup) in-game map download. Verified against
 * teeworlds/src/engine/server/server.cpp:
 *
 *  1. Client → NETMSG_INFO (version "0.7 802f1be60a05665f", password, client-version int)
 *  2. Server → NETMSG_MAP_CHANGE (name, crc, size, chunksPerRequest, chunkSize, raw sha256[32])
 *  3. Client → NETMSG_REQUEST_MAP_DATA (no chunk arg — the server tracks its own counter)
 *  4. Server → up to chunksPerRequest × NETMSG_MAP_DATA (RAW BYTES ONLY, no per-chunk framing)
 *     … client re-requests after each window until bytes received ≥ size (there is no last flag).
 *
 * MAP_CHANGE and the first window may arrive bundled in one burst, so every message of every
 * receive() batch is processed uniformly (mirrors the 0.6 session's anti-stall handling).
 */
final class SevenMapDownloadSession implements MapDownloadSessionInterface
{
    use MapDownloadVerification;

    private const NETMSG_INFO = 1;
    private const NETMSG_MAP_CHANGE = 2;
    private const NETMSG_MAP_DATA = 3;
    private const NETMSG_REQUEST_MAP_DATA = 23;

    /** Must exactly equal the server's GAME_NETVERSION (version.h) or the server drops us. */
    private const GAME_NETVERSION = '0.7 802f1be60a05665f';

    private const SHA256_BYTES = 32;

    private const STALL_WINDOW_MS = 8000;

    public function __construct(private readonly ConnectionInterface $conn)
    {
    }

    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
        // 0.7/sixup servers stream raw MAP_DATA and never advertise an HTTP mirror, so there is no
        // MapAvailableViaHttp to suppress here — the flag exists only for interface parity with 0.6.
        bool $ignoreAdvertisedHttp = false,
    ): MapDownloadResult {
        $now      = hrtime(true) / 1_000_000;
        $deadline = $now + $timeoutMs;

        $this->conn->sendVital($this->buildInfo());
        $this->conn->flush();

        $haveMapChange    = false;
        $name             = '';
        $crc              = 0;
        $size             = 0;
        $chunksPerRequest = 1;
        $sha256Hex        = null;
        $payload          = '';
        $chunksInWindow   = 0;
        $stallDeadline    = $now + self::STALL_WINDOW_MS;

        while (true) {
            $now       = hrtime(true) / 1_000_000;
            $remaining = (int) min($deadline - $now, $stallDeadline - $now);
            if ($remaining <= 0) {
                if (!$haveMapChange) {
                    throw new MapDownloadFailed('Timeout waiting for 0.7 NETMSG_MAP_CHANGE');
                }
                if ($stallDeadline - $now <= 0) {
                    throw new MapDownloadFailed('Timeout during 0.7 map data transfer (no progress)');
                }
                throw new MapDownloadFailed('Timeout during 0.7 map data transfer');
            }

            $messages = $this->conn->receive((int) min($remaining, 200));

            foreach ($messages as $msg) {
                if (!$haveMapChange) {
                    $parsed = $this->parseMapChange($msg);
                    if ($parsed === null) {
                        continue;
                    }

                    [$name, $crc, $size, $chunksPerRequest, , $sha256Hex] = $parsed;
                    $haveMapChange  = true;
                    $payload        = '';
                    $chunksInWindow = 0;
                    $stallDeadline  = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;
                    $this->requestMapData();

                    continue;
                }

                $data = $this->parseMapData($msg);
                if ($data === null) {
                    continue;
                }

                $payload       .= $data;
                $chunksInWindow++;
                $stallDeadline  = hrtime(true) / 1_000_000 + self::STALL_WINDOW_MS;

                // Completion is checked before the re-request below, so we never ask for a window past
                // the last chunk (the 0.7 server tracks its own counter and would otherwise be confused).
                if (strlen($payload) >= $size) {
                    $this->verifyIntegrity($payload, $size, $crc, $sha256Hex, $expectedSha256, $expectedCrc);
                    $this->conn->disconnect();

                    return new MapDownloadResult($payload, $name, $crc, $size, $sha256Hex);
                }

                // End of a server window → ask for the next one (the server waits for this).
                if ($chunksInWindow >= $chunksPerRequest) {
                    $chunksInWindow = 0;
                    $this->requestMapData();
                }
            }
        }
    }

    private function buildInfo(): string
    {
        return VariableInt::pack((self::NETMSG_INFO << 1) | 1)
            . self::GAME_NETVERSION . "\x00"
            . "\x00"                       // empty password
            . VariableInt::pack(0);        // client-version int (server stores it, does not gate on it)
    }

    private function requestMapData(): void
    {
        // 0.7 REQUEST_MAP_DATA carries no chunk index; the server advances its own per-client counter.
        $this->conn->sendVital(VariableInt::pack((self::NETMSG_REQUEST_MAP_DATA << 1) | 1));
        $this->conn->flush();
    }

    /**
     * @return array{string, int, int, int, int, string}|null  [name, crc, size, chunksPerRequest, chunkSize, sha256Hex]
     */
    private function parseMapChange(string $msg): ?array
    {
        $u       = new Unpacker($msg);
        $typeInt = $u->getInt();
        if ($u->error() || ($typeInt >> 1) !== self::NETMSG_MAP_CHANGE || ($typeInt & 1) !== 1) {
            return null;
        }

        $name             = $u->getString();
        $crc              = $u->getInt();
        $size             = $u->getInt();
        $chunksPerRequest = $u->getInt();
        $chunkSize        = $u->getInt();
        $sha256Raw        = $u->getRaw(self::SHA256_BYTES);

        if ($u->error() || $size < 0 || $chunksPerRequest < 1) {
            return null;
        }

        return [$name, $crc, $size, $chunksPerRequest, $chunkSize, bin2hex($sha256Raw)];
    }

    /**
     * 0.7 MAP_DATA is the message header followed by raw map bytes only.
     */
    private function parseMapData(string $msg): ?string
    {
        $u       = new Unpacker($msg);
        $typeInt = $u->getInt();
        if ($u->error() || ($typeInt >> 1) !== self::NETMSG_MAP_DATA || ($typeInt & 1) !== 1) {
            return null;
        }

        $data = $u->getRaw($u->remaining());

        // A real MAP_DATA chunk always carries at least one byte; treat an empty/garbage one as
        // non-MAP_DATA so the loop ignores it instead of counting a no-progress window step.
        if ($u->error() || $data === '') {
            return null;
        }

        return $data;
    }
}
