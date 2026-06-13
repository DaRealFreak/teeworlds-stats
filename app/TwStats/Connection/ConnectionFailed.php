<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Thrown when a GameConnection handshake or receive cycle cannot complete:
 * timeout before ONLINE, a peer CLOSE, or an unrecoverable protocol error.
 */
final class ConnectionFailed extends \RuntimeException
{
    public function __construct(string $message = 'Connection failed', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
