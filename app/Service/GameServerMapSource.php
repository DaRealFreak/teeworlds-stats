<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\ServerHistory;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\GameConnection;
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Discovery\FlavorClassifier;

/**
 * Downloads a .map file directly from a live Teeworlds game server over UDP using the in-game
 * map-download protocol (0.6 or 0.7, selected by flavour). Invoked by MapFileStore when HTTP
 * delivery (DdnetHttpMapSource) did not serve the map — i.e. maps with no HTTP-accessible mirror
 * (vanilla 0.6/0.7) and keyless maps for which no sha256 is known yet.
 *
 * A connected ConnectionInterface is obtained from the injected $connectionFactory;
 * the default factory builds a real GameConnection (SocketUdpTransport) so no wrapper
 * is needed in production. Tests inject a factory that returns a scripted FakeConnection,
 * avoiding any real UDP traffic.
 *
 * Host selection: we query the most-recently-updated server_histories row for this map,
 * resolve the server's address by preferred protocol (7 for vanilla_07, 6 otherwise), and
 * route to the correct download session via MapDownloadSessionFactory — SevenMapDownloadSession
 * for native sixup hosts (MAP_DATA streams without per-chunk requests), MapDownloadSession for
 * 0.6/DDNet hosts (MAP_DATA includes per-chunk framing).
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

        $flavor = $server->getAttribute('flavor') ?? FlavorClassifier::FLAVOR_VANILLA_06;

        // Dial the address that matches the flavour's wire: protocol 7 for native sixup, else 0.6.
        $preferredProtocol = $flavor === FlavorClassifier::FLAVOR_VANILLA_07 ? 7 : 6;
        $address = $server->addresses->where('protocol', $preferredProtocol)->first()
            ?? $server->addresses->first();

        if ($address === null) {
            return null;
        }

        $ip   = $address->ip;
        $port = $address->port;

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

        $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
            expectedSha256: $sha256,
            expectedCrc: $expectedCrc,
            timeoutMs: $this->timeoutMs,
        );

        // Name-match gate: the source picked a host from server_histories; if that host has since
        // switched maps it answers with a different name, and archiving its bytes as this map would
        // mislabel them. Reject the mismatch (the map is retried after the cooldown). Both sides are
        // trimmed because the stored name may have been recorded from the DDNet HTTP feed while this
        // name comes off the UDP MAP_CHANGE — tolerate that cross-source whitespace, not a real diff.
        if (trim($result->name) !== trim((string) $map->getAttribute('name'))) {
            return null;
        }

        // Datafile sanity: reject placeholder/troll content that is not a real Teeworlds map
        // (mirrors resources/assets/js/map/datafile.ts: 'DATA'/'ATAD' magic, ≥36 bytes).
        if (!$this->looksLikeMapDatafile($result->bytes)) {
            return null;
        }

        return $result->bytes;
    }

    /**
     * Whether the bytes are a Teeworlds map datafile: a 4-byte 'DATA'/'ATAD' magic and at least the
     * 36-byte header (4 magic + 7×4 header ints). Guards against archiving placeholder maps a swapped
     * host might still answer with.
     */
    private function looksLikeMapDatafile(string $bytes): bool
    {
        if (strlen($bytes) < 36) {
            return false;
        }
        $magic = substr($bytes, 0, 4);

        return $magic === 'DATA' || $magic === 'ATAD';
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
