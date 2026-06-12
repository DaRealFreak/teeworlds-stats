<?php

namespace App\TwStats\Protocol\Seven;

/**
 * The Teeworlds variable-length integer used by the 0.7 packer. First byte holds an extend
 * bit (0x80), a sign bit (0x40) and 6 data bits; continuation bytes hold an extend bit and
 * 7 data bits. A set sign bit means the value is the bitwise complement of the magnitude.
 * Mirrors CVariableInt in the reference (compression.cpp).
 */
final class VariableInt
{
    public static function pack(int $value): string
    {
        $first = 0;
        if ($value < 0) {
            $first |= 0x40; // sign
            $value = ~$value;
        }

        $first |= $value & 0x3F;
        $value >>= 6;

        $bytes = '';
        while ($value !== 0) {
            $first |= 0x80; // extend
            $bytes .= chr($first);
            $first = $value & 0x7F;
            $value >>= 7;
        }
        $bytes .= chr($first);

        return $bytes;
    }

    /**
     * @return array{0: int, 1: int} the decoded value and the offset just past it
     */
    public static function unpack(string $buffer, int $offset): array
    {
        $byte = ord($buffer[$offset]);
        $sign = ($byte >> 6) & 1;
        $value = $byte & 0x3F;

        $masks = [0x7F, 0x7F, 0x7F, 0x0F];
        $shifts = [6, 13, 20, 27];

        for ($i = 0; $i < 4; $i++) {
            if (!($byte & 0x80)) {
                break;
            }
            $offset++;
            // truncated: the extend bit is set but no continuation byte remains. Bail; the
            // resulting offset (past the buffer end) flags the over-read to the Unpacker caller.
            if ($offset >= strlen($buffer)) {
                break;
            }
            $byte = ord($buffer[$offset]);
            $value |= ($byte & $masks[$i]) << $shifts[$i];
        }

        $offset++;
        if ($sign) {
            $value = ~$value;
        }

        // keep PHP's wide int in 32-bit two's-complement range, matching the C int
        $value &= 0xFFFFFFFF;
        if ($value & 0x80000000) {
            $value -= 0x100000000;
        }

        return [$value, $offset];
    }
}
