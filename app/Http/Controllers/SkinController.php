<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Same-origin proxy for 0.6 tee skins. The tee renderer points every non-local skin here instead of
 * at https://skins.ddnet.org directly: a miss there 404s without CORS headers, which the browser
 * logs as a "Cross-Origin Request Blocked" even though the renderer falls back to the default tee.
 * This route always answers 200 with PNG bytes — a local copy, an on-disk cache, a fresh DDNet-DB
 * fetch, or the bundled default on any miss — so the front-end never makes a doomed cross-origin
 * request and getImageData (the colored-skin path) works without a CORS-clean image.
 */
class SkinController extends Controller
{
    /** the DDNet skin database the game client downloads from (cl_skin_download_url) */
    private const DDNET_SKIN_DB = 'https://skins.ddnet.org/skin/';

    private const DIR = 'skins/06';
    private const CACHE_MAX_AGE = 86400; // a day; skins rarely change and a stale tee is harmless

    public function six(string $name): Response
    {
        return response($this->resolve($name), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=' . self::CACHE_MAX_AGE,
        ]);
    }

    /**
     * The PNG bytes to serve for a skin name, in the client's local > cache/fetch > default order.
     */
    private function resolve(string $name): string
    {
        // A name that can't be a safe single path segment can never resolve to a real skin; serve the
        // default without touching the filesystem path or the network (path-traversal guard).
        if (!$this->isSafeName($name)) {
            return $this->default();
        }

        // local: a skin we ship or have imported (skins:import) — served straight from disk
        $local = public_path(self::DIR . '/' . $name . '.png');
        if (is_file($local)) {
            return (string) file_get_contents($local);
        }

        // cache: a skin we already fetched from the DDNet DB
        $cache = Storage::disk('local');
        $cachePath = self::DIR . '/' . $this->cacheFile($name);
        if ($cache->exists($cachePath)) {
            return (string) $cache->get($cachePath);
        }

        // fetch: ask the DDNet skin DB once, then cache for next time
        $fetched = $this->fetch($name);
        if ($fetched !== null) {
            $cache->put($cachePath, $fetched);

            return $fetched;
        }

        return $this->default();
    }

    /**
     * Fetch a skin from the DDNet DB, or null on any failure. The body must really be a PNG so a CDN
     * error page answered with a 200 is never cached or rendered as a tee.
     */
    private function fetch(string $name): ?string
    {
        try {
            $response = Http::timeout(8)->get(self::DDNET_SKIN_DB . rawurlencode($name) . '.png');
        } catch (\Throwable) {
            return null;
        }

        if (!$response->successful()) {
            return null;
        }

        $body = $response->body();

        return $this->looksLikePng($body) ? $body : null;
    }

    private function default(): string
    {
        return (string) file_get_contents(public_path(self::DIR . '/default.png'));
    }

    /** The 8-byte PNG signature. */
    private function looksLikePng(string $bytes): bool
    {
        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n");
    }

    /**
     * Whether the (player-controlled) skin name can form a safe single path/URL segment: no path
     * separators, no traversal, no null byte, and a bounded length.
     */
    private function isSafeName(string $name): bool
    {
        return $name !== ''
            && mb_strlen($name) <= 128
            && !str_contains($name, '..')
            && !preg_match('#[/\\\\\x00]#', $name);
    }

    /**
     * Cache filename for a fetched skin: a filesystem-safe slug plus a hash of the exact name, so two
     * names that slug identically (e.g. a space vs an underscore) never collide on one cache entry.
     */
    private function cacheFile(string $name): string
    {
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
        $slug = rtrim(substr(trim((string) $slug, '_'), 0, 64), '_');

        return ($slug === '' ? 'skin' : $slug) . '_' . substr(hash('sha256', $name), 0, 16) . '.png';
    }
}
