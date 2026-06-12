<?php

namespace Tests\Feature;

use App\Models\Player;
use Tests\TestCase;

class PlayerTeeComponentTest extends TestCase
{
    /**
     * A player with no observed cosmetics resolves to no renderable tee (TeeSkin::describe
     * returns null), so the component renders the generic <img> placeholder. That image must
     * honour the :size prop — otherwise it balloons to the placeholder's native 204x204 in any
     * context that doesn't wrap it in a fixed-size .profile/.avatar frame (e.g. the mod/map
     * detail toplists).
     */
    public function test_fallback_placeholder_honours_the_size_prop(): void
    {
        $player = Player::factory()->make(['name' => 'Skinless', 'skin' => null, 'skin_parts' => null]);

        $view = $this->blade('<x-player-tee :player="$player" :size="28" />', ['player' => $player]);

        $view->assertSee('<img', false);
        $view->assertSee('width="28"', false);
        $view->assertSee('height="28"', false);
    }
}
