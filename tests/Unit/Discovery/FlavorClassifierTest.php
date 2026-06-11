<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\FlavorClassifier;
use PHPUnit\Framework\TestCase;

class FlavorClassifierTest extends TestCase
{
    public function test_ddnet_when_version_carries_a_build_after_the_engine_version(): void
    {
        $this->assertSame('ddnet', FlavorClassifier::classify('0.6.4, 19.1'));
        $this->assertSame('ddnet', FlavorClassifier::classify('0.6.5, 18.8'));
        $this->assertSame('ddnet', FlavorClassifier::classify('DDNet 19.1'));
    }

    public function test_vanilla_07_for_an_07_engine_version(): void
    {
        $this->assertSame('vanilla_07', FlavorClassifier::classify('0.7.5'));
    }

    public function test_vanilla_06_for_a_plain_06_engine_version(): void
    {
        $this->assertSame('vanilla_06', FlavorClassifier::classify('0.6.4'));
    }
}
