<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by the map acquisition sources at each attempt outcome. Carries only scalars so it is cheap and
 * has no DB coupling; App\Listeners\RecordMapAcquisitionAttempt turns it into a map_acquisition_attempts row.
 */
class MapAcquisitionAttempted
{
    use Dispatchable;

    public function __construct(
        public readonly ?int $mapVersionId,
        public readonly ?int $serverId,
        public readonly string $source,   // http_mirror | udp | http_fastdl
        public readonly string $outcome,  // success | failure
        public readonly ?string $reason = null,
        public readonly ?int $bytes = null,
    ) {
    }
}
