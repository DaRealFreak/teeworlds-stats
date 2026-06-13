<?php

namespace App\Service;

/**
 * A map's archive state, surfaced as a badge on the map list. Downloaded means the .map file is on
 * disk; Queued/Retrying/Unavailable describe an uncached map (eligible now, cooling down after a
 * failed attempt, or having no content key to download at all).
 */
enum MapArchiveStatus: string
{
    case Downloaded = 'downloaded';
    case Queued = 'queued';
    case Retrying = 'retrying';
    case Unavailable = 'unavailable';

    public function label(): string
    {
        return match ($this) {
            self::Downloaded => 'Downloaded',
            self::Queued => 'Queued',
            self::Retrying => 'Retrying',
            self::Unavailable => 'Unavailable',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Downloaded => 'bg-success',
            self::Queued => 'bg-info',
            self::Retrying => 'bg-warning',
            self::Unavailable => 'bg-secondary',
        };
    }
}
