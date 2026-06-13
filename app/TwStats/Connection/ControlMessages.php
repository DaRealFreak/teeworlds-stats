<?php

declare(strict_types=1);

namespace App\TwStats\Connection;

/**
 * Builds and parses Teeworlds control-message payloads for 0.6 and 0.7.
 *
 * A control message payload is: [ctrlMsgByte] + extra bytes.  The caller is
 * responsible for wrapping the payload in a CONTROL packet via PacketCodec.
 *
 * 0.6 control-message numbers (network.h):
 *   KEEPALIVE=0, CONNECT=1, CONNECTACCEPT=2, ACCEPT=3, CLOSE=4
 *
 * 0.7 control-message numbers (protocol7.h):
 *   KEEPALIVE=0, CONNECT=1, ACCEPT=2, UNUSED=3, CLOSE=4, TOKEN=5
 *
 * SECURITY_TOKEN_MAGIC = {'T','K','E','N'} (network.cpp line 19).
 *
 * Source references: ddnet/src/engine/shared/network_conn.cpp,
 *   ddnet/src/engine/shared/network.cpp,
 *   ddnet/src/engine/shared/protocol7.h.
 */
final class ControlMessages
{
    // -----------------------------------------------------------------------
    // 0.6 control-message numbers (network.h, enum starting at 96)
    // -----------------------------------------------------------------------
    public const KEEPALIVE      = 0;
    public const CONNECT        = 1;
    public const CONNECTACCEPT  = 2;
    public const ACCEPT         = 3;
    public const CLOSE          = 4;

    // -----------------------------------------------------------------------
    // 0.7 control-message numbers (protocol7.h enum)
    // -----------------------------------------------------------------------
    public const P7_KEEPALIVE   = 0;
    public const P7_CONNECT     = 1;
    public const P7_ACCEPT      = 2;
    public const P7_UNUSED      = 3;
    public const P7_CLOSE       = 4;
    public const P7_TOKEN       = 5;

    // -----------------------------------------------------------------------
    // SECURITY_TOKEN_MAGIC: {'T','K','E','N'} — network.cpp line 19.
    // Sent as extra in CONNECT (0.6) and CONNECTACCEPT to signal DDNet-token
    // support; its presence as the first 4 bytes of CONNECTACCEPT extra tells
    // the client the following 4 bytes are the real security token.
    // -----------------------------------------------------------------------
    public const SECURITY_TOKEN_MAGIC = 'TKEN';

    /** DDNet-style 0.6 CONNECT payload: CONNECT byte + SECURITY_TOKEN_MAGIC. */
    public static function connect06(): string
    {
        return chr(self::CONNECT) . self::SECURITY_TOKEN_MAGIC;
    }

    /**
     * 0.7 CONNECT payload: sent after the TOKEN handshake via
     * SendControlWithToken7(NET_CTRLMSG_CONNECT, serverToken).
     * SendControlWithToken7 ALWAYS calls SendControlMsgWithToken7 with Extended=true
     * (network_conn.cpp line 240), so the token buffer is padded to
     * NET_TOKENREQUEST_DATASIZE=512 bytes — identical structure to tokenRequest07()
     * but with the CONNECT control byte (1) and the client's own token.
     *
     * Payload = [CONNECT byte] + [token 4 bytes BE] + [508 zero bytes].
     * Total = 513 bytes.
     */
    public static function connect07(int $token): string
    {
        $tokenBuffer = pack('N', $token & 0xFFFFFFFF) . str_repeat("\x00", 512 - 4);

        return chr(self::CONNECT) . $tokenBuffer;
    }

    /** 0.6 ACCEPT payload: single ACCEPT byte, no extra (network_conn.cpp line 490). */
    public static function accept(): string
    {
        return chr(self::ACCEPT);
    }

    /**
     * CLOSE payload: CLOSE byte + NUL-terminated reason if non-empty.
     * Mirrors network_conn.cpp line 290: SendControl(NET_CTRLMSG_CLOSE, pReason, str_length(pReason)+1).
     */
    public static function close(string $reason = ''): string
    {
        if ($reason === '') {
            return chr(self::CLOSE);
        }

        return chr(self::CLOSE) . $reason . "\x00";
    }

    /** KEEPALIVE payload: single KEEPALIVE byte, no extra (network_conn.cpp line 578). */
    public static function keepAlive(): string
    {
        return chr(self::KEEPALIVE);
    }

    /**
     * 0.7 TOKEN-request payload.
     *
     * Mirrors SendControlMsgWithToken7 with Extended=true (network.cpp line 393):
     * the client token occupies bytes 0–3 of a 512-byte buffer (NET_TOKENREQUEST_DATASIZE);
     * the anti-amplification measure requires the request to be at least as large as the
     * response before the server hands out its token.
     *
     * Payload = [P7_TOKEN byte] + [myToken 4 bytes BE] + [508 zero bytes].
     * Total = 513 bytes.
     */
    public static function tokenRequest07(int $myToken): string
    {
        $tokenBuffer = pack('N', $myToken & 0xFFFFFFFF) . str_repeat("\x00", 512 - 4);

        return chr(self::P7_TOKEN) . $tokenBuffer;
    }

    /**
     * Split a raw control payload into the control-message byte and the rest.
     *
     * @return array{ctrlMsg: int, extra: string}
     */
    public static function parse(string $payload): array
    {
        if ($payload === '') {
            // -1 is an unambiguous "no control message" sentinel; 0 would alias KEEPALIVE.
            return ['ctrlMsg' => -1, 'extra' => ''];
        }

        return [
            'ctrlMsg' => ord($payload[0]),
            'extra'   => substr($payload, 1),
        ];
    }

    /**
     * Extract the 4-byte security token from a CONNECTACCEPT payload's extra bytes.
     *
     * network_conn.cpp line 479: the extra is SECURITY_TOKEN_MAGIC (4 bytes)
     * followed by the security token (4 bytes, big-endian).
     */
    public static function securityTokenFromConnectAccept(string $extra): int
    {
        return unpack('N', substr($extra, 4, 4))[1];
    }
}
