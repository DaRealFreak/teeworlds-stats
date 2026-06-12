<?php

namespace App\TwStats\Net;

/**
 * A minimal connectionless UDP transport. Abstracted so the 0.7 discovery orchestration can be
 * unit-tested with a scripted fake instead of real sockets.
 */
interface UdpTransport
{
    public function send(string $ip, int $port, string $data): void;

    /**
     * Receive the next datagram, or null if none arrives within the timeout.
     *
     * @return array{ip: string, port: int, data: string}|null
     */
    public function receive(int $timeoutMs): ?array;
}
