<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * A driver that downloads a map's bytes over an established connection and reports the identity the
 * server announced. Implemented per protocol flavour (0.6 and 0.7/sixup).
 */
interface MapDownloadSessionInterface
{
    /**
     * @param bool $ignoreAdvertisedHttp When true, do not hand a server-advertised HTTP download URL
     *                                    back to the caller (no MapAvailableViaHttp); keep streaming the
     *                                    map over UDP instead. Used as the caller's fallback after the
     *                                    advertised HTTP mirror failed, mirroring the real client's
     *                                    "http failed, falling back to gameserver" behaviour.
     *
     * @throws MapDownloadFailed on timeout, malformed messages, or integrity failure.
     */
    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
        bool $ignoreAdvertisedHttp = false,
    ): MapDownloadResult;
}
