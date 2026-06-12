<?php

namespace App\Utility;

/**
 * Resolves a player's stored skin into a render descriptor the front-end tee renderer consumes.
 * Two skin systems exist: the 0.6 single-sheet skin (a name + optional body/feet colors) and the
 * 0.7 six-part skin (per-part name + color). 0.7 wins when present (richer). Skin names are matched
 * against the shipped asset whitelist — an unknown 0.6 name falls back to "default", and unknown
 * 0.7 parts are dropped — which also makes name resolution immune to path traversal.
 */
final class TeeSkin
{
    private const SIX_DIR = 'skins/06';
    private const SEVEN_DIR = 'skins/07';

    /** part order is not significant here; the renderer composites in its own order */
    public const SEVEN_PARTS = ['body', 'marking', 'decoration', 'hands', 'feet', 'eyes'];

    /**
     * @param array<string, array{name?: string, color?: int}>|null $skinParts
     * @return array<string, mixed>|null null when the player has no renderable skin
     */
    public static function describe(?string $skin, ?int $colorBody, ?int $colorFeet, ?array $skinParts): ?array
    {
        if (!empty($skinParts)) {
            $seven = self::describeSeven($skinParts);
            if ($seven !== null) {
                return $seven;
            }
        }

        if ($skin !== null && $skin !== '') {
            return self::describeSix($skin, $colorBody, $colorFeet);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function describeSix(string $skin, ?int $colorBody, ?int $colorFeet): array
    {
        $known = in_array($skin, self::shippedNames(self::SIX_DIR), true);
        $file = $known ? $skin : 'default';

        return [
            'mode' => '06',
            'name' => $skin,
            'url' => asset(self::SIX_DIR . '/' . rawurlencode($file) . '.png'),
            'fallback' => !$known,
            'colorBody' => $colorBody,
            'colorFeet' => $colorFeet,
        ];
    }

    /**
     * @param array<string, array{name?: string, color?: int}> $skinParts
     * @return array<string, mixed>|null null when no body part resolves (cannot form a 0.7 tee)
     */
    private static function describeSeven(array $skinParts): ?array
    {
        $parts = [];

        foreach (self::SEVEN_PARTS as $part) {
            $name = $skinParts[$part]['name'] ?? '';
            if ($name === '' || !in_array($name, self::shippedNames(self::SEVEN_DIR . '/' . $part), true)) {
                continue;
            }

            $parts[$part] = [
                'url' => asset(self::SEVEN_DIR . '/' . $part . '/' . rawurlencode($name) . '.png'),
                'color' => $skinParts[$part]['color'] ?? null,
            ];
        }

        // the body is the one indispensable part; without it there is nothing to render
        if (!isset($parts['body'])) {
            return null;
        }

        return [
            'mode' => '07',
            'name' => $skinParts['body']['name'] ?? '0.7 skin',
            'parts' => $parts,
        ];
    }

    /**
     * The base names (without .png) of the skins shipped under public/<dir>, cached per directory.
     *
     * @return string[]
     */
    private static function shippedNames(string $dir): array
    {
        static $cache = [];

        if (!isset($cache[$dir])) {
            $cache[$dir] = array_map(
                static fn (string $path): string => basename($path, '.png'),
                glob(public_path($dir . '/*.png')) ?: [],
            );
        }

        return $cache[$dir];
    }
}
