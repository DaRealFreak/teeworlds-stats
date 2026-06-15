<?php

namespace App\Service;

use App\Models\Map;
use App\Models\MapVersion;

/**
 * A strategy that tries to produce the raw bytes of a map's .map file. Implementations return
 * the verified bytes on success or null if they cannot serve this map, so MapFileStore can try
 * the next source. Phase A ships DdnetHttpMapSource; Phase E adds the UDP GameServerMapSource.
 *
 * Both acquisition paths are supported: map-keyed (fetch) for legacy callers and version-keyed
 * (fetchVersion) for the new MapFileStore::ensureVersion path.
 */
interface MapSource
{
    public function fetch(Map $map): ?string;

    public function fetchVersion(MapVersion $version): ?string;
}
