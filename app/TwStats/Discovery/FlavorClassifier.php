<?php

namespace App\TwStats\Discovery;

/**
 * Derives the server-type label from the reported version string. DDNet servers append
 * their build to the engine version (e.g. "0.6.4, 19.1"); vanilla servers report only the
 * engine version ("0.6.4" / "0.7.5"). Heuristic — revisit if DDNet changes version reporting.
 */
final class FlavorClassifier
{
    public const FLAVOR_DDNET = 'ddnet';
    public const FLAVOR_VANILLA_06 = 'vanilla_06';
    public const FLAVOR_VANILLA_07 = 'vanilla_07';

    public static function classify(string $version): string
    {
        if (str_contains($version, ',') || stripos($version, 'ddnet') !== false) {
            return self::FLAVOR_DDNET;
        }

        if (str_starts_with($version, '0.7')) {
            return self::FLAVOR_VANILLA_07;
        }

        return self::FLAVOR_VANILLA_06;
    }
}
