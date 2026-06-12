<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixListCodec;
use PHPUnit\Framework\TestCase;

class SixListCodecTest extends TestCase
{
    public function test_parses_an_ipv4_mapped_entry_with_protocol_six(): void
    {
        // ::ffff:1.2.3.4 : 8303
        $entry = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff" . "\x01\x02\x03\x04" . "\x20\x6f";

        $addresses = (new SixListCodec())->parse($entry);

        $this->assertCount(1, $addresses);
        $this->assertSame('1.2.3.4', $addresses[0]->ip);
        $this->assertSame(8303, $addresses[0]->port);
        $this->assertSame(6, $addresses[0]->protocol);
    }

    public function test_parses_multiple_entries_and_ignores_a_trailing_partial(): void
    {
        $a = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x0a\x00\x00\x01\x20\x6f";
        $b = "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff\x0a\x00\x00\x02\x20\x70";

        $addresses = (new SixListCodec())->parse($a . $b . "\x00\x01\x02");

        $this->assertCount(2, $addresses);
        $this->assertSame('10.0.0.1', $addresses[0]->ip);
        $this->assertSame('10.0.0.2', $addresses[1]->ip);
        $this->assertSame(8304, $addresses[1]->port);
    }
}
