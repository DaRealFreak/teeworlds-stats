<?php

declare(strict_types=1);

namespace Tests\Unit\Connection;

use App\TwStats\Connection\ControlMessages;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that ControlMessages builds and parses the control-message payloads
 * faithfully against the DDNet source (network_conn.cpp / network.cpp / protocol7.h).
 *
 * All assertions are against exact byte sequences so that any future drift from
 * the wire format is caught immediately.
 */
class ControlMessagesTest extends TestCase
{
    // -----------------------------------------------------------------------
    // 0.6 constants
    // -----------------------------------------------------------------------

    public function test_06_constants(): void
    {
        $this->assertSame(0, ControlMessages::KEEPALIVE);
        $this->assertSame(1, ControlMessages::CONNECT);
        $this->assertSame(2, ControlMessages::CONNECTACCEPT);
        $this->assertSame(3, ControlMessages::ACCEPT);
        $this->assertSame(4, ControlMessages::CLOSE);
    }

    // -----------------------------------------------------------------------
    // 0.7 constants (protocol7.h enum: KEEPALIVE=0, CONNECT=1, ACCEPT=2,
    // UNUSED=3, CLOSE=4, TOKEN=5)
    // -----------------------------------------------------------------------

    public function test_07_constants(): void
    {
        $this->assertSame(0, ControlMessages::P7_KEEPALIVE);
        $this->assertSame(1, ControlMessages::P7_CONNECT);
        $this->assertSame(2, ControlMessages::P7_ACCEPT);
        $this->assertSame(3, ControlMessages::P7_UNUSED);
        $this->assertSame(4, ControlMessages::P7_CLOSE);
        $this->assertSame(5, ControlMessages::P7_TOKEN);
    }

    // -----------------------------------------------------------------------
    // SECURITY_TOKEN_MAGIC = {'T','K','E','N'}
    // Source: ddnet/src/engine/shared/network.cpp line 19
    // -----------------------------------------------------------------------

    public function test_security_token_magic_bytes(): void
    {
        // The magic constant is defined in network.cpp as {'T','K','E','N'}.
        $this->assertSame('TKEN', ControlMessages::SECURITY_TOKEN_MAGIC);
    }

    // -----------------------------------------------------------------------
    // connect06
    // -----------------------------------------------------------------------

    public function test_connect06_starts_with_connect_byte(): void
    {
        $payload = ControlMessages::connect06();
        $this->assertSame(ControlMessages::CONNECT, ord($payload[0]));
    }

    public function test_connect06_carries_security_token_magic(): void
    {
        // network_conn.cpp line 193: extra = SECURITY_TOKEN_MAGIC (4 bytes).
        $payload = ControlMessages::connect06();
        $this->assertSame(5, strlen($payload));
        $this->assertSame('TKEN', substr($payload, 1, 4));
    }

    // -----------------------------------------------------------------------
    // accept
    // -----------------------------------------------------------------------

    public function test_accept_is_single_accept_byte(): void
    {
        // network_conn.cpp line 490: SendControl(NET_CTRLMSG_ACCEPT, nullptr, 0)
        $payload = ControlMessages::accept();
        $this->assertSame(1, strlen($payload));
        $this->assertSame(ControlMessages::ACCEPT, ord($payload[0]));
    }

    // -----------------------------------------------------------------------
    // close
    // -----------------------------------------------------------------------

    public function test_close_without_reason_is_single_close_byte(): void
    {
        $payload = ControlMessages::close();
        $this->assertSame(1, strlen($payload));
        $this->assertSame(ControlMessages::CLOSE, ord($payload[0]));
    }

    public function test_close_with_reason_appends_nul_terminated_reason(): void
    {
        // network_conn.cpp line 290: SendControl(NET_CTRLMSG_CLOSE, pReason, str_length(pReason)+1)
        $payload = ControlMessages::close('bye');
        $this->assertSame(5, strlen($payload));
        $this->assertSame(ControlMessages::CLOSE, ord($payload[0]));
        $this->assertSame("bye\x00", substr($payload, 1));
    }

    // -----------------------------------------------------------------------
    // keepAlive
    // -----------------------------------------------------------------------

    public function test_keepalive_is_single_keepalive_byte(): void
    {
        // network_conn.cpp line 578: SendControl(NET_CTRLMSG_KEEPALIVE, nullptr, 0)
        $payload = ControlMessages::keepAlive();
        $this->assertSame(1, strlen($payload));
        $this->assertSame(ControlMessages::KEEPALIVE, ord($payload[0]));
    }

    // -----------------------------------------------------------------------
    // parse
    // -----------------------------------------------------------------------

    public function test_parse_connect06_round_trips(): void
    {
        $payload = ControlMessages::connect06();
        $result = ControlMessages::parse($payload);

        $this->assertSame(ControlMessages::CONNECT, $result['ctrlMsg']);
        $this->assertSame('TKEN', $result['extra']);
    }

    public function test_parse_accept_round_trips(): void
    {
        $result = ControlMessages::parse(ControlMessages::accept());
        $this->assertSame(ControlMessages::ACCEPT, $result['ctrlMsg']);
        $this->assertSame('', $result['extra']);
    }

    public function test_parse_close_with_reason(): void
    {
        $result = ControlMessages::parse(ControlMessages::close('bye'));
        $this->assertSame(ControlMessages::CLOSE, $result['ctrlMsg']);
        $this->assertSame("bye\x00", $result['extra']);
    }

    public function test_parse_keepalive(): void
    {
        $result = ControlMessages::parse(ControlMessages::keepAlive());
        $this->assertSame(ControlMessages::KEEPALIVE, $result['ctrlMsg']);
        $this->assertSame('', $result['extra']);
    }

    // -----------------------------------------------------------------------
    // securityTokenFromConnectAccept
    // -----------------------------------------------------------------------

    public function test_security_token_from_connect_accept_extracts_4_byte_token(): void
    {
        // network_conn.cpp line 479: m_SecurityToken = ToSecurityToken(&pPacket->m_aChunkData[1 + sizeof(SECURITY_TOKEN_MAGIC)])
        // The CONNECTACCEPT extra is: SECURITY_TOKEN_MAGIC (4 bytes) + token (4 bytes, big-endian)
        $token = 0xDEADBEEF;
        $extra = 'TKEN' . pack('N', $token);

        $this->assertSame($token, ControlMessages::securityTokenFromConnectAccept($extra));
    }

    public function test_security_token_from_connect_accept_with_small_token(): void
    {
        $token = 42;
        $extra = 'TKEN' . pack('N', $token);

        $this->assertSame($token, ControlMessages::securityTokenFromConnectAccept($extra));
    }

    // -----------------------------------------------------------------------
    // 0.7 — tokenRequest07
    // -----------------------------------------------------------------------

    public function test_token_request07_first_byte_is_p7_token(): void
    {
        // network_conn.cpp line 260: SendControlWithToken7(protocol7::NET_CTRLMSG_TOKEN, NET_TOKEN_NONE)
        // The payload byte is P7_TOKEN = 5; the rest carries the client token + padding.
        $payload = ControlMessages::tokenRequest07(0xABCD1234);
        $this->assertSame(ControlMessages::P7_TOKEN, ord($payload[0]));
    }

    public function test_token_request07_encodes_my_token_big_endian(): void
    {
        $myToken = 0xABCD1234;
        $payload = ControlMessages::tokenRequest07($myToken);
        // The client token occupies bytes 1–4 of the payload (big-endian).
        $extracted = unpack('N', substr($payload, 1, 4))[1];
        $this->assertSame($myToken, $extracted);
    }

    public function test_token_request07_is_padded_to_512_bytes(): void
    {
        // SendControlMsgWithToken7 with Extended=true pads to NET_TOKENREQUEST_DATASIZE=512.
        // The payload is: [ctrlMsgByte] + 512 bytes = 513 bytes total.
        $payload = ControlMessages::tokenRequest07(0x01020304);
        $this->assertSame(513, strlen($payload));
    }

    // -----------------------------------------------------------------------
    // 0.7 — connect07
    // -----------------------------------------------------------------------

    public function test_connect07_first_byte_is_connect(): void
    {
        // network_conn.cpp line 426: SendControlWithToken7(NET_CTRLMSG_CONNECT, m_SecurityToken)
        // Uses the 0.6 NET_CTRLMSG_CONNECT=1 byte, sent via the token-framed helper.
        $payload = ControlMessages::connect07(0x12345678);
        $this->assertSame(ControlMessages::CONNECT, ord($payload[0]));
    }

    public function test_connect07_encodes_token_big_endian(): void
    {
        $token = 0x12345678;
        $payload = ControlMessages::connect07($token);
        // Byte 0 is the CONNECT control number; bytes 1–4 are the token big-endian;
        // the remaining 508 bytes are NUL padding (Extended=true, network_conn.cpp:240).
        $this->assertSame(ControlMessages::CONNECT, ord($payload[0]));
        $extracted = unpack('N', substr($payload, 1, 4))[1];
        $this->assertSame($token, $extracted);
        $this->assertSame(str_repeat("\x00", 508), substr($payload, 5));
    }

    public function test_connect07_is_513_bytes(): void
    {
        // SendControlWithToken7 ALWAYS calls SendControlMsgWithToken7 with Extended=true
        // (network_conn.cpp:240), padding the token buffer to NET_TOKENREQUEST_DATASIZE=512.
        // Payload = [ctrlMsgByte] + 512 bytes = 513 bytes total.
        $payload = ControlMessages::connect07(0x12345678);
        $this->assertSame(513, strlen($payload));
    }

    // -----------------------------------------------------------------------
    // parse — empty-payload sentinel
    // -----------------------------------------------------------------------

    public function test_parse_empty_payload_returns_minus_one_sentinel(): void
    {
        // -1 is returned for an empty payload so it cannot alias KEEPALIVE (0).
        $result = ControlMessages::parse('');
        $this->assertSame(-1, $result['ctrlMsg']);
        $this->assertSame('', $result['extra']);
    }
}
