<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\MassPrunable;

/**
 * One recorded acquisition attempt against one channel (http_mirror | udp | http_fastdl). Append-only
 * (created_at only); written by App\Listeners\RecordMapAcquisitionAttempt off the MapAcquisitionAttempted
 * event the acquisition sources fire. Drives the map-archive admin dashboard.
 *
 * Reason vocabulary (closed set): not_mirrored_404, timeout, connect_failed, name_mismatch, not_datafile,
 * sha_mismatch, no_hosts, http_error, download_failed, crc_mismatch, served_other_version.
 */
class MapAcquisitionAttempt extends Model
{
    use MassPrunable;

    public const UPDATED_AT = null; // append-only: there is no updated_at column

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function mapVersion()
    {
        return $this->belongsTo(MapVersion::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    /** Keep the log bounded — telemetry older than 30 days is pruned by the scheduled model:prune. */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(30));
    }
}
