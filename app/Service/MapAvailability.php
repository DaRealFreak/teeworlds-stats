<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;

/**
 * A map's file availability, surfaced as a badge on the map list and the map detail page. The disk is
 * the source of truth for "have the file": Cached means a .map file is on disk (the current version's,
 * or a legacy map-keyed copy). Otherwise the badge reflects the version's acquisition lifecycle so the
 * archive's backlog is visible: Queued (recorded, awaiting/eligible-for acquisition), Server-only (only
 * a live host serves it, not mirrored — we can still UDP-download it), or Unavailable (no source).
 */
enum MapAvailability: string
{
    case Cached = 'cached';
    case Queued = 'queued';
    case ServerOnly = 'server_only';
    case Unavailable = 'unavailable';

    /** Resolve a map's availability against the on-disk cache, falling back to its current version's status. */
    public static function for(Map $map, MapFileStore $store): self
    {
        $current = $map->currentVersion;
        if (($current !== null && $store->isCachedVersion($current)) || $store->isCached($map)) {
            return self::Cached;
        }

        // With a current version, the badge mirrors that version's lifecycle so the list and detail pages
        // agree. Legacy maps with no version rows fall through to Unavailable (a cached legacy file was
        // already caught above).
        return $current !== null ? self::forVersion($current, $store) : self::Unavailable;
    }

    /** Resolve a single version's availability: disk first, then its persisted acquisition lifecycle. */
    public static function forVersion(MapVersion $version, MapFileStore $store): self
    {
        // Disk is the source of truth for "have the file" — a cached row whose file is gone falls through
        // to the status mapping below and is re-queued.
        if ($store->isCachedVersion($version)) {
            return self::Cached;
        }

        return match ($version->getAttribute('acquisition_status')) {
            MapVersion::STATUS_PENDING => self::Queued,
            MapVersion::STATUS_UDP_ONLY => self::ServerOnly,
            MapVersion::STATUS_UNAVAILABLE => self::Unavailable,
            // status says cached but the file is gone (above) — it will be re-acquired.
            MapVersion::STATUS_CACHED => self::Queued,
            // null/unknown status: a keyed row is still acquirable, a keyless one is a dead end.
            default => $version->contentKey() !== null ? self::Queued : self::Unavailable,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cached => 'Cached',
            self::Queued => 'Queued',
            self::ServerOnly => 'Server-only',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Cached => 'bg-success',
            self::Queued => 'bg-info',
            self::ServerOnly => 'bg-warning text-dark',
            self::Unavailable => 'bg-secondary',
        };
    }
}
