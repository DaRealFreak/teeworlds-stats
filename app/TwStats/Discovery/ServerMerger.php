<?php

namespace App\TwStats\Discovery;

use App\TwStats\Model\DiscoveredAddress;
use App\TwStats\Model\DiscoveredClient;
use App\TwStats\Model\DiscoveredServer;

/**
 * Combines discovered servers from one or more sources into logical servers: any servers that
 * share an ip:port are the same logical server (mirroring how the DDNet master groups a server's
 * addresses). Earlier servers in the input win on metadata and on client identity, so callers
 * pass higher-priority sources first. Clients are deduped by (name, clan) — a sixup server
 * reports the same humans on its 0.6 and 0.7 lists, and they must not be double-counted.
 *
 * Assumes a server's address set is stable across sources (no entry bridges two pre-existing
 * groups); that holds for the real feeds, where a logical server's endpoints are consistent.
 */
final class ServerMerger
{
    /**
     * @param DiscoveredServer[] $servers
     * @return DiscoveredServer[]
     */
    public function merge(array $servers): array
    {
        /** @var DiscoveredServer[] $groups */
        $groups = [];
        $addressToGroup = []; // address key => group id

        foreach ($servers as $server) {
            $groupId = null;
            foreach ($server->addresses as $address) {
                $key = $this->groupKey($address);
                if (isset($addressToGroup[$key])) {
                    $groupId = $addressToGroup[$key];
                    break;
                }
            }

            if ($groupId === null) {
                $groupId = count($groups);
                $groups[$groupId] = $server;
            } else {
                $groups[$groupId] = $this->combine($groups[$groupId], $server);
            }

            foreach ($groups[$groupId]->addresses as $address) {
                $addressToGroup[$this->groupKey($address)] = $groupId;
            }
        }

        return array_values($groups);
    }

    private function combine(DiscoveredServer $primary, DiscoveredServer $secondary): DiscoveredServer
    {
        $addresses = $primary->addresses;
        $seenAddresses = array_map([$this, 'addressKey'], $addresses);
        foreach ($secondary->addresses as $address) {
            $key = $this->addressKey($address);
            if (!in_array($key, $seenAddresses, true)) {
                $addresses[] = $address;
                $seenAddresses[] = $key;
            }
        }

        $clients = $primary->clients;
        $seenClients = array_map([$this, 'clientKey'], $clients);
        foreach ($secondary->clients as $client) {
            $key = $this->clientKey($client);
            if (!in_array($key, $seenClients, true)) {
                $clients[] = $client;
                $seenClients[] = $key;
            }
        }

        // primary (higher-priority / earlier) source wins on metadata
        return new DiscoveredServer(
            addresses: $addresses,
            name: $primary->name,
            map: $primary->map,
            gametype: $primary->gametype,
            version: $primary->version,
            maxClients: $primary->maxClients,
            maxPlayers: $primary->maxPlayers,
            clients: $clients,
            location: $primary->location,
            flavor: $primary->flavor,
        );
    }

    private function groupKey(DiscoveredAddress $address): string
    {
        // a physical server is one ip:port regardless of protocol (UDP binding is exclusive), so the
        // 0.6 and 0.7 endpoints of a sixup server — which different sources surface separately — are
        // the same logical server. The address union still keeps both protocols (see addressKey).
        return $address->ip . ':' . $address->port;
    }

    private function addressKey(DiscoveredAddress $address): string
    {
        return $address->ip . ':' . $address->port . ':' . $address->protocol;
    }

    private function clientKey(DiscoveredClient $client): string
    {
        return $client->name . "\x00" . $client->clan;
    }
}
