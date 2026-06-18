<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Protocol\Seven\VariableInt;

/**
 * Builders for scripted 0.6 map-download wire messages, shared by the GameServerMapSource tests so the
 * two suites cannot drift on the framing.
 */
trait ScriptsMapDownloads
{
    /** Normalise to signed int32 — the wire representation the server packs CRCs in. */
    protected function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }

    protected function mapChange(string $name, int $crc, int $size): string
    {
        return VariableInt::pack((2 << 1) | 1)          // NETMSG_MAP_CHANGE = 2
            . $name . "\x00"
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($size);
    }

    protected function mapData(int $last, int $crc, int $chunk, string $data): string
    {
        return VariableInt::pack((3 << 1) | 1)          // NETMSG_MAP_DATA = 3
            . VariableInt::pack($last)
            . VariableInt::pack($this->toInt32($crc))
            . VariableInt::pack($chunk)
            . VariableInt::pack(strlen($data))
            . $data;
    }

    /** A connection whose single receive() batch carries the given pre-built messages. */
    protected function connectionYielding(string ...$messages): ConnectionInterface
    {
        return new class($messages) implements ConnectionInterface {
            public bool $disconnected = false;
            /** @var array<int, string[]> */
            private array $inbox;

            /** @param string[] $batch */
            public function __construct(array $batch)
            {
                $this->inbox = [$batch];
            }

            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return $this->inbox === [] ? [] : array_shift($this->inbox); }
            public function disconnect(): void { $this->disconnected = true; }
            public function isOnline(): bool { return !$this->disconnected; }
        };
    }
}
