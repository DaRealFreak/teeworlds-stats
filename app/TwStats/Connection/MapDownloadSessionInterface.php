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
     * @throws MapDownloadFailed on timeout, malformed messages, or integrity failure.
     */
    public function downloadWithIdentity(
        ?string $expectedSha256 = null,
        ?int $expectedCrc = null,
        int $timeoutMs = 120000,
    ): MapDownloadResult;
}
