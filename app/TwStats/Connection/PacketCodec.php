<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Teeworlds connected-packet codec — pack / unpack the 3-byte (0.6) or
 * 7-byte (0.7 "sixup") packet header plus the per-chunk framing inside the
 * payload, with optional Huffman compression.
 *
 * Ported exactly from:
 *   ddnet/src/engine/shared/network.cpp  — CNetBase::SendPacket / UnpackPacket
 *   ddnet/src/engine/shared/network.cpp  — CNetChunkHeader::Pack / Unpack
 *   ddnet/src/engine/shared/protocolglue.cpp — PacketFlags_SixToSeven / SevenToSix
 *
 * The 0.7 ("sixup") wire uses a different flag-bit mapping and a 4-byte
 * security token at bytes 3..6.  See the comment block in network.h for
 * the full bit layout.
 */
final class PacketCodec
{
    // -----------------------------------------------------------------------
    // Public flag constants (0.6 / internal representation)
    // -----------------------------------------------------------------------

    /** 1 << 0 — marks a sixup packet on the wire (UNUSED in 0.6). */
    public const FLAG_UNUSED = 0x01;

    /** 1 << 1 — 0.6 token flag (not used by DDNet, kept for completeness). */
    public const FLAG_TOKEN = 0x02;

    /** 1 << 2 — control packet (no chunk framing; raw payload). */
    public const FLAG_CONTROL = 0x04;

    /** 1 << 3 — connectionless packet (handled separately). */
    public const FLAG_CONNLESS = 0x08;

    /** 1 << 4 — resend. */
    public const FLAG_RESEND = 0x10;

    /** 1 << 5 — payload is Huffman-compressed (set automatically on pack). */
    public const FLAG_COMPRESSION = 0x20;

    // -----------------------------------------------------------------------
    // Internal 0.7 flag values (protocol7 namespace in C++)
    // -----------------------------------------------------------------------

    private const P7_FLAG_CONTROL     = 0x01; // 1 << 0
    private const P7_FLAG_RESEND      = 0x02; // 1 << 1
    private const P7_FLAG_COMPRESSION = 0x04; // 1 << 2

    // -----------------------------------------------------------------------
    // Chunk flag values (same for both protocols)
    // -----------------------------------------------------------------------

    private const CHUNK_FLAG_VITAL  = 1;
    private const CHUNK_FLAG_RESEND = 2;

    // -----------------------------------------------------------------------
    // Limits
    // -----------------------------------------------------------------------

    private const MAX_PACKET_SIZE = 1400;
    private const HEADER_SIZE_06  = 3;
    private const HEADER_SIZE_07  = 7; // 3 + 4-byte security token

    private Huffman $huffman;

    public function __construct()
    {
        $this->huffman = new Huffman();
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    /**
     * Pack a connected packet to wire bytes.
     *
     * $packet shape:
     *   [
     *     'flags'   => int,          // FLAG_* constants (internal 0.6 representation)
     *     'ack'     => int,          // 10-bit acknowledgement number
     *     'chunks'  => [             // ignored for CONTROL packets
     *       ['vital' => bool, 'seq' => int, 'data' => string],
     *       ...
     *     ],
     *     'control' => string,       // raw payload for CONTROL packets
     *   ]
     *
     * @param  int|null $token  4-byte security token (required for sixup / 0.7)
     * @throws \InvalidArgumentException on obviously invalid input
     */
    public function pack(array $packet, bool $sixup, ?int $token = null): string
    {
        $flags     = $packet['flags'] ?? 0;
        $ack       = $packet['ack']   ?? 0;
        $chunks    = $packet['chunks'] ?? [];
        $isControl = (bool) ($flags & self::FLAG_CONTROL);

        $headerSize = $sixup ? self::HEADER_SIZE_07 : self::HEADER_SIZE_06;

        // ---- Build payload ------------------------------------------------
        if ($isControl) {
            // Control packets: raw payload, no chunk framing, no compression.
            $payload = $packet['control'] ?? '';
        } else {
            $payload = $this->packChunks($chunks, $sixup);

            // Attempt Huffman compression (only for non-control packets).
            $compressed = $this->huffman->compress($payload);
            if (strlen($compressed) > 0 && strlen($compressed) < strlen($payload)) {
                $payload = $compressed;
                $flags  |= self::FLAG_COMPRESSION;
            }
        }

        // ---- 0.7 flag remap -----------------------------------------------
        // For 0.7 ("sixup"), remap internal 0.6 flag bits to 0.7 wire bits.
        // We do NOT add the UNUSED marker here: in the C++ engine, Sixup is
        // determined by connection state (not from this bit in non-control
        // packets).  The UNUSED==CONTROL coincidence in 0.7 auto-detects only
        // control packets; all other sixup packets rely on caller context.
        $wireFlagsByte = $sixup ? $this->sixToSeven($flags) : $flags;

        // ---- Encode header ------------------------------------------------
        $numChunks = $isControl ? 0 : count($chunks);

        $byte0 = (($wireFlagsByte << 2) & 0xfc) | (($ack >> 8) & 0x03);
        $byte1 = $ack & 0xff;
        $byte2 = $numChunks & 0xff;

        $header = chr($byte0) . chr($byte1) . chr($byte2);

        if ($sixup) {
            // Security token: 4 bytes big-endian at bytes 3..6.
            $t      = (int) $token;
            $header .= chr(($t >> 24) & 0xff)
                     . chr(($t >> 16) & 0xff)
                     . chr(($t >>  8) & 0xff)
                     . chr($t & 0xff);
        }

        return $header . $payload;
    }

    /**
     * Unpack wire bytes into a packet array.
     *
     * Returns:
     *   [
     *     'flags'     => int,          // internal 0.6 flag representation
     *     'ack'       => int,
     *     'numChunks' => int,
     *     'token'     => int|null,     // non-null for sixup
     *     'chunks'    => [...],        // non-control packets
     *     'control'   => string,       // control packets
     *   ]
     *
     * @throws \InvalidArgumentException on malformed / truncated input
     */
    public function unpack(string $bytes, bool $sixup): array
    {
        $size = strlen($bytes);

        if ($size < self::HEADER_SIZE_06) {
            throw new \InvalidArgumentException(
                "Packet too short: {$size} bytes (need at least " . self::HEADER_SIZE_06 . ')'
            );
        }

        $byte0 = ord($bytes[0]);
        $byte1 = ord($bytes[1]);
        $byte2 = ord($bytes[2]);

        // Flags are in bits 2..7 of byte0.
        $rawFlags = $byte0 >> 2;

        // The UNUSED bit (1<<0) in raw flags coincides with 0.7 CONTROL (1<<0),
        // which lets a receiver auto-detect sixup control packets even without
        // prior connection state.  We honour that, but only when $sixup is not
        // already set — the caller's context takes precedence.
        if (!$sixup && ($rawFlags & self::FLAG_UNUSED)) {
            $sixup = true;
        }

        $dataStart = $sixup ? self::HEADER_SIZE_07 : self::HEADER_SIZE_06;

        if ($size < $dataStart) {
            throw new \InvalidArgumentException(
                "Packet too short for " . ($sixup ? '0.7' : '0.6') . " header: {$size} bytes (need {$dataStart})"
            );
        }

        $ack       = (($byte0 & 0x03) << 8) | $byte1;
        $numChunks = $byte2;

        // Extract security token (sixup only).
        $token = null;
        if ($sixup) {
            $token = (ord($bytes[3]) << 24)
                   | (ord($bytes[4]) << 16)
                   | (ord($bytes[5]) <<  8)
                   |  ord($bytes[6]);
            // Treat as unsigned 32-bit.
            $token &= 0xffffffff;

            // Remap 0.7 wire flags → internal 0.6 representation.
            $rawFlags = $this->sevenToSix($rawFlags);
        }

        $flags = $rawFlags;

        // Extract payload.
        $payload = substr($bytes, $dataStart);

        // Decompress if the COMPRESSION flag is set.
        if ($flags & self::FLAG_COMPRESSION) {
            $decompressed = $this->huffman->decompress($payload);
            if ($decompressed === false) {
                throw new \InvalidArgumentException('Huffman decompression failed');
            }
            $payload = $decompressed;
        }

        $isControl = (bool) ($flags & self::FLAG_CONTROL);

        if ($isControl) {
            return [
                'flags'     => $flags,
                'ack'       => $ack,
                'numChunks' => $numChunks,
                'token'     => $token,
                'chunks'    => [],
                'control'   => $payload,
            ];
        }

        $chunks = $this->unpackChunks($payload, $numChunks, $sixup);

        return [
            'flags'     => $flags,
            'ack'       => $ack,
            'numChunks' => $numChunks,
            'token'     => $token,
            'chunks'    => $chunks,
            'control'   => '',
        ];
    }

    // -----------------------------------------------------------------------
    // Chunk framing (CNetChunkHeader::Pack / Unpack, Split-aware)
    // -----------------------------------------------------------------------

    /**
     * Serialise an array of chunks into a binary payload.
     *
     * Chunk header layout (from CNetChunkHeader::Pack in ddnet/network.cpp):
     *   Split = 4 for 0.6, Split = 6 for 0.7 (sixup).
     *
     *   byte0 = (flags & 3) << 6  |  (size >> Split) & 0x3f
     *   byte1 = size & ((1 << Split) - 1)
     *   [if VITAL]
     *   byte1 |= (seq >> 2) & ~((1 << Split) - 1) & 0xff
     *   byte2  = seq & 0xff
     *
     * Split=4 masks: byte1 low = 0x0f, byte1 seq-high = 0xf0
     * Split=6 masks: byte1 low = 0x3f, byte1 seq-high = 0xc0
     *
     * @param  array<array{vital: bool, seq: int, data: string}> $chunks
     */
    private function packChunks(array $chunks, bool $sixup): string
    {
        $split     = $sixup ? 6 : 4;
        $lowMask   = (1 << $split) - 1;   // 0x0f (split=4) or 0x3f (split=6)
        $highMask  = (~$lowMask) & 0xff;   // 0xf0 (split=4) or 0xc0 (split=6)
        $payload   = '';

        foreach ($chunks as $chunk) {
            $vital = !empty($chunk['vital']);
            $data  = $chunk['data'] ?? '';
            $size  = strlen($data);
            $seq   = (int) ($chunk['seq'] ?? 0);

            $chunkFlags = $vital ? self::CHUNK_FLAG_VITAL : 0;

            // byte0: high 6 bits of size go into the low 6 bits of byte0
            $b0 = (($chunkFlags & 3) << 6) | (($size >> $split) & 0x3f);
            // byte1: low Split bits of size
            $b1 = $size & $lowMask;

            if ($vital) {
                // Upper bits of byte1 carry the high part of seq.
                // C++: pData[1] |= (seq >> 2) & (~((1 << Split) - 1))
                $b1 |= ($seq >> 2) & $highMask;
                $b2  = $seq & 0xff;

                $payload .= chr($b0) . chr($b1) . chr($b2) . $data;
            } else {
                $payload .= chr($b0) . chr($b1) . $data;
            }
        }

        return $payload;
    }

    /**
     * Parse exactly $expectedCount chunks out of a (decompressed) payload buffer.
     *
     * Unpack layout (from CNetChunkHeader::Unpack in ddnet/network.cpp):
     *   Split = 4 for 0.6, Split = 6 for 0.7 (sixup).
     *
     *   flags = (byte0 >> 6) & 3
     *   size  = (byte0 & 0x3f) << Split  |  byte1 & ((1 << Split) - 1)
     *   [if VITAL]
     *   seq   = (byte1 & ~((1 << Split) - 1)) << 2  |  byte2
     *   [else]
     *   seq   = -1
     *
     * DDNet reads exactly m_NumChunks chunks and does not loop to exhaustion;
     * we mirror that: throw if the payload runs out before $expectedCount chunks.
     *
     * @return array<array{vital: bool, seq: int, data: string}>
     * @throws \InvalidArgumentException on malformed or truncated input
     */
    private function unpackChunks(string $payload, int $expectedCount, bool $sixup): array
    {
        $split    = $sixup ? 6 : 4;
        $lowMask  = (1 << $split) - 1;   // 0x0f (split=4) or 0x3f (split=6)
        $highMask = (~$lowMask) & 0xff;   // 0xf0 (split=4) or 0xc0 (split=6)

        $chunks = [];
        $offset = 0;
        $length = strlen($payload);

        for ($i = 0; $i < $expectedCount; $i++) {
            if ($offset + 2 > $length) {
                throw new \InvalidArgumentException(
                    "Payload exhausted after {$i} chunks (expected {$expectedCount}); truncated at offset {$offset}"
                );
            }

            $b0 = ord($payload[$offset]);
            $b1 = ord($payload[$offset + 1]);

            $chunkFlags = ($b0 >> 6) & 3;
            // size = high 6 bits from byte0 shifted up by Split, low Split bits from byte1
            $size  = (($b0 & 0x3f) << $split) | ($b1 & $lowMask);
            $vital = (bool) ($chunkFlags & self::CHUNK_FLAG_VITAL);

            if ($vital) {
                if ($offset + 3 > $length) {
                    throw new \InvalidArgumentException(
                        "Truncated vital chunk header at offset {$offset}"
                    );
                }
                $b2 = ord($payload[$offset + 2]);
                // C++: seq = ((pData[1] & ~((1 << Split) - 1)) << 2) | pData[2]
                $seq        = (($b1 & $highMask) << 2) | $b2;
                $dataOffset = $offset + 3;
            } else {
                $seq        = -1;
                $dataOffset = $offset + 2;
            }

            if ($dataOffset + $size > $length) {
                throw new \InvalidArgumentException(
                    "Chunk data overflows payload at offset {$dataOffset} (size={$size})"
                );
            }

            $chunks[] = [
                'vital' => $vital,
                'seq'   => $seq,
                'data'  => substr($payload, $dataOffset, $size),
            ];

            $offset = $dataOffset + $size;
        }

        return $chunks;
    }

    // -----------------------------------------------------------------------
    // Flag remapping (protocolglue.cpp)
    // -----------------------------------------------------------------------

    /**
     * Remap internal 0.6 flags → 0.7 wire flags before encoding.
     *
     * 0.6 bit positions: CONTROL=1<<2, RESEND=1<<4, COMPRESSION=1<<5
     * 0.7 bit positions: CONTROL=1<<0, RESEND=1<<1, COMPRESSION=1<<2
     */
    private function sixToSeven(int $flags): int
    {
        $seven = 0;
        if ($flags & self::FLAG_CONTROL)     $seven |= self::P7_FLAG_CONTROL;
        if ($flags & self::FLAG_RESEND)      $seven |= self::P7_FLAG_RESEND;
        if ($flags & self::FLAG_COMPRESSION) $seven |= self::P7_FLAG_COMPRESSION;
        return $seven;
    }

    /**
     * Remap 0.7 wire flags → internal 0.6 flags after decoding.
     *
     * The UNUSED bit (1<<0) was used as a wire-only sixup marker; it is not
     * part of the 0.6 flag vocabulary, so it is stripped here.  Callers learn
     * the packet is sixup from the $sixup parameter they passed to unpack().
     */
    private function sevenToSix(int $flags): int
    {
        $six = 0;
        if ($flags & self::P7_FLAG_CONTROL)     $six |= self::FLAG_CONTROL;
        if ($flags & self::P7_FLAG_RESEND)       $six |= self::FLAG_RESEND;
        if ($flags & self::P7_FLAG_COMPRESSION)  $six |= self::FLAG_COMPRESSION;
        return $six;
    }
}
