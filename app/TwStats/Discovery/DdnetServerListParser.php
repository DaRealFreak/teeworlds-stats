<?php

namespace App\TwStats\Discovery;

/**
 * Parses a DDNet master `servers.json` body into normalized servers. The feed is an external
 * contract, so every entry/field is validated and anything malformed is skipped rather than
 * aborting the whole list.
 */
final class DdnetServerListParser
{
    /**
     * @return DiscoveredServer[]
     */
    public function parse(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['servers']) || !is_array($decoded['servers'])) {
            return [];
        }

        $servers = [];
        foreach ($decoded['servers'] as $entry) {
            $server = $this->parseServer($entry);
            if ($server !== null) {
                $servers[] = $server;
            }
        }

        return $servers;
    }

    private function parseServer(mixed $entry): ?DiscoveredServer
    {
        if (!is_array($entry) || !isset($entry['addresses'], $entry['info'])
            || !is_array($entry['addresses']) || !is_array($entry['info'])) {
            return null;
        }

        $addresses = [];
        foreach ($entry['addresses'] as $url) {
            if (is_string($url) && ($address = DiscoveredAddress::fromUrl($url)) !== null) {
                $addresses[] = $address;
            }
        }
        if ($addresses === []) {
            return null;
        }

        $info = $entry['info'];
        foreach (['name', 'map', 'game_type', 'version', 'max_clients', 'max_players', 'clients'] as $key) {
            if (!array_key_exists($key, $info)) {
                return null;
            }
        }
        if (!is_array($info['map']) || !isset($info['map']['name']) || !is_array($info['clients'])) {
            return null;
        }

        // the feed is an external contract — a wrong-typed scalar field (e.g. an array where a
        // string is expected) is malformed data to skip, not to coerce into "Array"/junk.
        if (!is_string($info['name']) || !is_string($info['game_type']) || !is_string($info['version'])
            || !is_string($info['map']['name']) || !is_int($info['max_clients']) || !is_int($info['max_players'])) {
            return null;
        }

        $clients = [];
        foreach ($info['clients'] as $client) {
            if (is_array($client) && ($parsed = $this->parseClient($client)) !== null) {
                $clients[] = $parsed;
            }
        }

        return new DiscoveredServer(
            addresses: $addresses,
            name: (string) $info['name'],
            map: (string) $info['map']['name'],
            gametype: (string) $info['game_type'],
            version: (string) $info['version'],
            maxClients: (int) $info['max_clients'],
            maxPlayers: (int) $info['max_players'],
            clients: $clients,
            location: isset($entry['location']) && is_string($entry['location']) ? $entry['location'] : null,
            flavor: FlavorClassifier::classify((string) $info['version']),
        );
    }

    private function parseClient(array $client): ?DiscoveredClient
    {
        foreach (['name', 'clan', 'country', 'score', 'is_player'] as $key) {
            if (!array_key_exists($key, $client)) {
                return null;
            }
        }

        if (!is_string($client['name']) || !is_string($client['clan'])
            || !is_int($client['country']) || !is_int($client['score']) || !is_bool($client['is_player'])) {
            return null;
        }

        [$skin, $colorBody, $colorFeet, $skinParts] = $this->parseSkin($client['skin'] ?? null);

        return new DiscoveredClient(
            name: (string) $client['name'],
            clan: (string) $client['clan'],
            country: (int) $client['country'],
            score: (int) $client['score'],
            isPlayer: (bool) $client['is_player'],
            afk: isset($client['afk']) && is_bool($client['afk']) ? $client['afk'] : false,
            skin: $skin,
            colorBody: $colorBody,
            colorFeet: $colorFeet,
            skinParts: $skinParts,
        );
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: ?int, 3: ?array}
     */
    private function parseSkin(mixed $skin): array
    {
        if (!is_array($skin)) {
            return [null, null, null, null];
        }

        // 0.6 skin: a single skin name plus optional custom tee colors
        if (isset($skin['name']) && is_string($skin['name'])) {
            $colorBody = isset($skin['color_body']) && is_int($skin['color_body']) ? $skin['color_body'] : null;
            $colorFeet = isset($skin['color_feet']) && is_int($skin['color_feet']) ? $skin['color_feet'] : null;

            return [$skin['name'], $colorBody, $colorFeet, null];
        }

        // 0.7 skin: six parts {body:{name,color}, ...}
        if (isset($skin['body']) && is_array($skin['body'])) {
            return [null, null, null, $skin];
        }

        return [null, null, null, null];
    }
}
