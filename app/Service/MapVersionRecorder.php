<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use Carbon\Carbon;

/**
 * Records the content version a map sighting served. A version is identified by sha256 (DDNet)
 * and/or crc (0.6/0.7); a sighting carries whatever its source knows. We match an existing version
 * by EITHER key, backfill the missing one, and merge two rows once a sighting proves they are the
 * same file. Keyless sightings record nothing — there is nothing to distinguish.
 */
class MapVersionRecorder
{
    /**
     * Record a sighting's version for $map and return it, or null when the sighting is keyless.
     */
    public function record(Map $map, ?string $sha256, ?string $crc, ?int $size): ?MapVersion
    {
        if ($sha256 === null && $crc === null) {
            return null;
        }

        $now = Carbon::now();

        $bySha = $sha256 !== null
            ? MapVersion::where('map_id', $map->getAttribute('id'))->where('sha256', $sha256)->first()
            : null;
        $byCrc = $crc !== null
            ? MapVersion::where('map_id', $map->getAttribute('id'))->where('crc', $crc)->first()
            : null;

        $version = $bySha ?? $byCrc;

        if ($version === null) {
            return MapVersion::create([
                'map_id' => $map->getAttribute('id'),
                'sha256' => $sha256,
                'crc' => $crc,
                'size' => $size,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        }

        $updates = ['last_seen_at' => $now];
        if ($version->getAttribute('sha256') === null && $sha256 !== null) {
            $updates['sha256'] = $sha256;
        }
        if ($version->getAttribute('crc') === null && $crc !== null) {
            $updates['crc'] = $crc;
        }
        if ($version->getAttribute('size') === null && $size !== null) {
            $updates['size'] = $size;
        }
        $version->update($updates);

        return $version;
    }
}
