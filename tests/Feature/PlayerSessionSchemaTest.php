<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlayerSessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_sessions_has_afk_counter_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('player_sessions', 'afk_minutes'));
        $this->assertTrue(Schema::hasColumn('player_sessions', 'afk_tracked_minutes'));
    }
}
