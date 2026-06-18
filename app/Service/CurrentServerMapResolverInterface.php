<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Server;

/**
 * Resolves a server's CURRENT master-advertised map name via the channel that originally produced it
 * (DDNet HTTP master for ddnet, connless info for vanilla_06), or null for all other flavors and
 * whenever it cannot be determined. Used to confirm a "phantom" version before throttling it.
 */
interface CurrentServerMapResolverInterface
{
    public function currentMapName(Server $server): ?string;
}
