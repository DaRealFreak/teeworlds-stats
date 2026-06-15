<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Server;
use App\Models\ServerHistory;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\GameConnection;
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Discovery\FlavorClassifier;
use Illuminate\Database\Eloquent\Builder;

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
 * Host selection: we query server_histories for all hosts that recently served this map (or
 * specific version), ordered newest-first, deduplicated by (ip, port), capped at
 * MAP_UDP_MAX_HOSTS. Each host is dialled in turn via downloadFromHost() until one returns
 * verified bytes. Targeting hosts known to have served the exact identity avoids wasting
 * connects on hosts that have moved to a different map/version. downloadFromHost() is public so
 * inline acquisition (scraper) can target the exact host it just saw serving the version.
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
        return $this->downloadFromCandidates(
            ServerHistory::where('map_id', $map->getAttribute('id')),
            $map->getAttribute('sha256'),
            $this->crcToInt32($map->getAttribute('crc')),
            (string) $map->getAttribute('name'),
        );
    }

    /**
     * Version-keyed acquisition path: resolve candidate hosts from server_histories filtered by
     * map_version_id (newest first, deduped, capped), then try each until one serves verified
     * bytes. Returns null on any failure so the store can try the next source.
     */
    public function fetchVersion(MapVersion $version): ?string
    {
        return $this->downloadFromCandidates(
            ServerHistory::where('map_version_id', $version->getAttribute('id')),
            $version->getAttribute('sha256'),
            $this->crcToInt32($version->getAttribute('crc')),
            (string) $version->map->getAttribute('name'),
        );
    }

    /**
     * Download + verify a map from ONE explicit host. Returns the verified .map bytes, or null on any
     * failure (connect, download, name mismatch, non-datafile). Public so inline acquisition can target
     * the exact host it just saw serving the version.
     */
    public function downloadFromHost(string $ip, int $port, string $flavor, ?string $expectedSha256, ?int $expectedCrc, string $expectedName, int $timeoutMs): ?string
    {
        try {
            $factory = $this->connectionFactory ?? $this->defaultFactory();
            $conn    = ($factory)($ip, $port, $flavor);

            $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
                expectedSha256: $expectedSha256,
                expectedCrc: $expectedCrc,
                timeoutMs: $timeoutMs,
            );

            // Name-match gate: a host that has since switched maps answers with a different name;
            // archiving its bytes here would mislabel them. Trim both sides (the stored name may come
            // from the DDNet HTTP feed, the wire name off the UDP MAP_CHANGE).
            if (trim($result->name) !== trim($expectedName)) {
                return null;
            }
            if (!$this->looksLikeMapDatafile($result->bytes)) {
                return null;
            }

            return $result->bytes;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Try the distinct servers that served this identity, most-recently-seen first, capped at
     * MAP_UDP_MAX_HOSTS, until one yields verified bytes. Grouping by server_id (history rows aggregate
     * per server/weekday/hour, so one server owns many rows) guarantees we surface up to N *different*
     * hosts rather than many rows of a single busy server — which is the whole point of the fallback.
     */
    private function downloadFromCandidates(Builder $historyQuery, ?string $sha256, ?int $crc, string $name): ?string
    {
        $serverIds = $historyQuery->getQuery()
            ->select('server_id')
            ->selectRaw('MAX(updated_at) as recent_at')
            ->groupBy('server_id')
            ->orderByDesc('recent_at')
            ->limit($this->maxHosts())
            ->pluck('server_id');

        if ($serverIds->isEmpty()) {
            return null;
        }

        $servers = Server::whereIn('id', $serverIds)->with('addresses')->get()->keyBy('id');

        foreach ($serverIds as $serverId) {
            $server = $servers->get($serverId);
            if ($server === null) {
                continue;
            }
            $host = $this->hostFor($server);
            if ($host === null) {
                continue;
            }
            $bytes = $this->downloadFromHost($host['ip'], $host['port'], $host['flavor'], $sha256, $crc, $name, $this->timeoutMs);
            if ($bytes !== null) {
                return $bytes;
            }
        }

        return null;
    }

    /**
     * The (ip, port, flavor) to dial for a server: protocol 7 for native sixup, else the 0.6 address.
     *
     * @return array{ip: string, port: int, flavor: string}|null
     */
    private function hostFor(Server $server): ?array
    {
        $flavor = $server->getAttribute('flavor') ?? FlavorClassifier::FLAVOR_VANILLA_06;
        $preferredProtocol = $flavor === FlavorClassifier::FLAVOR_VANILLA_07 ? 7 : 6;
        $address = $server->addresses->where('protocol', $preferredProtocol)->first()
            ?? $server->addresses->first();
        if ($address === null) {
            return null;
        }

        return ['ip' => (string) $address->ip, 'port' => (int) $address->port, 'flavor' => $flavor];
    }

    /** Convert an 8-hex crc string to a signed int32 for the download session, or null. */
    private function crcToInt32(?string $crcHex): ?int
    {
        if ($crcHex === null) {
            return null;
        }
        $c = (int) hexdec($crcHex);
        if ($c & 0x80000000) {
            $c -= 0x100000000;
        }

        return $c;
    }

    private function maxHosts(): int
    {
        return (int) env('MAP_UDP_MAX_HOSTS', 5);
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
