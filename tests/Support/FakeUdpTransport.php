<?php

namespace Tests\Support;

use App\TwStats\Net\UdpTransport;

/**
 * Scripted UDP transport for testing the 0.7 discovery orchestration. queue() enqueues a datagram
 * to be returned by receive(); queueGap() enqueues a null (a "timeout") so a receive-drain loop
 * ends where the real network would fall silent. Sends are recorded for assertions.
 */
final class FakeUdpTransport implements UdpTransport
{
    /** @var array<int, array{ip: string, port: int, data: string}> */
    public array $sent = [];

    /** @var array<int, array{ip: string, port: int, data: string}|null> */
    private array $inbox = [];

    public function queue(string $ip, int $port, string $data): void
    {
        $this->inbox[] = ['ip' => $ip, 'port' => $port, 'data' => $data];
    }

    public function queueGap(): void
    {
        $this->inbox[] = null;
    }

    public function send(string $ip, int $port, string $data): void
    {
        $this->sent[] = ['ip' => $ip, 'port' => $port, 'data' => $data];
    }

    public function receive(int $timeoutMs): ?array
    {
        if ($this->inbox === []) {
            return null;
        }

        return array_shift($this->inbox);
    }
}
