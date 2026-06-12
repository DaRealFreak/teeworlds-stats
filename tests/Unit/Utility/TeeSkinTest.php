<?php

namespace Tests\Unit\Utility;

use App\Utility\TeeSkin;
use Tests\TestCase;

class TeeSkinTest extends TestCase
{
    public function test_returns_null_only_when_no_cosmetics_were_ever_observed(): void
    {
        // null skin = UDP-only sighting, no cosmetics → nothing to render
        $this->assertNull(TeeSkin::describe(null, null, null, null));
    }

    public function test_an_empty_skin_name_renders_the_default_tee(): void
    {
        // the DDNet feed reports some players with an empty skin name; in Teeworlds that is the
        // default skin, so it renders (with the player's colors) rather than showing nothing
        $tee = TeeSkin::describe('', 5, 6, null);

        $this->assertSame('06', $tee['mode']);
        $this->assertSame('default', $tee['name']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
        $this->assertFalse($tee['external']); // it IS the local default skin, not a DDNet-DB fetch
        $this->assertSame(5, $tee['colorBody']);
        $this->assertSame(6, $tee['colorFeet']);
    }

    public function test_a_locally_shipped_skin_resolves_to_a_local_url(): void
    {
        $tee = TeeSkin::describe('default', null, null, null);

        $this->assertSame('06', $tee['mode']);
        $this->assertSame('default', $tee['name']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
        $this->assertFalse($tee['external']);
        $this->assertNull($tee['colorBody']);
        $this->assertNull($tee['colorFeet']);
    }

    public function test_an_unshipped_skin_fetches_from_the_ddnet_db_with_a_default_fallback(): void
    {
        $tee = TeeSkin::describe('SomeCustomCommunitySkin', 100, 200, null);

        $this->assertSame('06', $tee['mode']);
        $this->assertSame('SomeCustomCommunitySkin', $tee['name']);
        $this->assertTrue($tee['external']);
        $this->assertStringContainsString('skins.ddnet.org/skin/SomeCustomCommunitySkin.png', $tee['url']);
        // local default tee is the fallback when the DDNet DB doesn't have it
        $this->assertStringContainsString('skins/06/default.png', $tee['fallbackUrl']);
        // colors are preserved
        $this->assertSame(100, $tee['colorBody']);
        $this->assertSame(200, $tee['colorFeet']);
    }

    public function test_a_malicious_skin_name_cannot_escape_the_path(): void
    {
        $tee = TeeSkin::describe('../../../../etc/passwd', null, null, null);

        // rawurlencode escapes the slashes (../ -> ..%2F), so the name can only ever 404, never
        // traverse — there is no real path-separator sequence left in the URL
        $this->assertTrue($tee['external']);
        $this->assertStringNotContainsString('../', $tee['url']);
        $this->assertStringContainsString('skins.ddnet.org', $tee['url']);
        $this->assertStringContainsString('skins/06/default.png', $tee['fallbackUrl']);
    }

    public function test_describes_a_07_six_part_skin(): void
    {
        $parts = [
            'body' => ['name' => 'standard', 'color' => 5334342],
            'marking' => ['name' => 'duodonny', 'color' => -11771603],
            'decoration' => ['name' => ''],
            'hands' => ['name' => 'standard', 'color' => 750848],
            'feet' => ['name' => 'standard', 'color' => 1944919],
            'eyes' => ['name' => 'standard'],
        ];

        $tee = TeeSkin::describe(null, null, null, $parts);

        $this->assertSame('07', $tee['mode']);
        $this->assertStringContainsString('skins/07/body/standard.png', $tee['parts']['body']['url']);
        $this->assertSame(5334342, $tee['parts']['body']['color']);
        // marking present and colored
        $this->assertStringContainsString('skins/07/marking/duodonny.png', $tee['parts']['marking']['url']);
        $this->assertSame(-11771603, $tee['parts']['marking']['color']);
        // empty-name part (decoration) is dropped
        $this->assertArrayNotHasKey('decoration', $tee['parts']);
        // eyes present without a color
        $this->assertStringContainsString('skins/07/eyes/standard.png', $tee['parts']['eyes']['url']);
        $this->assertNull($tee['parts']['eyes']['color']);
    }

    public function test_07_takes_precedence_over_06_when_both_are_present(): void
    {
        $parts = ['body' => ['name' => 'standard', 'color' => 1]];

        $tee = TeeSkin::describe('default', null, null, $parts);

        $this->assertSame('07', $tee['mode']);
    }

    public function test_07_with_no_resolvable_body_falls_through_to_06(): void
    {
        // an unknown body part name can't form a 0.7 tee; fall back to the 0.6 skin
        $parts = ['body' => ['name' => 'NotAShippedBodyPart', 'color' => 1]];

        $tee = TeeSkin::describe('default', null, null, $parts);

        $this->assertSame('06', $tee['mode']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
    }

    public function test_07_with_no_resolvable_body_and_no_06_skin_is_null(): void
    {
        $parts = ['body' => ['name' => 'NotAShippedBodyPart']];

        $this->assertNull(TeeSkin::describe(null, null, null, $parts));
    }
}
