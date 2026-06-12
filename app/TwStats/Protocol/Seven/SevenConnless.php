<?php

namespace App\TwStats\Protocol\Seven;

/**
 * Frames and parses Teeworlds 0.7 connless packets and the token-handshake control packets.
 * 0.7 connless packets carry a 9-byte header (flag/version byte + 4-byte server token + 4-byte
 * response token); a server only answers once the client has obtained its token via a
 * NET_CTRLMSG_TOKEN control exchange. Mirrors network.cpp.
 */
final class SevenConnless
{
    private const PACKETFLAG_CONNLESS = 8;
    private const PACKETFLAG_CONTROL = 1;
    private const PACKETVERSION = 1;
    private const CTRLMSG_TOKEN = 5;
    private const TOKEN_NONE = 0xFFFFFFFF;
    private const TOKENREQUEST_DATASIZE = 512;

    private const CONNLESS_HEADER = 9;
    private const PACKET_HEADER = 7;

    /**
     * the 7-byte control header + [NET_CTRLMSG_TOKEN][my token] that asks a server for its token
     */
    public static function tokenRequest(int $myToken): string
    {
        $header = chr((self::PACKETFLAG_CONTROL << 2) & 0xFC)
            . "\x00"  // ack low
            . "\x00"  // num chunks
            . self::packToken(self::TOKEN_NONE);

        // pad the token buffer to NET_TOKENREQUEST_DATASIZE so the request is at least as large
        // as the response: the anti-amplification measure a 0.7 server requires before it hands
        // out its token (network.cpp SendControlMsgWithToken with Extended=true)
        $tokenBuffer = self::packToken($myToken) . str_repeat("\x00", self::TOKENREQUEST_DATASIZE - 4);

        return $header . chr(self::CTRLMSG_TOKEN) . $tokenBuffer;
    }

    /**
     * extract the server's token from a NET_CTRLMSG_TOKEN control response, or null if the
     * packet is not a control-token packet
     */
    public static function parseTokenResponse(string $packet): ?int
    {
        if (strlen($packet) < self::PACKET_HEADER + 5) {
            return null;
        }

        $flags = (ord($packet[0]) & 0xFC) >> 2;
        if (!($flags & self::PACKETFLAG_CONTROL)) {
            return null;
        }

        $payload = substr($packet, self::PACKET_HEADER);
        if ($payload === '' || ord($payload[0]) !== self::CTRLMSG_TOKEN) {
            return null;
        }

        return self::unpackToken(substr($payload, 1, 4));
    }

    /**
     * a connless packet: 0x21 header + server token + response (client) token + data
     */
    public static function connless(int $serverToken, int $myToken, string $data): string
    {
        $first = (self::PACKETFLAG_CONNLESS << 2) & 0xFC | (self::PACKETVERSION & 0x03);

        return chr($first) . self::packToken($serverToken) . self::packToken($myToken) . $data;
    }

    /**
     * @return array{token: int, response_token: int, data: string}|null
     */
    public static function parseConnless(string $packet): ?array
    {
        if (strlen($packet) < self::CONNLESS_HEADER) {
            return null;
        }

        $flags = (ord($packet[0]) & 0xFC) >> 2;
        if (!($flags & self::PACKETFLAG_CONNLESS)) {
            return null;
        }

        return [
            'token' => self::unpackToken(substr($packet, 1, 4)),
            'response_token' => self::unpackToken(substr($packet, 5, 4)),
            'data' => substr($packet, self::CONNLESS_HEADER),
        ];
    }

    private static function packToken(int $token): string
    {
        return pack('N', $token & 0xFFFFFFFF);
    }

    private static function unpackToken(string $bytes): int
    {
        return unpack('N', $bytes)[1];
    }
}
