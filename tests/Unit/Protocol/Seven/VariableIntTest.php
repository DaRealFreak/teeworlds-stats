<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VariableIntTest extends TestCase
{
    public static function vectors(): array
    {
        // value => packed bytes (verified against CVariableInt::Pack)
        return [
            'zero'       => [0, "\x00"],
            'one'        => [1, "\x01"],
            'minus_one'  => [-1, "\x40"],
            'max_6bit'   => [63, "\x3f"],
            'minus_64'   => [-64, "\x7f"],
            'needs_ext'  => [64, "\x80\x01"],
            'big'        => [8191, "\xbf\x7f"],
            'int_max'    => [2147483647, "\xbf\xff\xff\xff\x0f"],
            'int_min'    => [-2147483648, "\xff\xff\xff\xff\x0f"],
        ];
    }

    #[DataProvider('vectors')]
    public function test_pack_matches_reference(int $value, string $bytes): void
    {
        $this->assertSame(bin2hex($bytes), bin2hex(VariableInt::pack($value)));
    }

    #[DataProvider('vectors')]
    public function test_unpack_round_trips(int $value, string $bytes): void
    {
        [$decoded, $offset] = VariableInt::unpack($bytes, 0);
        $this->assertSame($value, $decoded);
        $this->assertSame(strlen($bytes), $offset);
    }

    public function test_unpack_advances_offset_across_a_sequence(): void
    {
        $buffer = VariableInt::pack(64) . VariableInt::pack(-1) . VariableInt::pack(5);
        $offset = 0;
        [$a, $offset] = VariableInt::unpack($buffer, $offset);
        [$b, $offset] = VariableInt::unpack($buffer, $offset);
        [$c, $offset] = VariableInt::unpack($buffer, $offset);
        $this->assertSame([64, -1, 5], [$a, $b, $c]);
        $this->assertSame(strlen($buffer), $offset);
    }
}
