<?php

namespace App\TwStats\Protocol\Six;

/**
 * Frames and parses Teeworlds 0.6 "token-extended" connless packets. A request is the 2-byte
 * NET_HEADER_EXTENDED ("xe") + a 4-byte extra token + "\xff\xff\xff\xff" + a 4-char command;
 * the extended header is what makes a 0.6 server answer with extended (iext) info rather than
 * the 16-capped vanilla inf3. A response carries the same 6-byte framing, then an 8-byte
 * command at offset 6 and the payload at offset 14. Mirrors ddnet network.cpp SendPacketConnless.
 */
final class SixConnless
{
    private const HEADER_EXTENDED = 'xe';
    private const EXTRA_SIZE = 4;          // NET_CONNLESS_EXTRA_SIZE
    private const FRAMING_SIZE = 6;        // "xe" + 4-byte extra
    private const COMMAND_SIZE = 8;        // "\xff\xff\xff\xff" + 4 chars
    private const PAYLOAD_OFFSET = 14;     // FRAMING_SIZE + COMMAND_SIZE

    public const GETLIST = "\xff\xff\xff\xffreq2";
    public const GETINFO = "\xff\xff\xff\xffgie3";
    public const LIST = 'lis2';
    public const INFO = 'inf3';
    public const INFO_EXTENDED = 'iext';
    public const INFO_EXTENDED_MORE = 'iex+';

    /**
     * GETLIST request. $token is 2 caller-supplied random bytes that the master echoes back.
     */
    public static function getList(string $token): string
    {
        return self::frame($token, self::GETLIST);
    }

    /**
     * GETINFO request. $infoToken is the 1 byte appended after gie3; the server folds it into the
     * echoed token field of its inf3/iext reply, which we drop (replies are matched by source address).
     */
    public static function getInfo(string $token, string $infoToken): string
    {
        return self::frame($token, self::GETINFO . $infoToken);
    }

    private static function frame(string $token, string $payload): string
    {
        // 4-byte extra = 2 token bytes + "\0\0", matching the legacy scraper and CNetBase extra
        return self::HEADER_EXTENDED . str_pad(substr($token, 0, 2), self::EXTRA_SIZE, "\x00") . $payload;
    }

    private const COMMAND_MARKER = "\xff\xff\xff\xff"; // SERVERBROWSE_* prefix, present in every browse packet

    /**
     * @return array{command: string, payload: string}|null the 4-char command and the bytes
     *         after the 14-byte framing+command prefix, or null if this is not a 0.6 browse packet
     */
    public static function parse(string $datagram): ?array
    {
        if (strlen($datagram) < self::PAYLOAD_OFFSET) {
            return null;
        }

        // both connless headers — plain (6x 0xFF) and extended ("xe" + 4-byte token) — are 6 bytes,
        // then the 8-byte command (\xff\xff\xff\xff + 4 chars) follows. The live masters answer our
        // extended request with the PLAIN header, so match on the command marker, not the "xe" prefix.
        if (substr($datagram, self::FRAMING_SIZE, 4) !== self::COMMAND_MARKER) {
            return null;
        }

        return [
            'command' => substr($datagram, self::FRAMING_SIZE + 4, 4),
            'payload' => substr($datagram, self::PAYLOAD_OFFSET),
        ];
    }
}
