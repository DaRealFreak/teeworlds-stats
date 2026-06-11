<?php

namespace Tests\Feature;

use App\Models\Mod;
use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
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

    /**
     * (d) Regression: GET /mod/{near-miss}/ must flash a modSuggestions collection
     * hydrated as Mod models, not Player models.
     *
     * Before FIX 1 the controller called Player::hydrate() on the FuzzySearch results
     * from the mods table, so each suggestion was a Player instance with no 'name'
     * attribute populated from the mods row. After the fix, Mod::hydrate() is used
     * and each suggestion is a proper Mod instance.
     */
    public function test_mod_suggestion_is_hydrated_as_mod_not_player(): void
    {
        Mod::create(['name' => 'FNG']);

        $response = $this->get('/mod/' . urlencode('FN') . '/');

        $response->assertRedirect(url('search'));

        // Read the flashed collection before assertSessionHasErrors: that helper
        // re-serialises the session bag and converts objects to arrays as a side-effect.
        $suggestions = $response->getSession()->get('modSuggestions');
        $this->assertNotNull($suggestions, 'modSuggestions must be flashed to the session');
        $this->assertNotEmpty($suggestions, 'modSuggestions must not be empty');
        $first = $suggestions->first();
        $this->assertInstanceOf(Mod::class, $first, 'Each suggestion must be a Mod instance, not a Player');
        $this->assertSame('FNG', $first->name, 'The hydrated Mod must carry the name from the mods table');

        $response->assertSessionHasErrors('mod');
    }

    /**
     * (e) Regression: the /search view must render suggestions that arrive as plain
     * arrays, not Collections.
     *
     * The controllers flash Eloquent collections, but Laravel 11+'s default JSON
     * session serialization (config/session.php 'serialization' => 'json') flattens
     * every key except 'errors' to plain arrays on the real (cross-process) session
     * round-trip. The view previously called ->isEmpty() — a Collection method — on
     * the flashed suggestions, which fatals with "Call to a member function isEmpty()
     * on array" in the browser. The HTTP test session is an in-process singleton that
     * never serializes through the handler, so it keeps the live Collection and hides
     * the bug; rendering the view directly with the production-shaped state (array
     * suggestion + a real shared ViewErrorBag) reproduces it deterministically.
     */
    public function test_search_view_renders_array_shaped_suggestions(): void
    {
        // The suggestion block only renders inside the @if($errors->has('clan')) branch,
        // so share a real error bag the way ShareErrorsFromSession would.
        $errors = (new ViewErrorBag)->put(
            'default',
            new MessageBag(['clan' => ['This clan does not exist']])
        );
        View::share('errors', $errors);

        // The post-JSON-deserialization shape the browser actually sees: a plain array.
        session(['clanSuggestions' => [['id' => 1, 'name' => 'SomeClan']]]);

        $html = View::make('search')->render();

        $this->assertStringContainsString('This clan does not exist', $html);
        $this->assertStringContainsString('SomeClan', $html);
        $this->assertStringContainsString(url('clan', urlencode('SomeClan')), $html);
    }
}
