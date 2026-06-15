<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\DdnetUuid;
use PHPUnit\Framework\TestCase;

class DdnetUuidTest extends TestCase
{
    public function test_computes_the_known_ddnet_extended_message_uuids(): void
    {
        // The version-3 (MD5) UUIDs DDNet derives for these message names (uuid_manager.cpp). If these
        // bytes are wrong the server rejects our CLIENTVER and never recognises MAP_DETAILS.
        self::assertSame('8c00130484613e478787f672b3835bd4', bin2hex(DdnetUuid::compute('clientver@ddnet.tw')));
        self::assertSame('f9117b3c803934169fc0aef2bcb75c03', bin2hex(DdnetUuid::compute('map-details@ddnet.tw')));
    }

    public function test_sets_the_uuid_version_and_variant_bits(): void
    {
        $u = DdnetUuid::compute('anything@example.com');

        self::assertSame(16, strlen($u));
        self::assertSame(0x30, ord($u[6]) & 0xf0, 'version nibble must be 3');
        self::assertSame(0x80, ord($u[8]) & 0xc0, 'variant bits must be RFC 4122');
    }
}
