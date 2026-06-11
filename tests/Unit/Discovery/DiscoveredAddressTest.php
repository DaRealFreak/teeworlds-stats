<?php

namespace Tests\Unit\Discovery;

use App\TwStats\Discovery\DiscoveredAddress;
use PHPUnit\Framework\TestCase;

class DiscoveredAddressTest extends TestCase
{
    public function test_parses_an_ipv4_0_6_master_url(): void
    {
        $address = DiscoveredAddress::fromUrl('tw-0.6+udp://192.0.2.10:8303');

        $this->assertNotNull($address);
        $this->assertSame('192.0.2.10', $address->ip);
        $this->assertSame(8303, $address->port);
        $this->assertSame(6, $address->protocol);
    }

    public function test_parses_an_ipv6_0_7_master_url_and_strips_brackets(): void
    {
        $address = DiscoveredAddress::fromUrl('tw-0.7+udp://[2001:db8::5]:8310');

        $this->assertNotNull($address);
        $this->assertSame('2001:db8::5', $address->ip);
        $this->assertSame(8310, $address->port);
        $this->assertSame(7, $address->protocol);
    }

    public function test_returns_null_for_non_teeworlds_or_malformed_urls(): void
    {
        $this->assertNull(DiscoveredAddress::fromUrl('http://example.com'));
        $this->assertNull(DiscoveredAddress::fromUrl('tw-0.5+udp://192.0.2.10:8303'));
        $this->assertNull(DiscoveredAddress::fromUrl('tw-0.6+udp://192.0.2.10'));
        $this->assertNull(DiscoveredAddress::fromUrl(''));
    }
}
