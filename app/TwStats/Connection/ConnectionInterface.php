<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Minimal contract for an established Teeworlds game connection.
 *
 * Extracted from GameConnection so that MapDownloadSession and other higher-
 * level protocol drivers can be tested against a lightweight fake instead of
 * a real UDP socket.
 */
interface ConnectionInterface
{
    /**
     * Queue a vital (reliable) message for transmission.
     * The message must be a fully-packed NETMSG payload (VariableInt header + fields).
     */
    public function sendVital(string $message): void;

    /**
     * Flush all queued chunks to the wire in a single packet.
     */
    public function flush(): void;

    /**
     * Receive the next available data message payloads.
     *
     * Control messages (keepalive, ping, …) are handled internally.
     * Returns an empty array on timeout or when only control traffic arrived.
     *
     * @return string[]  Raw NETMSG payload byte-strings (vital chunk data).
     * @throws ConnectionFailed if the peer sent a CLOSE.
     */
    public function receive(int $timeoutMs): array;

    /**
     * Send a CLOSE control packet and transition to the offline state.
     */
    public function disconnect(): void;

    /**
     * Whether the connection is in the ONLINE state.
     */
    public function isOnline(): bool;
}
