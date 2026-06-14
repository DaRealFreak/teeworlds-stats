<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

use App\TwStats\Discovery\FlavorClassifier;

/**
 * Selects the map-download driver for a connection by server flavour: native 0.7 (sixup) servers
 * stream raw MAP_DATA windows and need SevenMapDownloadSession; DDNet and vanilla 0.6 servers speak
 * the classic 0.6 per-chunk protocol (DDNet routes through 0.6, see GameConnection::isSixup()).
 */
final class MapDownloadSessionFactory
{
    public static function for(ConnectionInterface $conn, string $flavor): MapDownloadSessionInterface
    {
        return $flavor === FlavorClassifier::FLAVOR_VANILLA_07
            ? new SevenMapDownloadSession($conn)
            : new MapDownloadSession($conn);
    }
}
