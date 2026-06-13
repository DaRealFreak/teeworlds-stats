<?php

declare(strict_types=1);

namespace Tests\Unit\Connection;

use App\TwStats\Connection\Huffman;
use PHPUnit\Framework\TestCase;

class HuffmanTest extends TestCase
{
    /**
     * Authoritative compatibility vector from ddnet/src/test/huffman_test.cpp.
     * Input: bytes 0x00..0x07 followed by 56 NUL bytes (64 bytes total).
     * Expected compressed output: exactly these 15 bytes.
     */
    public function test_matches_the_teeworlds_compatibility_vector(): void
    {
        $input = '';
        for ($i = 0; $i < 8; $i++) {
            $input .= chr($i);
        }
        $input .= str_repeat("\x00", 56); // 64 bytes total

        $expected = "\x51\x58\x78\x76\x1B\xB7\xFF\xFF\xFF\xFF\xFF\xFF\x7F\xC5\x0D";

        $this->assertSame(bin2hex($expected), bin2hex((new Huffman())->compress($input)));
    }

    /** Round-trip: decompress(compress(input)) === input for empty string. */
    public function test_roundtrip_empty(): void
    {
        $huffman = new Huffman();
        $this->assertSame('', $huffman->decompress($huffman->compress('')));
    }

    /** Round-trip: single character. */
    public function test_roundtrip_single_byte(): void
    {
        $huffman = new Huffman();
        $this->assertSame('A', $huffman->decompress($huffman->compress('A')));
    }

    /** Round-trip: multi-byte ASCII string. */
    public function test_roundtrip_hello_teeworlds(): void
    {
        $huffman = new Huffman();
        $input = 'hello teeworlds';
        $this->assertSame($input, $huffman->decompress($huffman->compress($input)));
    }

    /** Round-trip: all 256 byte values (fixed pattern). */
    public function test_roundtrip_full_byte_range(): void
    {
        $huffman = new Huffman();
        $input = '';
        for ($i = 0; $i < 256; $i++) {
            $input .= chr($i % 256);
        }
        $this->assertSame($input, $huffman->decompress($huffman->compress($input)));
    }

    /** Decompress the compatibility vector back to the original 64-byte input. */
    public function test_decompress_compatibility_vector(): void
    {
        $expected = '';
        for ($i = 0; $i < 8; $i++) {
            $expected .= chr($i);
        }
        $expected .= str_repeat("\x00", 56);

        $compressed = "\x51\x58\x78\x76\x1B\xB7\xFF\xFF\xFF\xFF\xFF\xFF\x7F\xC5\x0D";

        $this->assertSame(bin2hex($expected), bin2hex((new Huffman())->decompress($compressed)));
    }
}
