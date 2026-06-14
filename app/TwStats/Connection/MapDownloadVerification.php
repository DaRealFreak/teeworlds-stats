<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Shared map-bytes integrity checks used by both the 0.6 and 0.7 download sessions: total size, the
 * server-announced CRC32, and (when present) SHA-256, plus caller expectations. Extracted so the two
 * protocol drivers cannot drift apart on verification.
 */
trait MapDownloadVerification
{
    /**
     * Verify total size, CRC32, and optional SHA-256.
     *
     * CRC note: PHP's crc32() returns a signed 32-bit int. Teeworlds packs the
     * CRC as a signed int too (via VariableInt). Normalise both to signed int32
     * before comparing so positive server CRCs and negative PHP CRCs agree.
     *
     * @throws MapDownloadFailed on any mismatch.
     */
    private function verifyIntegrity(
        string $payload,
        int $announcedSize,
        int $announcedCrc,
        ?string $serverSha256Hex,
        ?string $expectedSha256,
        ?int $expectedCrc,
    ): void {
        $actual = strlen($payload);
        if ($actual !== $announcedSize) {
            throw new MapDownloadFailed(
                "Map size mismatch: announced {$announcedSize}, received {$actual}",
            );
        }

        $phpCrc = $this->toInt32(crc32($payload));
        $srvCrc = $this->toInt32($announcedCrc);

        if ($phpCrc !== $srvCrc) {
            throw new MapDownloadFailed(
                sprintf('CRC32 mismatch: server 0x%08x, computed 0x%08x', $srvCrc & 0xFFFFFFFF, $phpCrc & 0xFFFFFFFF),
            );
        }

        if ($expectedCrc !== null) {
            $expCrc = $this->toInt32($expectedCrc);
            if ($phpCrc !== $expCrc) {
                throw new MapDownloadFailed(
                    sprintf('CRC32 mismatch with caller expectation: expected 0x%08x, computed 0x%08x', $expCrc & 0xFFFFFFFF, $phpCrc & 0xFFFFFFFF),
                );
            }
        }

        if ($expectedSha256 !== null) {
            $actual256 = hash('sha256', $payload);
            if (!hash_equals($expectedSha256, $actual256)) {
                throw new MapDownloadFailed(
                    "SHA-256 mismatch: expected {$expectedSha256}, got {$actual256}",
                );
            }
        }

        if ($serverSha256Hex !== null && $expectedSha256 !== null) {
            if (!hash_equals($serverSha256Hex, $expectedSha256)) {
                throw new MapDownloadFailed(
                    "Server-announced SHA-256 {$serverSha256Hex} does not match caller expectation {$expectedSha256}",
                );
            }
        }
    }

    /**
     * Normalise an arbitrary int to signed 32-bit two's complement, matching
     * both PHP's crc32() range and Teeworlds' VariableInt int32 representation.
     */
    private function toInt32(int $v): int
    {
        $v &= 0xFFFFFFFF;
        if ($v & 0x80000000) {
            $v -= 0x100000000;
        }

        return $v;
    }
}
