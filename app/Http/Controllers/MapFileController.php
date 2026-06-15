<?php

namespace App\Http\Controllers;

use App\Models\Map;
use App\Models\MapVersion;
use App\Service\MapFileStore;
use App\Service\MapFileUnavailable;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a map's .map bytes: `file` inline (what the in-browser viewer fetches) and `download` as an
 * attachment. An optional {version} content key (sha256 or crc) selects a specific version; omitted
 * serves the current (newest-seen) version, falling back to the legacy map-keyed identity for maps
 * that predate version tracking. Both lazily acquire + cache via MapFileStore and 404 when the file
 * cannot be produced. Not response-cached — binary and potentially large.
 */
class MapFileController extends Controller
{
    public function __construct(private readonly MapFileStore $store)
    {
    }

    public function file(string $map_name, ?string $version = null): StreamedResponse
    {
        return $this->serve($map_name, $version, attachment: false);
    }

    public function download(string $map_name, ?string $version = null): StreamedResponse
    {
        return $this->serve($map_name, $version, attachment: true);
    }

    private function serve(string $map_name, ?string $version, bool $attachment): StreamedResponse
    {
        /** @var Map $map */
        $map = Map::where('name', urldecode($map_name))->firstOrFail();

        $relative = $this->resolve($map, $version);

        $disk = Storage::disk($this->store->disk);
        $filename = $map->getAttribute('name') . '.map';
        $headers = ['Content-Type' => 'application/octet-stream'];

        return $attachment
            ? $disk->download($relative, $filename, $headers)
            : $disk->response($relative, $filename, $headers, 'inline');
    }

    /**
     * Relative path of the acquired/cached file for the requested version key (matched by sha256 or
     * crc), the current version when no key is given, or the legacy map-keyed file as a last resort.
     * 404s when the version is unknown or no file can be produced.
     */
    private function resolve(Map $map, ?string $version): string
    {
        if ($version !== null) {
            /** @var MapVersion|null $mapVersion */
            $mapVersion = $map->versions()
                ->where(fn ($q) => $q->where('sha256', $version)->orWhere('crc', $version))
                ->first();
            if ($mapVersion === null) {
                abort(404, 'Map version not found');
            }

            return $this->ensureVersion($mapVersion);
        }

        $current = $map->currentVersion;
        if ($current !== null) {
            return $this->ensureVersion($current);
        }

        // legacy: a map with a maps.* identity but no MapVersion row yet
        try {
            return $this->store->ensure($map);
        } catch (MapFileUnavailable) {
            abort(404, 'Map file not available');
        }
    }

    private function ensureVersion(MapVersion $version): string
    {
        try {
            return $this->store->ensureVersion($version);
        } catch (MapFileUnavailable) {
            abort(404, 'Map file not available');
        }
    }
}
