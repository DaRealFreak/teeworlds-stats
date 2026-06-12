<?php

namespace App\TwStats\Protocol\Seven;

use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredClient;
use App\TwStats\Discovery\DiscoveredServer;
use App\TwStats\Discovery\FlavorClassifier;

/**
 * Parses a Teeworlds 0.7 `inf3` info payload (everything after the SERVERBROWSE_INFO token)
 * into a DiscoveredServer for the given address. The 0.7 layout differs from 0.6/DDNet: it
 * inserts `hostname` and `skill_level`, and the per-client flag is 0=player / 1=spectator /
 * 2=bot. 0.7 carries no skin/afk, so those cosmetic fields are left null/unknown.
 */
final class SevenInfoCodec
{
    public function parse(string $payload, DiscoveredAddress $address): ?DiscoveredServer
    {
        $unpacker = new Unpacker($payload);

        $unpacker->getInt();             // browse-token echo (matched by the caller at the socket layer)
        $version = $unpacker->getString();
        $name = $unpacker->getString();
        $unpacker->getString();          // hostname (0.7 only; not tracked)
        $map = $unpacker->getString();
        $gametype = $unpacker->getString();
        $unpacker->getInt();             // flags
        $unpacker->getInt();             // skill level (0.7 only)
        $numPlayers = $unpacker->getInt();
        $maxPlayers = $unpacker->getInt();
        $numClients = $unpacker->getInt();
        $maxClients = $unpacker->getInt();

        if ($unpacker->error()) {
            return null;
        }

        $clients = [];
        for ($i = 0; $i < $numClients; $i++) {
            $clientName = $unpacker->getString();
            $clan = $unpacker->getString();
            $country = $unpacker->getInt();
            $score = $unpacker->getInt();
            $playerFlag = $unpacker->getInt();

            if ($unpacker->error()) {
                return null;
            }

            $clients[] = new DiscoveredClient(
                name: $clientName,
                clan: $clan,
                country: $country,
                score: $score,
                isPlayer: $playerFlag === 0,
                afk: null,       // 0.7 reports no afk
                skin: null,
                colorBody: null,
                colorFeet: null,
                skinParts: null,
            );
        }

        return new DiscoveredServer(
            addresses: [$address],
            name: $name,
            map: $map,
            gametype: $gametype,
            version: $version,
            maxClients: $maxClients,
            maxPlayers: $maxPlayers,
            clients: $clients,
            location: null,
            flavor: FlavorClassifier::classify($version),
        );
    }
}
