<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\MapDownloadResult;
use PHPUnit\Framework\TestCase;

class MapDownloadResultTest extends TestCase
{
    public function testExposesBytesAndObservedIdentity(): void
    {
        $result = new MapDownloadResult(
            bytes: 'DATA....',
            name: 'ctf5',
            crc: -825302392,
            size: 8,
            sha256: str_repeat('a', 64),
        );

        self::assertSame('DATA....', $result->bytes);
        self::assertSame('ctf5', $result->name);
        self::assertSame(-825302392, $result->crc);
        self::assertSame(8, $result->size);
        self::assertSame(str_repeat('a', 64), $result->sha256);
    }

    public function testSha256IsNullableForVanilla06(): void
    {
        $result = new MapDownloadResult('DATA....', 'dm1', 1, 8, null);

        self::assertNull($result->sha256);
    }
}
