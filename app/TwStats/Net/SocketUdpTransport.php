<?php

namespace App\TwStats\Net;

/**
 * Non-blocking UDP via the socket extension. send() is fire-and-forget; receive() blocks up to
 * the given timeout for one datagram. Mirrors the connectionless I/O the legacy scraper uses,
 * and the flow proven live against the teeworlds.com 0.7 masters.
 */
final class SocketUdpTransport implements UdpTransport
{
    private \Socket $socket;

    public function __construct()
    {
        $this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    }

    public function __destruct()
    {
        socket_close($this->socket);
    }

    public function send(string $ip, int $port, string $data): void
    {
        @socket_sendto($this->socket, $data, strlen($data), 0, $ip, $port);
    }

    public function receive(int $timeoutMs): ?array
    {
        // set per-call timeout so each receive blocks at most $timeoutMs milliseconds
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, [
            'sec' => intdiv($timeoutMs, 1000),
            'usec' => ($timeoutMs % 1000) * 1000,
        ]);

        $data = '';
        $ip = '';
        $port = 0;
        $received = @socket_recvfrom($this->socket, $data, 4096, 0, $ip, $port);

        if ($received === false || $received <= 0) {
            return null;
        }

        return ['ip' => $ip, 'port' => (int) $port, 'data' => $data];
    }
}
