<?php

namespace App\Listeners;

use App\Events\MapAcquisitionAttempted;
use App\Models\MapAcquisitionAttempt;

/**
 * Persists each MapAcquisitionAttempted as a row. The whole body is guarded: telemetry must never throw
 * into the acquisition path, so a logging failure (e.g. a transient DB error) is swallowed, not propagated.
 */
class RecordMapAcquisitionAttempt
{
    public function handle(MapAcquisitionAttempted $event): void
    {
        try {
            MapAcquisitionAttempt::create([
                'map_version_id' => $event->mapVersionId,
                'server_id' => $event->serverId,
                'source' => $event->source,
                'outcome' => $event->outcome,
                'reason' => $event->reason,
                'bytes' => $event->bytes,
            ]);
        } catch (\Throwable) {
            // never let telemetry break a download
        }
    }
}
