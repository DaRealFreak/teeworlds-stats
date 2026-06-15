<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;
use App\Models\ServerHistory;
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

        // Both keys resolve to two different rows → this sighting proves they are the same file; merge.
        // The sha-row wins: it is the DDNet-authoritative identity and the more likely to already have
        // server_histories pointing at it, which minimises re-pointing.
        if ($bySha !== null && $byCrc !== null && $bySha->isNot($byCrc)) {
            $version = $this->merge($bySha, $byCrc);
        } else {
            $version = $bySha ?? $byCrc;
        }

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

    /**
     * Fold $loser into $winner: keep the earliest first_seen / latest last_seen, fill any missing
     * keys, re-point server_histories, and delete the loser. Returns the surviving row.
     *
     * Order matters: the (map_id, sha256) and (map_id, crc) unique indexes mean the loser's key must
     * leave the table BEFORE the winner adopts it — re-point histories and delete the loser first,
     * then update the winner — otherwise the key briefly sits on two rows and the update fails.
     */
    private function merge(MapVersion $winner, MapVersion $loser): MapVersion
    {
        $firstSeen = $winner->getAttribute('first_seen_at')->lessThanOrEqualTo($loser->getAttribute('first_seen_at'))
            ? $winner->getAttribute('first_seen_at')
            : $loser->getAttribute('first_seen_at');
        $lastSeen = $winner->getAttribute('last_seen_at')->greaterThanOrEqualTo($loser->getAttribute('last_seen_at'))
            ? $winner->getAttribute('last_seen_at')
            : $loser->getAttribute('last_seen_at');
        $sha256 = $winner->getAttribute('sha256') ?? $loser->getAttribute('sha256');
        $crc = $winner->getAttribute('crc') ?? $loser->getAttribute('crc');
        $size = $winner->getAttribute('size') ?? $loser->getAttribute('size');

        ServerHistory::where('map_version_id', $loser->getAttribute('id'))
            ->update(['map_version_id' => $winner->getAttribute('id')]);
        $loser->delete();

        $winner->update([
            'first_seen_at' => $firstSeen,
            'last_seen_at' => $lastSeen,
            'sha256' => $sha256,
            'crc' => $crc,
            'size' => $size,
        ]);

        return $winner;
    }
}
