<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixConnless;
use PHPUnit\Framework\TestCase;

class SixConnlessTest extends TestCase
{
    public function test_get_list_builds_extended_header_plus_req2(): void
    {
        $packet = SixConnless::getList("\x12\x34");

        // "xe" + 4-byte extra (2 token bytes + "\0\0") + "\xff\xff\xff\xff" + "req2"
        $this->assertSame("xe\x12\x34\x00\x00\xff\xff\xff\xffreq2", $packet);
    }

    public function test_get_info_appends_the_one_byte_info_token(): void
    {
        $packet = SixConnless::getInfo("\xaa\xbb", "\x7f");

        $this->assertSame("xe\xaa\xbb\x00\x00\xff\xff\xff\xffgie3\x7f", $packet);
    }

    public function test_parse_extracts_command_and_payload_at_the_right_offsets(): void
    {
        // 6-byte framing + 8-byte command + payload
        $datagram = "xe\x00\x00\x00\x00\xff\xff\xff\xfflis2" . "PAYLOAD";

        $parsed = SixConnless::parse($datagram);

        $this->assertSame('lis2', $parsed['command']);
        $this->assertSame('PAYLOAD', $parsed['payload']);
    }

    public function test_parse_returns_null_for_a_too_short_datagram(): void
    {
        $this->assertNull(SixConnless::parse('short'));
    }

    public function test_parse_returns_null_when_the_extended_header_is_missing(): void
    {
        // 14 bytes but not starting with "xe"
        $this->assertNull(SixConnless::parse("\xff\xff\xff\xff\xff\xff\xff\xff\xff\xfflis2"));
    }
}
