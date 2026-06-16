<?php

namespace App\Service;

use App\Models\MapAcquisitionAttempt;
use App\Models\MapVersion;
use App\Models\Server;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only aggregation for the map-archive admin dashboard. Every windowed method takes a $days span.
 * Day bucketing uses DATE(created_at), which both MySQL and SQLite support. Headline counts avoid an
 * O(versions) disk scan: cachedFiles/cachedBytes come from a single directory listing, the rest are
 * single COUNT queries.
 */
final class MapArchiveStats
{
    public function __construct(private readonly string $disk = 'local')
    {
    }

    /** @return array{cachedFiles:int, cachedBytes:int, totalVersions:int, neverAttempted:int, coolingDown:int} */
    public function overview(): array
    {
        $storage = Storage::disk($this->disk);
        $files = $storage->files('maps');
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += $storage->size($file);
        }

        return [
            'cachedFiles' => count($files),
            'cachedBytes' => $bytes,
            'totalVersions' => MapVersion::count(),
            'neverAttempted' => MapVersion::whereNull('last_acquire_attempt_at')->count(),
            'coolingDown' => MapVersion::where('last_acquire_attempt_at', '>=', now()->subHours($this->retryHours()))->count(),
        ];
    }

    /** @return array<string,int> ordered oldest→newest day => success count, zero-filled across the window */
    public function downloadsPerDay(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $counts = MapAcquisitionAttempt::query()
            ->where('outcome', 'success')
            ->where('created_at', '>=', $since)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = now()->subDays($i)->toDateString();
            $series[$day] = (int) ($counts[$day] ?? 0);
        }

        return $series;
    }

    /** @return \Illuminate\Support\Collection<int, MapAcquisitionAttempt> latest successes with map+server loaded */
    public function recentDownloads(int $limit = 25): \Illuminate\Database\Eloquent\Collection
    {
        return MapAcquisitionAttempt::with(['mapVersion.map', 'server'])
            ->where('outcome', 'success')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Latest genuine failures with map+server loaded. Excludes not_mirrored_404: that is the expected
     * first-source miss (the DDNet HTTP mirror simply does not carry the map), which the same scrape
     * pass then retries over UDP — so it is an intermediate step, not a dead end, and it would
     * otherwise dominate the list and bury the real failures.
     *
     * @return \Illuminate\Support\Collection<int, MapAcquisitionAttempt>
     */
    public function recentFailures(int $limit = 25): \Illuminate\Database\Eloquent\Collection
    {
        return MapAcquisitionAttempt::with(['mapVersion.map', 'server'])
            ->where('outcome', 'failure')
            ->where('reason', '!=', 'not_mirrored_404')
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Servers ranked by failed UDP/fastdl attempts in the window, with a success rate and last reason.
     *
     * @return array<int, array{id:int, name:string, address:string, fails:int, successRate:float, lastReason:?string, lastTriedAt:?\Illuminate\Support\Carbon}>
     */
    public function failingServers(int $days = 30, int $limit = 20): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $rows = MapAcquisitionAttempt::query()
            ->whereNotNull('server_id')
            ->where('created_at', '>=', $since)
            ->selectRaw('server_id')
            ->selectRaw("SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as fails")
            ->selectRaw("SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as oks")
            ->selectRaw('MAX(created_at) as last_at')
            ->groupBy('server_id')
            ->having('fails', '>', 0)
            ->orderByDesc('fails')
            ->limit($limit)
            ->get();

        $servers = Server::whereIn('id', $rows->pluck('server_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($servers) {
            $server = $servers->get($row->server_id);
            $fails = (int) $row->fails;
            $oks = (int) $row->oks;
            $total = $fails + $oks;

            return [
                'id' => (int) $row->server_id,
                'name' => $server?->getAttribute('name') ?? '(deleted server)',
                'address' => $server ? $server->getAttribute('ip') . ':' . $server->getAttribute('port') : '',
                'fails' => $fails,
                'successRate' => $total > 0 ? round($oks / $total * 100, 1) : 0.0,
                'lastReason' => $this->lastReasonFor((int) $row->server_id),
                'lastTriedAt' => $row->last_at !== null ? \Illuminate\Support\Carbon::parse($row->last_at) : null,
            ];
        })->all();
    }

    /**
     * Version count per acquisition_status, zero-filled across all four statuses so the dashboard always
     * renders every row even before any rows reach a given state. groupBy + COUNT(*) is portable to
     * both SQLite and MySQL.
     *
     * @return array<string,int>
     */
    public function statusBreakdown(): array
    {
        $counts = MapVersion::query()
            ->groupBy('acquisition_status')
            ->selectRaw('acquisition_status, COUNT(*) as c')
            ->pluck('c', 'acquisition_status')
            ->toArray();

        $out = [];
        foreach ([
            MapVersion::STATUS_PENDING,
            MapVersion::STATUS_CACHED,
            MapVersion::STATUS_UDP_ONLY,
            MapVersion::STATUS_UNAVAILABLE,
        ] as $status) {
            $out[$status] = (int) ($counts[$status] ?? 0);
        }

        return $out;
    }

    /** @return array<string, array{success:int, failure:int}> per-source success/failure tally in the window */
    public function sourceBreakdown(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $rows = MapAcquisitionAttempt::query()
            ->where('created_at', '>=', $since)
            ->selectRaw('source')
            ->selectRaw("SUM(CASE WHEN outcome = 'success' THEN 1 ELSE 0 END) as oks")
            ->selectRaw("SUM(CASE WHEN outcome = 'failure' THEN 1 ELSE 0 END) as fails")
            ->groupBy('source')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->source] = ['success' => (int) $row->oks, 'failure' => (int) $row->fails];
        }

        return $out;
    }

    private function lastReasonFor(int $serverId): ?string
    {
        return MapAcquisitionAttempt::where('server_id', $serverId)
            ->where('outcome', 'failure')
            ->latest('created_at')
            ->value('reason');
    }

    private function retryHours(): int
    {
        return (int) env('MAP_ACQUIRE_RETRY_HOURS', 6);
    }
}
