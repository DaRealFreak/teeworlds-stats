<?php

namespace App\Service;

use App\Models\Map;

/**
 * A map's file availability, surfaced as a badge on the map list. Cached means a .map file is on
 * disk (the current version's, or a legacy map-keyed copy); Downloadable means we have an identity
 * or a recorded host to fetch it from on demand; Unavailable means neither.
 */
enum MapAvailability: string
{
    case Cached = 'cached';
    case Downloadable = 'downloadable';
    case Unavailable = 'unavailable';

    /** Resolve a map's availability against the on-disk cache. */
    public static function for(Map $map, MapFileStore $store): self
    {
        $current = $map->currentVersion;
        if (($current !== null && $store->isCachedVersion($current)) || $store->isCached($map)) {
            return self::Cached;
        }
        if ($map->hasDownloadableFile()) {
            return self::Downloadable;
        }

        return self::Unavailable;
    }

    public function label(): string
    {
        return match ($this) {
            self::Cached => 'Cached',
            self::Downloadable => 'Downloadable',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Cached => 'bg-success',
            self::Downloadable => 'bg-info',
            self::Unavailable => 'bg-secondary',
        };
    }
}
