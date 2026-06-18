<?php

declare(strict_types=1);

namespace Tests\Unit\Service;

use App\Service\MapCachePath;
use PHPUnit\Framework\TestCase;

class MapCachePathTest extends TestCase
{
    public function test_prefers_sha256_and_slugs_the_name(): void
    {
        self::assertSame(
            'maps/My_Map_' . str_repeat('a', 64) . '.map',
            MapCachePath::forIdentity('My Map', str_repeat('a', 64), 'deadbeef'),
        );
    }

    public function test_falls_back_to_crc_when_no_sha(): void
    {
        self::assertSame('maps/dm1_deadbeef.map', MapCachePath::forIdentity('dm1', null, 'deadbeef'));
    }

    public function test_null_when_no_key(): void
    {
        self::assertNull(MapCachePath::forIdentity('dm1', null, null));
    }

    public function test_null_when_key_is_not_hex(): void
    {
        self::assertNull(MapCachePath::forIdentity('dm1', '../etc/passwd', null));
    }

    public function test_empty_slug_yields_no_prefix(): void
    {
        self::assertSame('maps/deadbeef.map', MapCachePath::forIdentity('***', null, 'deadbeef'));
    }
}
