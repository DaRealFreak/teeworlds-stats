<?php

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerCosmeticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_persists_a_ddnet_cosmetic_snapshot(): void
    {
        $player = Player::factory()->create([
            'skin'       => 'glow_cammo',
            'color_body' => 16726016,
            'color_feet' => 16745499,
            'afk'        => false,
        ]);

        $fresh = $player->fresh();
        $this->assertSame('glow_cammo', $fresh->skin);
        $this->assertSame(16726016, $fresh->color_body);
        $this->assertSame(16745499, $fresh->color_feet);
        $this->assertFalse($fresh->afk);
    }

    public function test_player_skin_parts_round_trips_as_an_array(): void
    {
        $parts = [
            'body'    => ['name' => 'standard', 'color' => 65408],
            'marking' => ['name' => 'duodonny', 'color' => 65408],
        ];

        $player = Player::factory()->create(['skin_parts' => $parts]);

        $this->assertSame($parts, $player->fresh()->skin_parts);
    }

    public function test_player_without_cosmetics_has_a_null_snapshot(): void
    {
        $fresh = Player::factory()->create()->fresh();

        $this->assertNull($fresh->skin);
        $this->assertNull($fresh->color_body);
        $this->assertNull($fresh->color_feet);
        $this->assertNull($fresh->afk);
        $this->assertNull($fresh->skin_parts);
    }
}
