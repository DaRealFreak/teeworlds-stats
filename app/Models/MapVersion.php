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

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_acquire_attempt_at' => 'datetime',
        ];
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
