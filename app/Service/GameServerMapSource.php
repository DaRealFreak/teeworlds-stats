<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\ServerHistory;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\GameConnection;
use App\TwStats\Connection\MapDownloadSession;
use App\TwStats\Net\SocketUdpTransport;

/**
 * Downloads a .map file directly from a live Teeworlds game server over UDP using the
 * in-game map-download protocol (0.6). Only invoked by MapFileStore when HTTP delivery
 * (DdnetHttpMapSource) did not serve the map, so this path exclusively handles non-DDNet
 * servers that have no HTTP-accessible mirror.
 *
 * A connected ConnectionInterface is obtained from the injected $connectionFactory;
 * the default factory builds a real GameConnection (SocketUdpTransport) so no wrapper
 * is needed in production. Tests inject a factory that returns a scripted FakeConnection,
 * avoiding any real UDP traffic.
 *
 * Host selection: we query the most-recently-updated server_histories row for this map,
 * resolve the server's 0.6 address (protocol = 6), and derive the flavor from the server's
 * version column. We prefer 0.6 because MapDownloadSession implements the 0.6 request-per-
 * chunk flow; 0.7 servers stream MAP_DATA without per-chunk requests and would need a
 * separate session variant.
 */
final class GameServerMapSource implements MapSource
{
    /**
     * @param callable|null $connectionFactory fn(string $ip, int $port, string $flavor): ConnectionInterface
     *                                          Must return an ALREADY CONNECTED connection.
     */
    public function __construct(
        private readonly mixed $connectionFactory = null,
        private readonly int $timeoutMs = 20000,
    ) {
    }

    public function fetch(Map $map): ?string
    {
        try {
            return $this->doFetch($map);
        } catch (\Throwable) {
            return null;
        }
    }

    private function doFetch(Map $map): ?string
    {
        $crcHex = $map->getAttribute('crc');
        $sha256 = $map->getAttribute('sha256');

        // Nothing to verify against — skip.
        if ($crcHex === null && $sha256 === null) {
            return null;
        }

        // Find the most recently seen server that was hosting this map.
        $history = ServerHistory::where('map_id', $map->getAttribute('id'))
            ->orderByDesc('updated_at')
            ->with(['server.addresses'])
            ->first();

        if ($history === null) {
            return null;
        }

        $server = $history->server;
        if ($server === null) {
            return null;
        }

        // Prefer a 0.6 address; MapDownloadSession implements the 0.6 per-chunk protocol.
        $address = $server->addresses
            ->where('protocol', 6)
            ->first();

        // Fall back to any address when no 0.6 address exists (best-effort).
        if ($address === null) {
            $address = $server->addresses->first();
        }

        if ($address === null) {
            return null;
        }

        $ip     = $address->ip;
        $port   = $address->port;
        $flavor = $server->getAttribute('flavor') ?? 'vanilla_06';

        // Convert the 8-hex crc string to a signed int32 for the session.
        $expectedCrc = null;
        if ($crcHex !== null) {
            $c = (int) hexdec($crcHex);
            if ($c & 0x80000000) {
                $c -= 0x100000000;
            }
            $expectedCrc = $c;
        }

        $factory = $this->connectionFactory ?? $this->defaultFactory();
        $conn    = ($factory)($ip, $port, $flavor);

        return (new MapDownloadSession($conn))->download(
            expectedSha256: $sha256,
            expectedCrc: $expectedCrc,
            timeoutMs: $this->timeoutMs,
        );
    }

    /**
     * Build the default factory that creates and connects a real GameConnection.
     * Separated so tests can inject a scripted factory without touching the real UDP path.
     */
    private function defaultFactory(): callable
    {
        return static function (string $ip, int $port, string $flavor): ConnectionInterface {
            $conn = new GameConnection(new SocketUdpTransport(), $ip, $port, $flavor);
            $conn->connect();

            return $conn;
        };
    }
}
