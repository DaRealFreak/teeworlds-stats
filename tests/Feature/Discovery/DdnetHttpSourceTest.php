<?php

namespace Tests\Feature\Discovery;

use App\TwStats\Discovery\DdnetHttpSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DdnetHttpSourceTest extends TestCase
{
    private function fixture(): string
    {
        return file_get_contents(base_path('tests/Fixtures/ddnet_servers.json'));
    }

    public function test_fetches_and_parses_from_the_first_responding_mirror(): void
    {
        Http::fake([
            'https://a/*' => Http::response($this->fixture(), 200),
            'https://b/*' => Http::response('', 500),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);
        $servers = $source->fetch();

        $this->assertCount(3, $servers);
        $this->assertSame('DDNet GER10', $servers[0]->name);
    }

    public function test_fails_over_to_the_next_mirror_when_one_errors(): void
    {
        Http::fake([
            'https://a/*' => Http::response('', 500),
            'https://b/*' => Http::response($this->fixture(), 200),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);
        $servers = $source->fetch();

        $this->assertCount(3, $servers);
    }

    public function test_returns_empty_when_every_mirror_fails(): void
    {
        Http::fake([
            'https://a/*' => Http::response('', 500),
            'https://b/*' => Http::response('', 503),
        ]);

        $source = new DdnetHttpSource(mirrors: ['https://a/servers.json', 'https://b/servers.json']);

        $this->assertSame([], $source->fetch());
    }
}
