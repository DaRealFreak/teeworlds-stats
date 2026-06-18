<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Computes the on-disk cache path for a map identity, shared by every writer/reader so a file cached
 * via one acquisition path is found by the others. The key is sha256 if available, else crc; the name
 * is a best-effort, filesystem-safe prefix (lookups never parse it back). The hex-only key is the
 * path-traversal guard.
 */
final class MapCachePath
{
    public static function forIdentity(string $name, ?string $sha256, ?string $crc): ?string
    {
        $key = $sha256 ?? $crc;
        if ($key === null || !ctype_xdigit($key)) {
            return null;
        }

        $slug = self::nameSlug($name);
        $prefix = $slug === '' ? '' : $slug . '_';

        return 'maps/' . $prefix . $key . '.map';
    }

    /**
     * Filesystem-safe, human-readable slug for a (server-controlled) map name. The whitelist keeps out
     * '/', '.' and whitespace, so the slug can never contain a path separator or '..'; the dash sits
     * last in the class so 0-9-_ is not read as a range. Capped so name + hash stay under the 255-byte
     * filename limit.
     */
    private static function nameSlug(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);

        return rtrim(substr(trim($slug, '_'), 0, 64), '_');
    }
}
