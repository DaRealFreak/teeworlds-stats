<?php

namespace App\TwStats\Discovery;

/**
 * One client (player or spectator) on a discovered server. Skin/colors/afk come only from
 * the DDNet HTTP feed; for UDP-only sources they are null/false.
 */
final class DiscoveredClient
{
    public function __construct(
        public readonly string $name,
        public readonly string $clan,
        public readonly int $country,
        public readonly int $score,
        public readonly bool $isPlayer,
        public readonly bool $afk,
        public readonly ?string $skin,      // 0.6 skin name
        public readonly ?int $colorBody,    // 0.6 custom tee colors (null = default)
        public readonly ?int $colorFeet,
        public readonly ?array $skinParts,  // 0.7 six-part skin {body:{name,color}, ...}
    ) {
    }
}
