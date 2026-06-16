<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\Server;
use App\Models\ServerHistory;
use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\GameConnection;
use App\Events\MapAcquisitionAttempted;
use App\TwStats\Connection\ConnectionFailed;
use App\TwStats\Connection\MapAvailableViaHttp;
use App\TwStats\Connection\MapDownloadFailed;
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Net\SocketUdpTransport;
use App\TwStats\Discovery\FlavorClassifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

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
 * Host selection: we query server_histories for the hosts seen serving this map (or specific
 * version) within the live window, ordered newest-first, deduplicated by server, capped at
 * MAP_UDP_MAX_HOSTS. Each host is dialled in turn via downloadFromHost() until one returns
 * verified bytes. The live-window gate keeps us from wasting a connect + download on a host that
 * has since cycled to a different map (its history row would be stale) — that only ever produces a
 * name/crc mismatch. downloadFromHost() is public so inline acquisition (scraper) can target the
 * exact host it just saw serving the version.
 */
final class GameServerMapSource implements MapSource
{
    /** Hard cap on a map fetched from a server-advertised HTTP mirror (real maps are a few MB). */
    private const MAX_MAP_BYTES = 64 * 1024 * 1024;

    /** Overall map-download deadline. The transfer may run this long as long as it keeps making
     *  progress (a chunk at least every MapDownloadSession::STALL_WINDOW_MS); generous because vanilla
     *  0.6 servers serve one ~900-byte chunk per round-trip, so distant/large maps are slow but real. */
    private readonly int $timeoutMs;

    /**
     * @param callable|null $connectionFactory fn(string $ip, int $port, string $flavor): ConnectionInterface
     *                                          Must return an ALREADY CONNECTED connection.
     * @param int|null $timeoutMs explicit overall deadline in ms; null reads MAP_UDP_TIMEOUT_S (seconds, default 120).
     */
    public function __construct(
        private readonly mixed $connectionFactory = null,
        ?int $timeoutMs = null,
    ) {
        $this->timeoutMs = $timeoutMs ?? ((int) env('MAP_UDP_TIMEOUT_S', 120)) * 1000;
    }

    public function fetch(Map $map): ?string
    {
        return $this->downloadFromCandidates(
            ServerHistory::where('map_id', $map->getAttribute('id')),
            $map->getAttribute('sha256'),
            $this->crcToInt32($map->getAttribute('crc')),
            (string) $map->getAttribute('name'),
            null,
            (int) $map->getAttribute('id'),
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
            (int) $version->getAttribute('id'),
            (int) $version->getAttribute('map_id'),
        );
    }

    /**
     * Download + verify a map from ONE explicit host. Returns the verified .map bytes, or null on any
     * failure (connect, download, name mismatch, non-datafile). Public so inline acquisition can target
     * the exact host it just saw serving the version.
     *
     * The trailing $serverId and $mapVersionId args are optional so existing direct-call sites (inline
     * acquisition, tests) continue to compile with the original 7-arg form — they fire null-context
     * events which the listener stores with null FKs (harmless for auditing purposes).
     */
    public function downloadFromHost(string $ip, int $port, string $flavor, ?string $expectedSha256, ?int $expectedCrc, string $expectedName, int $timeoutMs, ?int $serverId = null, ?int $mapVersionId = null): ?string
    {
        try {
            return $this->runSession($ip, $port, $flavor, $expectedSha256, $expectedCrc, $expectedName, $timeoutMs, $serverId, $mapVersionId, false, 'udp');
        } catch (MapAvailableViaHttp $http) {
            // The host advertises HTTP for a *different* map than we asked for: it has cycled since
            // server_histories last saw it serving our version (Gores servers rotate maps every few
            // minutes). Our map isn't here — a UDP fallback would only re-dial and download the wrong
            // map to reject it on a CRC/name check (up to a full timeout on a slow link). Record the
            // cycle and let the next candidate try, matching the pure-UDP name_mismatch signal.
            if (trim($http->name) !== trim($expectedName)) {
                MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'name_mismatch', null);
                return null;
            }

            // Same map, served from the host's own HTTP mirror rather than over UDP — fetch it there.
            $bytes = $this->fetchAdvertisedUrl($http, $expectedSha256, $expectedName);
            if ($bytes !== null) {
                MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'http_fastdl', 'success', null, strlen($bytes));
                return $bytes;
            }

            // The advertised mirror refused us — almost always a Cloudflare/WAF block that fingerprints
            // our server-side HTTP client, which the in-game client never trips because it downloads
            // over UDP. Mirror the real DDNet client's "http failed, falling back to gameserver" path:
            // reconnect and stream the map over UDP, this time ignoring the advertised URL. Recorded
            // under the 'udp_fallback' source so the dashboard tells a clean UDP serve apart from a
            // recovery after an unusable mirror (a udp_fallback row implies HTTP was offered but failed).
            return $this->udpFallback($ip, $port, $flavor, $expectedSha256, $expectedCrc, $expectedName, $timeoutMs, $serverId, $mapVersionId);
        } catch (ConnectionFailed) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', 'connect_failed', null);
            return null;
        } catch (\Throwable $e) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp', 'failure', $this->classifyDownloadFailure($e), null);
            return null;
        }
    }

    /**
     * Re-dial the host and stream the map over UDP, ignoring any advertised HTTP mirror. The caller
     * invokes this only after the advertised mirror failed; outcomes are recorded under 'udp_fallback'.
     */
    private function udpFallback(string $ip, int $port, string $flavor, ?string $expectedSha256, ?int $expectedCrc, string $expectedName, int $timeoutMs, ?int $serverId, ?int $mapVersionId): ?string
    {
        try {
            return $this->runSession($ip, $port, $flavor, $expectedSha256, $expectedCrc, $expectedName, $timeoutMs, $serverId, $mapVersionId, true, 'udp_fallback');
        } catch (ConnectionFailed) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp_fallback', 'failure', 'connect_failed', null);
            return null;
        } catch (\Throwable $e) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, 'udp_fallback', 'failure', $this->classifyDownloadFailure($e), null);
            return null;
        }
    }

    /**
     * Map a download exception to a specific failure reason for the telemetry, so the dashboard can
     * tell "server cycled to a different map" (crc/sha/size mismatch) apart from "genuinely slow or
     * unreachable" (timeout). The messages are our own (MapDownloadVerification / the session loops),
     * so matching their stable tokens is safe. Anything unrecognised stays the generic download_failed.
     */
    private function classifyDownloadFailure(\Throwable $e): string
    {
        if (!$e instanceof MapDownloadFailed) {
            return 'download_failed';
        }

        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Timeout') => 'timeout',
            str_contains($message, 'CRC32 mismatch') => 'crc_mismatch',
            str_contains($message, 'SHA-256') => 'sha_mismatch',
            str_contains($message, 'size mismatch') => 'size_mismatch',
            default => 'download_failed',
        };
    }

    /**
     * One connect + UDP map-download attempt against a host, with the name/datafile gates and the
     * success/mismatch telemetry. $udpSource labels the rows ('udp' for the primary attempt,
     * 'udp_fallback' for a retry after a blocked HTTP mirror). Lets MapAvailableViaHttp, ConnectionFailed
     * and other throwables propagate so the caller decides how to record and whether to fall back.
     */
    private function runSession(string $ip, int $port, string $flavor, ?string $expectedSha256, ?int $expectedCrc, string $expectedName, int $timeoutMs, ?int $serverId, ?int $mapVersionId, bool $ignoreAdvertisedHttp, string $udpSource): ?string
    {
        $factory = $this->connectionFactory ?? $this->defaultFactory();
        $conn    = ($factory)($ip, $port, $flavor);

        $result = MapDownloadSessionFactory::for($conn, $flavor)->downloadWithIdentity(
            expectedSha256: $expectedSha256,
            expectedCrc: $expectedCrc,
            timeoutMs: $timeoutMs,
            ignoreAdvertisedHttp: $ignoreAdvertisedHttp,
        );

        // Name-match gate: a host that has since switched maps answers with a different name;
        // archiving its bytes here would mislabel them. Trim both sides (the stored name may come
        // from the DDNet HTTP feed, the wire name off the UDP MAP_CHANGE).
        if (trim($result->name) !== trim($expectedName)) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, $udpSource, 'failure', 'name_mismatch', null);
            return null;
        }
        if (!$this->looksLikeMapDatafile($result->bytes)) {
            MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, $udpSource, 'failure', 'not_datafile', null);
            return null;
        }

        MapAcquisitionAttempted::dispatch($mapVersionId, $serverId, $udpSource, 'success', null, strlen($result->bytes));

        return $result->bytes;
    }

    /**
     * Fetch a map from the HTTP URL a fast-download server advertised in MAP_DETAILS. The URL is
     * server-controlled, so this is locked down against SSRF: https only, no redirects, public hosts
     * only, a size cap, and the bytes must be a Teeworlds map datafile whose sha256 matches what we
     * expected (or, lacking an expectation, the hash the server itself announced). Returns null on any
     * failure so acquisition falls through.
     */
    private function fetchAdvertisedUrl(MapAvailableViaHttp $http, ?string $expectedSha256, string $expectedName): ?string
    {
        // The host must still be advertising the map we asked for (it may have changed maps).
        if (trim($http->name) !== trim($expectedName) || !$this->isFetchableUrl($http->url)) {
            return null;
        }

        try {
            $response = Http::withOptions(['allow_redirects' => false])->timeout(20)->get($http->url);
        } catch (\Throwable) {
            return null;
        }
        if (!$response->successful()) {
            return null;
        }

        $body = $response->body();
        if (strlen($body) > self::MAX_MAP_BYTES || !$this->looksLikeMapDatafile($body)) {
            return null;
        }

        // Content integrity: prefer the sha256 we expected, else the one the server announced.
        $want = $expectedSha256 ?? $http->sha256;
        if ($want !== null && hash('sha256', $body) !== strtolower($want)) {
            return null;
        }

        return $body;
    }

    /**
     * Whether a server-advertised URL is safe to fetch: https scheme and a host that resolves only to
     * PUBLIC addresses (no loopback/private/link-local/reserved). A blunt SSRF guard so a malicious
     * server cannot point us at an internal service; redirects are disabled separately by the caller.
     */
    private function isFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $ips = $this->resolveHost((string) $parts['host']);
        if ($ips === []) {
            return false; // unresolvable → refuse
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // any private/reserved address → refuse
            }
        }

        return true;
    }

    /**
     * Resolve a host to its IP addresses (a literal IP resolves to itself), or [] on failure.
     *
     * @return string[]
     */
    private function resolveHost(string $host): array
    {
        $literal = trim($host, '[]'); // strip IPv6 brackets
        if (filter_var($literal, FILTER_VALIDATE_IP) !== false) {
            return [$literal];
        }

        return gethostbynamel($host) ?: [];
    }

    /**
     * Try the distinct servers that served this identity, most-recently-seen first, capped at
     * MAP_UDP_MAX_HOSTS, until one yields verified bytes. Grouping by server_id (history rows aggregate
     * per server/weekday/hour, so one server owns many rows) guarantees we surface up to N *different*
     * hosts rather than many rows of a single busy server — which is the whole point of the fallback.
     *
     * Only hosts whose (server, identity) history row was refreshed within the live window are kept: a
     * row's map/version is fixed at creation and its updated_at is bumped every scrape the host is still
     * on that map, so a host that has since cycled away leaves its row frozen in the past. Dialing such a
     * host only ever yields a name/crc mismatch (it serves a different map now), so we exclude it up front
     * rather than spend a connect + a download to reject it. A version no live host serves collapses to a
     * single no_hosts signal instead of N mismatch rows. The window matches the backfiller's notion of
     * "currently served" (MAP_BACKFILL_LIVE_WINDOW_MIN) so "live" means the same thing across the archive.
     */
    private function downloadFromCandidates(Builder $historyQuery, ?string $sha256, ?int $crc, string $name, ?int $mapVersionId, ?int $mapId = null): ?string
    {
        $serverIds = $historyQuery->getQuery()
            ->where('updated_at', '>=', now()->subMinutes($this->liveWindowMinutes()))
            // Drop hosts currently parked for THIS map: a (host, map) pair that just failed a UDP
            // download is in backoff, so dialing it again would only re-spend a connect timeout it just
            // exhausted. Keying on the pair (not the host) means a host that cycles to a different map is
            // a different key and is still tried — only the actually-failing pair backs off. Filtered
            // before ->limit() so the cap still yields up to N *eligible* hosts.
            ->when($mapId !== null, fn ($q) => $q->whereNotExists(function ($sub) use ($mapId) {
                $sub->from('server_map_backoffs')
                    ->whereColumn('server_map_backoffs.server_id', 'server_histories.server_id')
                    ->where('server_map_backoffs.map_id', $mapId)
                    ->where('server_map_backoffs.cooldown_until', '>', now());
            }))
            // Drop password-protected hosts: the in-game download sends an empty password, which a
            // 0.6/DDNet server validates before MAP_CHANGE, so the connect can only ever fail. Dialing
            // such a host wastes a connect timeout to produce a guaranteed connect_failed (live-confirmed).
            // The bitwise `flags & FLAG_PASSWORD` is portable across SQLite and MySQL.
            ->whereNotExists(function ($sub) {
                $sub->from('servers')
                    ->whereColumn('servers.id', 'server_histories.server_id')
                    ->whereRaw('(servers.flags & ?) != 0', [Server::FLAG_PASSWORD]);
            })
            ->select('server_id')
            ->selectRaw('MAX(updated_at) as recent_at')
            ->groupBy('server_id')
            ->orderByDesc('recent_at')
            ->limit($this->maxHosts())
            ->pluck('server_id');

        if ($serverIds->isEmpty()) {
            // surface "we couldn't even find a host that serves this" as a distinct failure
            MapAcquisitionAttempted::dispatch($mapVersionId, null, 'udp', 'failure', 'no_hosts', null);
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
            $bytes = $this->downloadFromHost($host['ip'], $host['port'], $host['flavor'], $sha256, $crc, $name, $this->timeoutMs, (int) $serverId, $mapVersionId);
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
     * How recently a host's history row for an identity must have been refreshed to still count as
     * "currently serving" it. Shares MAP_BACKFILL_LIVE_WINDOW_MIN (default: two scrape intervals, so a
     * host seen on the previous scrape still qualifies) so candidate selection and the backfiller agree
     * on what "live" means.
     */
    private function liveWindowMinutes(): int
    {
        return (int) env('MAP_BACKFILL_LIVE_WINDOW_MIN', max(1, (int) env('CRONTASK_INTERVAL', 10)) * 2);
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
