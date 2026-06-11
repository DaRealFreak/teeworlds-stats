<?php

namespace App\TwStats\Persistence;

use App\Models\Server;
use App\Models\ServerAddress;
use App\TwStats\Discovery\DiscoveredAddress;
use App\TwStats\Discovery\DiscoveredServer;
use Carbon\Carbon;

/**
 * Writes one merged discovery result to the DB. The address set is the server's identity, so the
 * logical Server is found by any of its addresses (matching how a sixup server is reachable under
 * several protocols); otherwise it is created. ip/port stay on servers as the denormalised
 * canonical pointer for existing consumers; the full protocol-tagged set lives in server_addresses.
 */
class ServerPersister
{
    public function persist(DiscoveredServer $server): Server
    {
        $canonical = $server->addresses[0];

        $existingAddress = ServerAddress::query()
            ->where(function ($query) use ($server) {
                foreach ($server->addresses as $address) {
                    $query->orWhere(function ($match) use ($address) {
                        $match->where('ip', $address->ip)
                            ->where('port', $address->port)
                            ->where('protocol', $address->protocol);
                    });
                }
            })
            ->first();

        $model = $existingAddress?->server ?? new Server();

        $model->setAttribute('name', $server->name);
        $model->setAttribute('version', $server->version);
        $model->setAttribute('flavor', $server->flavor);
        $model->setAttribute('ip', $canonical->ip);
        $model->setAttribute('port', $canonical->port);
        $model->setAttribute('last_seen', Carbon::now());
        $model->save();

        $this->syncAddresses($model, $server->addresses);

        return $model;
    }

    /**
     * @param DiscoveredAddress[] $addresses
     */
    private function syncAddresses(Server $model, array $addresses): void
    {
        // exactly one address is canonical (the first); clear any stale flag before re-marking
        ServerAddress::where('server_id', $model->getAttribute('id'))->update(['is_canonical' => false]);

        foreach ($addresses as $index => $address) {
            ServerAddress::updateOrCreate(
                [
                    'ip' => $address->ip,
                    'port' => $address->port,
                    'protocol' => $address->protocol,
                ],
                [
                    'server_id' => $model->getAttribute('id'),
                    'is_canonical' => $index === 0,
                ],
            );
        }
    }
}
