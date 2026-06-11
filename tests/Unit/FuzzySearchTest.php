<?php

namespace Tests\Unit;

use App\Models\Player;
use App\Service\FuzzySearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FuzzySearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_ranks_exact_prefix_and_contains_matches(): void
    {
        Player::create(['name' => 'gores', 'country' => 'DE']);       // exact
        Player::create(['name' => 'goresw', 'country' => 'DE']);      // prefix
        Player::create(['name' => 'xx gores xx', 'country' => 'DE']); // contains
        Player::create(['name' => 'unrelated', 'country' => 'DE']);

        $results = FuzzySearch::on(Player::query(), 'name', 'gores')
            ->having('relevance', '>', 20)
            ->limit(10)
            ->get();

        $names = $results->pluck('name')->all();

        $this->assertSame('gores', $names[0]);     // exact ranks first
        $this->assertContains('goresw', $names);
        $this->assertContains('xx gores xx', $names);
        $this->assertNotContains('unrelated', $names);
    }
}
