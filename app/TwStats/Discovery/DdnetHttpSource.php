<?php

namespace App\TwStats\Discovery;

use App\TwStats\Model\DiscoveredServer;
use Illuminate\Support\Facades\Http;

/**
 * Discovers servers from the DDNet master `servers.json` over HTTP. This is the only source
 * that carries real (un-capped) limits, full player lists, and skins, and it groups a logical
 * server's addresses for us. Mirrors are tried in turn; if all fail the scrape cycle proceeds
 * with the other (UDP) sources, so a total failure returns [] rather than throwing.
 */
final class DdnetHttpSource
{
    public const MIRRORS = [
        'https://master1.ddnet.org/ddnet/15/servers.json',
        'https://master2.ddnet.org/ddnet/15/servers.json',
        'https://master3.ddnet.org/ddnet/15/servers.json',
        'https://master4.ddnet.org/ddnet/15/servers.json',
    ];

    /**
     * @param string[] $mirrors
     */
    public function __construct(
        private readonly DdnetServerListParser $parser = new DdnetServerListParser(),
        private readonly array $mirrors = self::MIRRORS,
    ) {
    }

    /**
     * @return DiscoveredServer[]
     */
    public function fetch(): array
    {
        foreach ($this->mirrors as $url) {
            try {
                $response = Http::timeout(10)->get($url);
                if ($response->successful()) {
                    return $this->parser->parse($response->body());
                }
            } catch (\Throwable) {
                // mirror unreachable — fall through to the next one
            }
        }

        return [];
    }
}
