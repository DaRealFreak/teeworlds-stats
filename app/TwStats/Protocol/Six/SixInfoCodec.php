<?php

namespace App\TwStats\Protocol\Six;

use App\TwStats\Model\DiscoveredClient;

/**
 * Parses a Teeworlds 0.6 info payload (the NUL-delimited bytes after the 14-byte framing+command)
 * into a SixInfoPacket. 0.6 packs every field — numbers included — as a NUL-terminated ASCII
 * string (ddnet server.cpp ADD_INT = str_format("%d") + AddString). The leading field is the
 * echoed info token and is dropped. Three layouts:
 *   inf3  vanilla:   version, name, map, gametype, flags, nums..., then (name, clan, country, score, isPlayer)*
 *   iext  extended:  inserts mapCrc/mapSize after map and a trailing reserved field per client
 *   iex+  continuation: chunkNumber, reserved, then the same per-client tuples (no server header)
 * 0.6 UDP carries no skin/afk, so those cosmetic fields are left null.
 */
final class SixInfoCodec
{
    public function parse(string $payload, string $command): ?SixInfoPacket
    {
        $fields = explode("\x00", $payload);
        // every field is NUL-terminated, so a well-formed payload ends with an empty trailing element
        if ($fields !== [] && end($fields) === '') {
            array_pop($fields);
        }

        array_shift($fields); // drop the echoed token

        return match ($command) {
            SixConnless::INFO => $this->parseHeaderPacket($fields, extended: false),
            SixConnless::INFO_EXTENDED => $this->parseHeaderPacket($fields, extended: true),
            SixConnless::INFO_EXTENDED_MORE => $this->parseContinuation($fields),
            default => null,
        };
    }

    /**
     * @param string[] $fields
     */
    private function parseHeaderPacket(array $fields, bool $extended): ?SixInfoPacket
    {
        // version, name, map, [mapCrc, mapSize], gametype, flags, numPlayers, maxPlayers, numClients, maxClients
        $headerCount = $extended ? 11 : 9;
        if (count($fields) < $headerCount) {
            return null;
        }

        $version = array_shift($fields);
        $name = array_shift($fields);
        $map = array_shift($fields);
        if ($extended) {
            array_shift($fields); // mapCrc
            array_shift($fields); // mapSize
        }
        $gametype = array_shift($fields);
        array_shift($fields);               // flags
        array_shift($fields);               // numPlayers
        $maxPlayers = (int) array_shift($fields);
        array_shift($fields);               // numClients
        $maxClients = (int) array_shift($fields);

        if ($extended) {
            array_shift($fields); // reserved
        }

        return new SixInfoPacket(
            hasHeader: true,
            version: $version,
            name: $name,
            map: $map,
            gametype: $gametype,
            maxPlayers: $maxPlayers,
            maxClients: $maxClients,
            clients: $this->parseClients($fields, $extended),
        );
    }

    /**
     * @param string[] $fields
     */
    private function parseContinuation(array $fields): ?SixInfoPacket
    {
        if (count($fields) < 2) {
            return null;
        }

        array_shift($fields); // chunk number
        array_shift($fields); // reserved

        return new SixInfoPacket(
            hasHeader: false,
            version: null,
            name: null,
            map: null,
            gametype: null,
            maxPlayers: null,
            maxClients: null,
            clients: $this->parseClients($fields, extended: true),
        );
    }

    /**
     * @param string[] $fields
     * @return DiscoveredClient[]
     */
    private function parseClients(array $fields, bool $extended): array
    {
        $tuple = $extended ? 6 : 5; // extended carries a trailing reserved field per client
        $clients = [];

        while (count($fields) >= $tuple) {
            $name = array_shift($fields);
            $clan = array_shift($fields);
            $country = (int) array_shift($fields);
            $score = (int) array_shift($fields);
            $isPlayer = ((int) array_shift($fields)) === 1;
            if ($extended) {
                array_shift($fields); // reserved
            }

            $clients[] = new DiscoveredClient(
                name: $name,
                clan: $clan,
                country: $country,
                score: $score,
                isPlayer: $isPlayer,
                afk: null,
                skin: null,
                colorBody: null,
                colorFeet: null,
                skinParts: null,
            );
        }

        return $clients;
    }
}
