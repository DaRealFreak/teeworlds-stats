<?php

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * (a) Posting to /tee with no tee_name redirects back and flashes a 'tee' error.
     */
    public function test_empty_tee_search_redirects_back_with_error(): void
    {
        // Without a Referer the "back" redirect goes to '/'
        $response = $this->get('/tee');

        // assertRedirect asserts both 3xx status and the Location header target.
        $response->assertRedirect('/');

        // In Laravel 5.8 the flashed session errors are readable directly on
        // the redirect response — no need to follow the redirect.
        $response->assertSessionHasErrors('tee');
    }

    /**
     * (b) GET /tee?tee_name=SomeTee redirects to url('tee', urlencode('SomeTee')).
     */
    public function test_tee_search_with_name_redirects_to_tee_url(): void
    {
        $name = 'SomeTee';
        $response = $this->get('/tee?tee_name=' . urlencode($name));

        $response->assertStatus(302);
        $response->assertRedirect(url('tee', urlencode($name)));
    }

    /**
     * (c) GET /tee/{name}/ for an unknown player redirects to 'search' with a 'tee' error.
     *
     * Now backed by App\Service\FuzzySearch (CASE/HAVING), which is SQLite-compatible,
     * so this path runs against the in-memory SQLite test database. A known player is
     * seeded so the fuzzy suggestion query has data to rank.
     */
    public function test_unknown_tee_name_redirects_to_search_with_error(): void
    {
        Player::create(['name' => 'Existing Tee', 'country' => 'DE']);

        $response = $this->get('/tee/' . urlencode('Existing') . '/');

        $response->assertRedirect(url('search'));
        $response->assertSessionHasErrors('tee');
    }
}
