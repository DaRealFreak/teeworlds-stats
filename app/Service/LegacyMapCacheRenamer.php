<?php

namespace App\Service;

use App\Models\Map;
use Illuminate\Support\Facades\Storage;

/**
 * One-off transitional helper: renames cache files written under the old hash-only scheme
 * (maps/<sha256|crc>.map) to the current human-readable scheme (maps/<name>_<hash>.map). Driven by a
 * migration so each environment converts its own cached files exactly once. Idempotent; files with
 * no matching Map row (orphans) are left untouched.
 */
final class LegacyMapCacheRenamer
{
    public function __construct(
        private readonly MapFileStore $store = new MapFileStore(),
        private readonly string $disk = 'local',
    ) {
    }

    /**
     * @return int number of files renamed
     */
    public function run(): int
    {
        $storage = Storage::disk($this->disk);
        $renamed = 0;

        foreach ($storage->files('maps') as $path) {
            // legacy files are exactly maps/<hex>.map — the new scheme always has a name_ prefix
            // (which contains '_'), so it never matches this pattern.
            if (!preg_match('#^maps/([0-9a-fA-F]+)\.map$#', $path, $m)) {
                continue;
            }
            $key = $m[1];

            /** @var Map|null $map */
            $map = Map::where(fn ($q) => $q->where('sha256', $key)->orWhere('crc', $key))->first();
            if ($map === null) {
                continue; // orphan: no record to derive a name from
            }

            $target = $this->store->relativePathFor($map);
            if ($target === null || $target === $path || $storage->exists($target)) {
                continue;
            }

            $storage->move($path, $target);
            $renamed++;
        }

        return $renamed;
    }
}
