<?php

declare(strict_types=1);

namespace Tests\Feature\Connection;

use App\TwStats\Connection\ConnectionInterface;
use App\TwStats\Connection\MapDownloadSession;
use App\TwStats\Connection\MapDownloadSessionFactory;
use App\TwStats\Connection\SevenMapDownloadSession;
use App\TwStats\Discovery\FlavorClassifier;
use PHPUnit\Framework\TestCase;

class MapDownloadSessionFactoryTest extends TestCase
{
    private function conn(): ConnectionInterface
    {
        return new class implements ConnectionInterface {
            public function sendVital(string $message): void {}
            public function flush(): void {}
            public function receive(int $timeoutMs): array { return []; }
            public function disconnect(): void {}
            public function isOnline(): bool { return true; }
        };
    }

    public function testVanilla07GetsTheSevenSession(): void
    {
        $session = MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_VANILLA_07);

        self::assertInstanceOf(SevenMapDownloadSession::class, $session);
    }

    public function testDdnetAndVanilla06GetTheSixSession(): void
    {
        self::assertInstanceOf(
            MapDownloadSession::class,
            MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_DDNET),
        );
        self::assertInstanceOf(
            MapDownloadSession::class,
            MapDownloadSessionFactory::for($this->conn(), FlavorClassifier::FLAVOR_VANILLA_06),
        );
    }
}
