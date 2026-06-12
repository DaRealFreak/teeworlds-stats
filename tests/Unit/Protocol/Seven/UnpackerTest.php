<?php

namespace Tests\Unit\Protocol\Seven;

use App\TwStats\Protocol\Seven\Unpacker;
use App\TwStats\Protocol\Seven\VariableInt;
use PHPUnit\Framework\TestCase;

class UnpackerTest extends TestCase
{
    public function test_reads_ints_strings_and_raw_in_order(): void
    {
        $buffer = VariableInt::pack(7) . "hello\x00" . VariableInt::pack(-2) . "rawbytes";
        $unpacker = new Unpacker($buffer);

        $this->assertSame(7, $unpacker->getInt());
        $this->assertSame('hello', $unpacker->getString());
        $this->assertSame(-2, $unpacker->getInt());
        $this->assertSame('rawbytes', $unpacker->getRaw(8));
        $this->assertFalse($unpacker->error());
    }

    public function test_reading_past_the_end_sets_the_error_flag(): void
    {
        $unpacker = new Unpacker("\x05"); // one int, then nothing
        $this->assertSame(5, $unpacker->getInt());
        $unpacker->getString(); // no NUL terminator left
        $this->assertTrue($unpacker->error());
    }
}
