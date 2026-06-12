<?php

namespace Tests\Unit\Utility;

use App\Utility\TeeSkin;
use Tests\TestCase;

class TeeSkinTest extends TestCase
{
    public function test_returns_null_when_there_is_nothing_to_render(): void
    {
        $this->assertNull(TeeSkin::describe(null, null, null, null));
        $this->assertNull(TeeSkin::describe('', null, null, null));
    }

    public function test_describes_a_known_06_skin(): void
    {
        $tee = TeeSkin::describe('default', null, null, null);

        $this->assertSame('06', $tee['mode']);
        $this->assertSame('default', $tee['name']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
        $this->assertFalse($tee['fallback']);
        $this->assertNull($tee['colorBody']);
        $this->assertNull($tee['colorFeet']);
    }

    public function test_falls_back_to_the_default_skin_for_an_unknown_name(): void
    {
        $tee = TeeSkin::describe('SomeCustomCommunitySkin', 100, 200, null);

        $this->assertSame('06', $tee['mode']);
        $this->assertSame('SomeCustomCommunitySkin', $tee['name']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
        $this->assertTrue($tee['fallback']);
        // colors are preserved even on the fallback skin
        $this->assertSame(100, $tee['colorBody']);
        $this->assertSame(200, $tee['colorFeet']);
    }

    public function test_a_malicious_skin_name_cannot_escape_the_skin_directory(): void
    {
        $tee = TeeSkin::describe('../../../../etc/passwd', null, null, null);

        // whitelist resolution → the traversal name is simply unknown → default fallback
        $this->assertTrue($tee['fallback']);
        $this->assertStringContainsString('skins/06/default.png', $tee['url']);
        $this->assertStringNotContainsString('..', $tee['url']);
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
