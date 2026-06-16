<?php

namespace Tests\Unit\Model;

use App\Models\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ServerFlagsTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: bool, 2: bool}>
     */
    public static function flagsProvider(): array
    {
        return [
            // flags => [isPasswordProtected, usesTimeScore]
            'open server'                => [0, false, false],
            'password only'              => [Server::FLAG_PASSWORD, true, false],
            'timescore only'             => [Server::FLAG_TIMESCORE, false, true],
            'password and timescore'     => [Server::FLAG_PASSWORD | Server::FLAG_TIMESCORE, true, true],
        ];
    }

    #[DataProvider('flagsProvider')]
    public function test_decodes_the_flags_bitfield(int $flags, bool $passworded, bool $timescore): void
    {
        $server = new Server();
        $server->setAttribute('flags', $flags);

        $this->assertSame($passworded, $server->isPasswordProtected());
        $this->assertSame($timescore, $server->usesTimeScore());
    }

    public function test_flag_constants_match_the_teeworlds_protocol(): void
    {
        // SERVER_FLAG_PASSWORD = 1<<0, SERVER_FLAG_TIMESCORE = 1<<1 (ddnet protocol.h)
        $this->assertSame(1, Server::FLAG_PASSWORD);
        $this->assertSame(2, Server::FLAG_TIMESCORE);
    }
}
