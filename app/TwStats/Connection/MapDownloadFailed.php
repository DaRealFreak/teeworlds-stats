<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Thrown by MapDownloadSession::download() when the in-game map transfer fails.
 *
 * Causes include: timeout, malformed messages, CRC/SHA-256 mismatch, or a size
 * mismatch between the announced and received byte count.
 */
final class MapDownloadFailed extends \RuntimeException
{
}
