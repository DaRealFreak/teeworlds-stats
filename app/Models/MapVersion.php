<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A distinct content version of a map NAME — one .map file, identified by its sha256 (DDNet) and/or
 * crc (0.6/0.7). Both keys live on one row; a file seen under two partial identities is merged into
 * one version (see App\Service\MapVersionRecorder). The parent Map stays the stats/URL anchor.
 */
class MapVersion extends Model
{
    use HasFactory;

    // The lean 4-state acquisition lifecycle persisted in acquisition_status: a version is recorded as
    // pending, then resolves to cached (file on disk), udp_only (only a server serves it, not mirrored),
    // or unavailable (no source reachable). The recheck gate re-promotes stale udp_only/unavailable rows.
    public const STATUS_PENDING = 'pending';

    public const STATUS_CACHED = 'cached';

    public const STATUS_UDP_ONLY = 'udp_only';

    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_acquire_attempt_at' => 'datetime',
            'status_checked_at' => 'datetime',
        ];
    }

    /** Versions still worth working on: anything not already on disk. */
    public function scopeNeedingAcquisition($query)
    {
        return $query->where('acquisition_status', '!=', self::STATUS_CACHED);
    }

    /**
     * Persist a new acquisition status + recheck timestamp via the base query so the model's own
     * updated_at is NOT bumped — the recheck gate treats updated_at as an independent recency signal.
     */
    public function markStatus(string $status): void
    {
        $now = now();
        static::whereKey($this->getKey())->toBase()->update([
            'acquisition_status' => $status,
            'status_checked_at' => $now,
        ]);

        $this->setAttribute('acquisition_status', $status);
        $this->setAttribute('status_checked_at', $now);
    }

    public function map()
    {
        return $this->belongsTo(Map::class);
    }

    public function serverRecords()
    {
        return $this->hasMany(ServerHistory::class);
    }

    /** The content key used in the cache filename and per-version URLs: sha256 if known, else crc. */
    public function contentKey(): ?string
    {
        return $this->getAttribute('sha256') ?? $this->getAttribute('crc');
    }
}
