<?php

namespace App\Listeners;

use App\Events\MapAcquisitionAttempted;
use App\Models\MapVersion;
use App\Models\ServerMapBackoff;

/**
 * Advances control-state reactively off the same outcome event that drives telemetry — so the failure that
 * is *recorded* is the same failure that *governs* the next attempt, with no MapSource return-type refactor.
 *
 * The split between the three knobs is the whole point of this listener: a failure routes to whichever
 * entity actually owns the problem.
 *  - Host-health UDP failures (connect/timeout/transfer) back off the (host, map) PAIR — keyed on the pair so
 *    a host that cycles to a different map earns a fresh attempt and is never permanently blacklisted.
 *  - Only bytes-we-could-obtain-but-can't-verify (a mirror http_error / sha_mismatch) cool down the MAP via
 *    last_acquire_attempt_at; that is map-specific and host-independent.
 *  - A mirror 404 is a CLASSIFICATION, not a cooldown: it flips status to udp_only and is re-probed on the
 *    30-day mirror recheck, not the 6h cooldown — stamping the cooldown would hide the version from the filler
 *    and block the UDP retry the moment a live host appears.
 *  - A cycled-host mismatch (name/crc) penalises neither: the host is healthy with a different map and the
 *    live-gate already handles it next pass.
 *
 * A second, independent auto-discovered listener (not folded into RecordMapAcquisitionAttempt) keeps telemetry
 * and control-state from breaking each other. The whole body is guarded: state must never throw into a download.
 */
class ApplyMapAcquisitionState
{
    /** Host-health UDP failures: the (host, map) pair is currently unreachable → escalating backoff. */
    private const HOST_HEALTH_REASONS = ['connect_failed', 'timeout', 'download_failed'];

    /** Mirror failures where the bytes can't be verified → cool the map down (avoid re-fetching bad bytes). */
    private const MAP_COOLDOWN_REASONS = ['http_error', 'sha_mismatch'];

    private const UDP_SOURCES = ['udp', 'udp_fallback'];

    public function handle(MapAcquisitionAttempted $e): void
    {
        try {
            $version = $e->mapVersionId ? MapVersion::with('map')->find($e->mapVersionId) : null;
            $mapId = $version?->map?->getKey();

            if ($e->outcome === 'success') {
                $version?->markStatus(MapVersion::STATUS_CACHED);

                // a UDP serve proves the (host, map) pair is healthy again — clear its backoff
                if (in_array($e->source, self::UDP_SOURCES, true) && $e->serverId && $mapId) {
                    ServerMapBackoff::clear($e->serverId, $mapId);
                }

                return;
            }

            if ($e->outcome !== 'failure') {
                return;
            }

            if ($e->source === 'http_mirror') {
                if ($e->reason === 'not_mirrored_404') {
                    $version?->markStatus(MapVersion::STATUS_UDP_ONLY);
                } elseif (in_array($e->reason, self::MAP_COOLDOWN_REASONS, true) && $version) {
                    // query-builder stamp so the model's own updated_at (a recheck recency signal) is not bumped
                    MapVersion::whereKey($version->getKey())->toBase()->update(['last_acquire_attempt_at' => now()]);
                }

                return;
            }

            if (in_array($e->source, self::UDP_SOURCES, true)) {
                if (in_array($e->reason, self::HOST_HEALTH_REASONS, true) && $e->serverId && $mapId) {
                    ServerMapBackoff::recordFailure($e->serverId, $mapId);
                } elseif ($e->reason === 'no_hosts') {
                    $version?->markStatus(MapVersion::STATUS_UNAVAILABLE);
                }
                // any other reason (name_mismatch / crc_mismatch / not_datafile) → the host cycled or the
                // bytes are a different map; the live-gate handles it, so penalise nothing.
            }
        } catch (\Throwable) {
            // telemetry/state must never break a download
        }
    }
}
