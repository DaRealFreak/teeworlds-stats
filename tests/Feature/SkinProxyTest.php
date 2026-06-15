<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The same-origin 0.6 skin proxy. It exists so the tee renderer never makes a cross-origin request
 * to skins.ddnet.org: a miss there 404s without CORS headers, which spams the browser console even
 * though the renderer falls back to the default tee. The proxy always answers 200 with PNG bytes —
 * the real skin (local, cached, or freshly fetched from the DDNet DB) or the default on any miss.
 */
class SkinProxyTest extends TestCase
{
    use RefreshDatabase;

    private string $defaultPng;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local'); // isolate the proxy's on-disk fetch cache
        $this->defaultPng = (string) file_get_contents(public_path('skins/06/default.png'));
    }

    public function test_serves_a_shipped_local_skin_without_calling_ddnet(): void
    {
        Http::fake(); // any outbound call would trip assertNothingSent below

        $this->get('/skin/06/default')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');

        Http::assertNothingSent();
    }

    public function test_fetches_and_caches_an_unimported_skin_from_the_ddnet_db(): void
    {
        $png = $this->defaultPng; // any valid PNG bytes stand in for the real skin
        Http::fake(['skins.ddnet.org/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);

        $first = $this->get('/skin/06/santa');
        $first->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($png, $first->getContent());

        // second request is served from the cache — DDNet is hit exactly once
        $this->get('/skin/06/santa')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_unknown_skin_falls_back_to_default_with_200_not_404(): void
    {
        // The whole point of the proxy: a miss must NOT 404. A cross-origin 404 is exactly the
        // console noise we are removing.
        Http::fake(['skins.ddnet.org/*' => Http::response('', 404)]);

        $res = $this->get('/skin/06/Grizli23');
        $res->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->defaultPng, $res->getContent());
    }

    public function test_rejects_a_non_png_ddnet_response_and_serves_default(): void
    {
        // A swapped-in error page (200 text/html) must not be cached or served as a skin.
        Http::fake(['skins.ddnet.org/*' => Http::response('<html>nope</html>', 200, ['Content-Type' => 'text/html'])]);

        $res = $this->get('/skin/06/whatever');
        $res->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->defaultPng, $res->getContent());
    }

    public function test_serves_default_for_a_traversal_name_without_fetching(): void
    {
        Http::fake();

        $res = $this->get('/skin/06/' . rawurlencode('..\\..\\secret'));
        $res->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($this->defaultPng, $res->getContent());

        Http::assertNothingSent();
    }
}
