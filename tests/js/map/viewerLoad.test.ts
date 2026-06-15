import { describe, it, expect, afterEach } from 'vitest';
import { readFileSync } from 'node:fs';
import { loadMapScene, MapUnavailableError } from '../../../resources/assets/js/map/viewer';

const realFetch = globalThis.fetch;
afterEach(() => {
    globalThis.fetch = realFetch;
});

describe('loadMapScene', () => {
    it('throws MapUnavailableError (not "not a Teeworlds datafile") when the file route is not ok', async () => {
        // Regression: maps with no DDNet mirror and no reachable live host 404 from /map/{name}/file.
        // The viewer used to feed that HTML error page straight into parseMap, surfacing a confusing
        // "not a Teeworlds datafile" unhandled rejection. A non-ok response must fail cleanly instead.
        globalThis.fetch = (async () =>
            new Response('<!doctype html><title>404</title>', {
                status: 404,
                statusText: 'Not Found',
            })) as typeof fetch;

        await expect(loadMapScene('/map/FlipLipp30/file')).rejects.toBeInstanceOf(MapUnavailableError);
    });

    it('parses the bytes of an ok response into a scene', async () => {
        const bytes = readFileSync(new URL('../fixtures/gravity.map', import.meta.url));
        globalThis.fetch = (async () => new Response(bytes, { status: 200 })) as typeof fetch;

        const scene = await loadMapScene('/map/gravity/file');
        expect(scene.groups.length).toBeGreaterThan(0);
    });
});
