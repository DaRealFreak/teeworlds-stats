<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\SevenListCodec;
use PHPUnit\Framework\TestCase;

class SevenListCodecTest extends TestCase
{
    public function test_parses_ipv4_mapped_and_ipv6_entries(): void
    {
        // IPv4 192.0.2.50:8303 as an IPv4-mapped 16-byte address + port 8303 (0x206f)
        $ipv4 = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . "\xc0\x00\x02\x32" . "\x20\x6f";
        // IPv6 2001:db8::5:8310 (port 0x2076)
        $ipv6 = "\x20\x01\x0d\xb8\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x05" . "\x20\x76";

        $addresses = (new SevenListCodec())->parse($ipv4 . $ipv6);

        $this->assertCount(2, $addresses);
        $this->assertSame('192.0.2.50', $addresses[0]->ip);
        $this->assertSame(8303, $addresses[0]->port);
        $this->assertSame(7, $addresses[0]->protocol);
        $this->assertSame('2001:db8::5', $addresses[1]->ip);
        $this->assertSame(8310, $addresses[1]->port);
        $this->assertSame(7, $addresses[1]->protocol);
    }

    public function test_ignores_a_trailing_partial_entry(): void
    {
        $this->assertSame([], (new SevenListCodec())->parse("\x00\x00\x01")); // < 18 bytes
    }
}
