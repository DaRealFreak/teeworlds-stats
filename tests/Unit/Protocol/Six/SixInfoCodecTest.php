<?php

namespace Tests\Unit\Protocol\Six;

use App\TwStats\Protocol\Six\SixConnless;
use App\TwStats\Protocol\Six\SixInfoCodec;
use PHPUnit\Framework\TestCase;

class SixInfoCodecTest extends TestCase
{
    /** Build a NUL-delimited payload from already-stringified fields. */
    private function payload(array $fields): string
    {
        return implode("\x00", $fields) . "\x00";
    }

    public function test_parses_a_vanilla_inf3_header_and_clients(): void
    {
        $payload = $this->payload([
            '7777',                 // token (echoed; ignored)
            '0.6.4',                // version
            'My Server',            // name
            'dm1',                  // map
            'dm',                   // gametype
            '0',                    // flags
            '2',                    // numPlayers
            '16',                   // maxPlayers
            '2',                    // numClients
            '16',                   // maxClients
            'alice', 'TeeClan', '49', '7', '1',   // client 1 (player)
            'bob', '', '0', '0', '0',             // client 2 (spectator)
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO);

        $this->assertNotNull($packet);
        $this->assertTrue($packet->hasHeader);
        $this->assertSame('My Server', $packet->name);
        $this->assertSame('dm1', $packet->map);
        $this->assertSame('dm', $packet->gametype);
        $this->assertSame('0.6.4', $packet->version);
        $this->assertSame(16, $packet->maxClients);
        $this->assertSame(16, $packet->maxPlayers);
        $this->assertCount(2, $packet->clients);
        $this->assertSame('alice', $packet->clients[0]->name);
        $this->assertSame('TeeClan', $packet->clients[0]->clan);
        $this->assertSame(49, $packet->clients[0]->country);
        $this->assertSame(7, $packet->clients[0]->score);
        $this->assertTrue($packet->clients[0]->isPlayer);
        $this->assertFalse($packet->clients[1]->isPlayer);
        // 0.6 UDP carries no afk/skin
        $this->assertNull($packet->clients[0]->afk);
        $this->assertNull($packet->clients[0]->skin);
    }

    public function test_parses_an_extended_iext_header_with_reserved_fields(): void
    {
        $payload = $this->payload([
            '123',                  // token
            '0.6.4',                // version
            'Big Server',           // name
            'ctf5',                 // map
            '999',                  // mapCrc
            '512',                  // mapSize
            'ctf',                  // gametype
            '0',                    // flags
            '1',                    // numPlayers
            '64',                   // maxPlayers
            '1',                    // numClients
            '64',                   // maxClients
            '',                     // reserved
            'carol', 'X', '50', '3', '1', '',     // client + reserved
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO_EXTENDED);

        $this->assertNotNull($packet);
        $this->assertTrue($packet->hasHeader);
        $this->assertSame('Big Server', $packet->name);
        $this->assertSame('ctf5', $packet->map);
        $this->assertSame(64, $packet->maxClients);
        $this->assertCount(1, $packet->clients);
        $this->assertSame('carol', $packet->clients[0]->name);
        $this->assertTrue($packet->clients[0]->isPlayer);
    }

    public function test_parses_an_iex_plus_continuation_as_clients_only(): void
    {
        // continuation: token, chunkNumber, reserved, then clients (+reserved each)
        $payload = $this->payload([
            '123',                  // token
            '1',                    // chunk number
            '',                     // reserved
            'dave', 'Y', '0', '1', '1', '',
            'erin', '', '0', '2', '0', '',
        ]);

        $packet = (new SixInfoCodec())->parse($payload, SixConnless::INFO_EXTENDED_MORE);

        $this->assertNotNull($packet);
        $this->assertFalse($packet->hasHeader);
        $this->assertCount(2, $packet->clients);
        $this->assertSame('dave', $packet->clients[0]->name);
        $this->assertTrue($packet->clients[0]->isPlayer);
        $this->assertFalse($packet->clients[1]->isPlayer);
    }

    public function test_returns_null_when_the_header_is_truncated(): void
    {
        $payload = $this->payload(['123', '0.6.4', 'name']); // missing most header fields

        $this->assertNull((new SixInfoCodec())->parse($payload, SixConnless::INFO));
    }

    public function test_returns_null_for_an_unknown_command(): void
    {
        $this->assertNull((new SixInfoCodec())->parse('whatever', 'zzzz'));
    }
}
