<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\SevenConnless;
use PHPUnit\Framework\TestCase;

class SevenConnlessTest extends TestCase
{
    public function test_builds_a_token_request_with_the_none_token_and_client_token(): void
    {
        $request = SevenConnless::tokenRequest(0x11223344);

        // 7-byte control header + control byte + 4-byte client token
        $this->assertSame('0400'.'00'.'ffffffff'.'05'.'11223344', bin2hex($request));
    }

    public function test_parses_a_token_response_payload(): void
    {
        // a control packet: header (flags=CONTROL), then [NET_CTRLMSG_TOKEN=5][server token]
        $packet = "\x04\x00\x00\xff\xff\xff\xff" . "\x05" . "\xaa\xbb\xcc\xdd";

        $this->assertSame(0xAABBCCDD, SevenConnless::parseTokenResponse($packet));
    }

    public function test_returns_null_when_a_packet_is_not_a_token_response(): void
    {
        $this->assertNull(SevenConnless::parseTokenResponse("\x04\x00\x00\x00\x00\x00\x00\x00")); // not enough / wrong ctrl
        $this->assertNull(SevenConnless::parseTokenResponse('')); // empty
    }

    public function test_builds_a_connless_packet_with_the_0x21_header(): void
    {
        $packet = SevenConnless::connless(0xAABBCCDD, 0x11223344, 'DATA');

        $this->assertSame('21'.'aabbccdd'.'11223344'.bin2hex('DATA'), bin2hex($packet));
    }

    public function test_parses_a_connless_response_into_its_data(): void
    {
        $packet = "\x21" . "\xaa\xbb\xcc\xdd" . "\x11\x22\x33\x44" . 'PAYLOAD';

        $parsed = SevenConnless::parseConnless($packet);

        $this->assertNotNull($parsed);
        $this->assertSame(0xAABBCCDD, $parsed['token']);
        $this->assertSame(0x11223344, $parsed['response_token']);
        $this->assertSame('PAYLOAD', $parsed['data']);
    }

    public function test_rejects_a_non_connless_or_truncated_packet(): void
    {
        $this->assertNull(SevenConnless::parseConnless("\x04\x00\x00")); // too short / not connless
    }
}
