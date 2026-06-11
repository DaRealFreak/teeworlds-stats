<?php

namespace Tests\Unit;

use App\TwStats\Controller\GameServerController;
use App\TwStats\Models\GameServer;
use Tests\TestCase;

/**
 * Lock the Teeworlds SERVERBROWSE_INFO packet parser against regressions.
 *
 * Parsing is done by GameServerController::processPacket(), which is already
 * a pure static method that accepts a raw byte string and a GameServer model
 * (no live socket involved).  The fixture file
 * tests/Fixtures/server_info_response.bin encodes a hand-crafted but
 * structurally valid 'inf3' (SERVERBROWSE_INFO / vanilla) UDP response with:
 *
 *   - server name  "TestServer"
 *   - version      "0.7.5"
 *   - map          "dm1"
 *   - gametype     "DM"
 *   - numclients   2 / maxclients 16
 *   - 2 players:  "Alice" (no clan)  and  "Bob" (clan "MyClan")
 *
 * Packet layout (matches the NetworkController constants):
 *   bytes 0-5   : arbitrary 6-byte envelope header
 *   bytes 6-13  : type magic "\xff\xff\xff\xffinf3"  (SERVERBROWSE_INFO)
 *   bytes 14+   : NUL-delimited payload slots; last byte is a filler and
 *                 is stripped by the substr($data, 14, strlen($data)-15) call
 *                 inside processPacket.
 *
 * Fixture generation (for documentation — do NOT regenerate during tests):
 *   $slots  = ['7','0.7.5','TestServer','dm1','DM','0','2','16','2','16',
 *              'Alice','','276','5','1', 'Bob','MyClan','276','3','1'];
 *   $data   = str_repeat("\x00", 6) . "\xff\xff\xff\xffinf3"
 *           . implode("\x00", $slots) . 'X';  // 'X' = stripped filler byte
 *   // _token = chr(0x07) — token integer 7: (7 & 0xff) === ord(chr(7))
 */
class TwStatsParseTest extends TestCase
{
    /** @var string */
    private $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = __DIR__ . '/../Fixtures/server_info_response.bin';
    }

    public function test_fixture_file_exists(): void
    {
        $this->assertFileExists(
            $this->fixturePath,
            'Fixture file tests/Fixtures/server_info_response.bin is missing'
        );
    }

    /**
     * Feed the captured fixture to processPacket and assert the parsed fields.
     */
    public function test_vanilla_info_packet_is_parsed_correctly(): void
    {
        $data = file_get_contents($this->fixturePath);
        $this->assertNotFalse($data, 'Could not read fixture file');

        // Build a GameServer with the token that matches the fixture (byte 0x07).
        $server = new GameServer(['ip' => '127.0.0.1', 'port' => 8303]);
        $server->setAttribute('_token', chr(0x07));
        $server->setAttribute('_request_token', chr(0x00) . chr(0x00));

        GameServerController::processPacket($data, $server);

        // Token validation must pass
        $this->assertTrue(
            $server->getAttribute('response'),
            'processPacket rejected the packet (token mismatch or unrecognised type)'
        );

        // Packet type
        $this->assertSame('vanilla', $server->getAttribute('server_type'));

        // Server metadata
        $this->assertSame('TestServer', $server->getAttribute('name'));
        $this->assertSame('0.7.5',      $server->getAttribute('version'));
        $this->assertSame('dm1',        $server->getAttribute('map'));
        $this->assertSame('DM',         $server->getAttribute('gametype'));

        // Client counts
        $this->assertSame(2,  $server->getAttribute('numclients'));
        $this->assertSame(16, $server->getAttribute('maxclients'));
        $this->assertSame(2,  $server->getAttribute('numplayers'));
        $this->assertSame(16, $server->getAttribute('maxplayers'));

        // Players
        $players = $server->getAttribute('players');
        $this->assertCount(2, $players);

        $alice = $players[0];
        $this->assertSame('Alice', $alice->getAttribute('name'));
        $this->assertSame('',      $alice->getAttribute('clan'));
        $this->assertSame(276,     $alice->getAttribute('country'));
        $this->assertSame(5,       $alice->getAttribute('score'));
        $this->assertSame(1,       $alice->getAttribute('ingame'));

        $bob = $players[1];
        $this->assertSame('Bob',    $bob->getAttribute('name'));
        $this->assertSame('MyClan', $bob->getAttribute('clan'));
        $this->assertSame(276,      $bob->getAttribute('country'));
        $this->assertSame(3,        $bob->getAttribute('score'));
        $this->assertSame(1,        $bob->getAttribute('ingame'));
    }

    /**
     * Verify that a mismatched token causes processPacket to reject the packet.
     */
    public function test_token_mismatch_rejects_packet(): void
    {
        $data = file_get_contents($this->fixturePath);

        $server = new GameServer(['ip' => '127.0.0.1', 'port' => 8303]);
        // Fixture encodes token 7; supply byte 0x08 — mismatch
        $server->setAttribute('_token', chr(0x08));
        $server->setAttribute('_request_token', chr(0x00) . chr(0x00));

        GameServerController::processPacket($data, $server);

        $this->assertFalse(
            $server->getAttribute('response'),
            'processPacket should have rejected the packet on token mismatch'
        );
    }
}
