# twstats Modernization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring the 7-year-old twstats app from Laravel 5.8 / PHP 7.3 to Laravel 13 / PHP 8.5 with a modern DDEV config and a Vite + Bootstrap 5 + Chart.js 4 frontend, preserving behavior.

**Architecture:** Five sequential phases, each ending in a green test suite or a verified working state: (1) build a regression test baseline on the current 5.8 app, (2) modernize DDEV, (3) rebuild on the Laravel 13 skeleton porting code forward, (4) modernize the frontend build, (5) clean up CI/docs. The regression suite written in Phase 1 is the safety net for Phases 2–4.

**Tech Stack:** PHP 8.5, Laravel 13, MariaDB 11.8, Node 22, Vite 6, Bootstrap 5, Chart.js 4, PHPUnit feature/unit tests.

---

## Execution environment notes (read first)

- **This Claude session runs INSIDE the DDEV web container.** Run bare commands: `composer`, `php artisan`, `vendor/bin/phpunit`, `npm`. Do **not** prefix with `ddev exec`.
- **`ddev` lifecycle commands (`ddev config`, `ddev restart`, `ddev start`) must be run by the USER on the host** — they are not available inside the container. Tasks that need them say so explicitly and pause for the user.
- The working directory is `/var/www/html`.
- Spec: `docs/superpowers/specs/2026-06-11-laravel-ddev-modernization-design.md`.
- The user handles all `git commit`s themselves. "Commit" steps below describe the intended commit; **stage and report, then let the user commit** (do not auto-commit).

---

## Phase 1 — Regression test baseline (on current Laravel 5.8)

Goal: lock current behavior with tests that survive the migration. Use direct `Model::create()` fixtures (not the legacy `factory()` helper) so the tests port to L13 with minimal change. Use an in-memory SQLite connection for tests so they run without touching MariaDB.

### Task 1.1: Configure the test database and base TestCase

**Files:**
- Modify: `phpunit.xml`
- Verify: `tests/TestCase.php`, `tests/CreatesApplication.php` (exist from skeleton)

- [ ] **Step 1: Point phpunit at an in-memory SQLite DB**

In `phpunit.xml`, inside `<php>`, ensure these env vars exist (add if missing, replace existing `DB_*` test lines):

```xml
<php>
    <env name="APP_ENV" value="testing"/>
    <env name="BCRYPT_ROUNDS" value="4"/>
    <env name="CACHE_DRIVER" value="array"/>
    <env name="MAIL_DRIVER" value="array"/>
    <env name="QUEUE_CONNECTION" value="sync"/>
    <env name="SESSION_DRIVER" value="array"/>
    <env name="DB_CONNECTION" value="sqlite"/>
    <env name="DB_DATABASE" value=":memory:"/>
    <env name="RESPONSE_CACHE_ENABLED" value="false"/>
</php>
```

- [ ] **Step 2: Confirm sqlite PDO is available**

Run: `php -m | grep -i sqlite`
Expected: prints `pdo_sqlite` and `sqlite3`. (Present in the DDEV web image.)

- [ ] **Step 3: Commit**

Intended commit: `test: configure in-memory sqlite for the test suite`
Stage: `git add phpunit.xml` — report to user, do not auto-commit.

### Task 1.2: Data-driven smoke tests for public GET routes

**Files:**
- Create: `tests/Feature/RouteSmokeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Clan;
use App\Models\Map;
use App\Models\Mod;
use App\Models\Player;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function seedMinimalData(): void
    {
        $mod = Mod::create(['name' => 'FNG']);
        Map::create(['name' => 'dm1']);
        Player::create(['name' => 'nameless tee', 'country' => 'DE']);
        Clan::create(['name' => 'TestClan']);
        Server::create([
            'name'    => 'Test Server',
            'ip'      => '127.0.0.1',
            'port'    => 8303,
            'map'     => 'dm1',
            'gametype'=> 'DM',
            'mod_id'  => $mod->getAttribute('id'),
        ]);
    }

    /** @dataProvider publicRouteProvider */
    public function test_public_routes_return_ok(string $uri): void
    {
        $this->seedMinimalData();

        $response = $this->get($uri);

        $this->assertContains(
            $response->getStatusCode(),
            [200, 302],
            "Route {$uri} returned {$response->getStatusCode()}"
        );
    }

    public static function publicRouteProvider(): array
    {
        return [
            'home'     => ['/'],
            'about'    => ['/about'],
            'general'  => ['/general'],
            'search'   => ['/search'],
            'tees'     => ['/tees'],
            'clans'    => ['/clans'],
            'servers'  => ['/servers'],
            'mods'     => ['/mods'],
            'maps'     => ['/maps'],
            'login'    => ['/login'],
            'register' => ['/register'],
        ];
    }
}
```

> NOTE: Confirm the `servers` table columns (`ip`, `port`, `map`, `gametype`, `mod_id`) against `database/migrations/2018_04_27_152529_create_servers_table.php` before running; adjust `Server::create([...])` keys to match the actual non-nullable columns. Do the same for `Map`/`Clan`/`Mod`/`Player` required columns.

- [ ] **Step 2: Run to verify it fails (or surfaces real column mismatches)**

Run: `vendor/bin/phpunit tests/Feature/RouteSmokeTest.php`
Expected: failures point to missing columns or 500s — fix `seedMinimalData()` until all rows insert and every route returns 200/302.

- [ ] **Step 3: Make it pass**

Adjust `seedMinimalData()` column keys to match the migrations. Re-run until green.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/RouteSmokeTest.php`
Expected: OK (11 tests).

- [ ] **Step 5: Commit**

Intended commit: `test: smoke-test all public GET routes`. Stage `tests/Feature/RouteSmokeTest.php`, report to user.

### Task 1.3: Search redirect & validation behavior tests

**Files:**
- Create: `tests/Feature/SearchFlowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Player;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_tee_search_redirects_back_with_error(): void
    {
        $response = $this->from('/search')->get('/tee');
        $response->assertRedirect();
        $response->assertSessionHasErrors('tee');
    }

    public function test_tee_search_with_name_redirects_to_detail_url(): void
    {
        $response = $this->get('/tee?tee_name=SomeTee');
        $response->assertRedirect(url('tee', urlencode('SomeTee')));
    }

    public function test_unknown_tee_redirects_to_search_with_suggestions_key(): void
    {
        Player::create(['name' => 'Existing Tee', 'country' => 'DE']);

        $response = $this->get('/tee/' . urlencode('Existing') . '/');

        $response->assertRedirect(url('search'));
        $response->assertSessionHasErrors('tee');
    }
}
```

- [ ] **Step 2: Run to verify behavior**

Run: `vendor/bin/phpunit tests/Feature/SearchFlowTest.php`
Expected: green against current 5.8 behavior. If `test_unknown_tee_redirects...` fails due to Searchy needing MySQL-specific SQL on sqlite, mark that one `@group mysql-only` and skip it in CI (note it — the Searchy path is fully re-tested in Phase 3 against the new FuzzySearch service which IS sqlite-compatible).

- [ ] **Step 3: Commit**

Intended commit: `test: cover search redirect and validation flows`. Stage the file, report to user.

### Task 1.4: TwStats packet-parse unit test (fixture-based, no live UDP)

**Files:**
- Create: `tests/Unit/TwStatsParseTest.php`
- Create: `tests/Fixtures/server_info_response.bin` (captured packet bytes)
- Read first: `app/TwStats/Controller/GameServerController.php`, `app/TwStats/Models/Server.php`, `app/TwStats/Models/Player.php`

- [ ] **Step 1: Identify the pure parse function**

Read `GameServerController.php` and locate the method that turns a raw response buffer into a `Server`/`Player` model (the part after `socket_recvfrom`). If parsing is entangled with the socket call, extract a pure method `parseServerInfo(string $payload): GameServer` first (refactor only — no behavior change), and cover it.

- [ ] **Step 2: Capture a fixture**

Write the exact bytes of one known-good server-info response into `tests/Fixtures/server_info_response.bin` (hardcode the byte string the parser expects, derived from the existing protocol constants in `NetworkController`).

- [ ] **Step 3: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\TwStats\Controller\GameServerController;
use Tests\TestCase;

class TwStatsParseTest extends TestCase
{
    public function test_parses_server_info_payload_into_model(): void
    {
        $payload = file_get_contents(base_path('tests/Fixtures/server_info_response.bin'));

        $server = (new GameServerController())->parseServerInfo($payload);

        $this->assertNotEmpty($server->getName());
        $this->assertIsInt($server->getNumClients());
    }
}
```

> Adjust method/getter names to the actual `GameServer` API discovered in Step 1.

- [ ] **Step 4: Run, refactor to pure method if needed, make green**

Run: `vendor/bin/phpunit tests/Unit/TwStatsParseTest.php`
Expected: PASS after the parse method is isolated and the fixture matches.

- [ ] **Step 5: Run the whole suite**

Run: `vendor/bin/phpunit`
Expected: all green.

- [ ] **Step 6: Commit**

Intended commit: `test: lock TwStats packet parsing with a fixture`. Stage the test + fixture (+ any pure-parse refactor), report to user.

**Phase 1 exit criteria:** `vendor/bin/phpunit` is fully green on Laravel 5.8.

---

## Phase 2 — DDEV modernization

Goal: get the **current (still 5.8)** app booting on PHP 8.5 / Node 22 / MariaDB 11.8. (5.8 does not officially support PHP 8.5, so expect some deprecation noise here; it is temporary — Phase 3 lands on L13 immediately after. If 5.8 will not boot at all on 8.5, do Phase 2 with `php_version: "8.3"` and bump to `"8.5"` at the end of Phase 3. Decide based on what `ddev restart` reports.)

### Task 2.1: Rewrite `.ddev/config.yaml` to the current schema

**Files:**
- Modify: `.ddev/config.yaml`

- [ ] **Step 1: Replace the config body**

Replace the top of `.ddev/config.yaml` (the directive block, lines ~1–23) with:

```yaml
name: twstats
type: laravel
docroot: public
php_version: "8.5"
webserver_type: nginx-fpm
nodejs_version: "22"
database:
  type: mariadb
  version: "11.8"
xdebug_enabled: false
use_dns_when_possible: true
timezone: "Europe/Berlin"
web_environment:
  - APP_ENV=local
hooks:
  post-start:
    - exec: echo '* * * * * /usr/bin/php /var/www/html/artisan schedule:run >> /dev/null 2>&1' | sudo tee /etc/cron.d/laravel-cron
    - exec: sudo chmod 0600 /etc/cron.d/laravel-cron && sudo crontab /etc/cron.d/laravel-cron && sudo service cron start
```

Remove the two obsolete NodeSource hook lines (`curl ... setup_13.x` and `apt-get install -y nodejs`). Remove the stale `APIVersion`, `webimage_extra_packages: ["cron"]` (cron is available; if the post-start hook reports cron missing, re-add `webimage_extra_packages: ["cron"]`).

- [ ] **Step 2: Sanity-check YAML**

Run: `php -r "var_dump(yaml_parse_file('.ddev/config.yaml'));"` if `ext-yaml` present, else `python3 -c "import yaml,sys; yaml.safe_load(open('.ddev/config.yaml'))"`.
Expected: parses without error.

### Task 2.2: User restarts DDEV and we verify the container

- [ ] **Step 1: Ask the user to restart DDEV on the host**

Message the user: "Please run `ddev restart` in your host terminal (this can't run inside the container), then tell me when it's back up."
Pause for confirmation.

- [ ] **Step 2: Verify versions in-container**

Run:
```bash
php -v
node -v
php -m | grep -i sockets
```
Expected: PHP 8.5.x, Node v22.x, `sockets` listed.

- [ ] **Step 3: Boot the current app**

Run: `php artisan --version`
Expected: `Laravel Framework 5.8.x` prints (vendor/ still from 5.8). If it fatals on PHP 8.5, fall back to `php_version: "8.3"` in `config.yaml`, ask the user to `ddev restart` again, and proceed (Phase 3 raises it back to 8.5).

- [ ] **Step 4: Run the baseline suite on the new container**

Run: `vendor/bin/phpunit`
Expected: green (sqlite in-memory, framework-version-independent). Investigate any failure before continuing.

- [ ] **Step 5: Commit**

Intended commit: `chore(ddev): modernize config to PHP 8.5 / Node 22 / MariaDB 11.8`. Stage `.ddev/config.yaml`, report to user.

**Phase 2 exit criteria:** `ddev restart` succeeds; `php -v`/`node -v` show targets; baseline suite green.

---

## Phase 3 — Rebuild on the Laravel 13 skeleton

Goal: move the app onto Laravel 13 with the modern skeleton, replacing dead dependencies. Keep the baseline suite green throughout (update test call sites only where the framework API forces it).

### Task 3.1: Scaffold a reference L13 skeleton to copy structural files from

**Files:**
- Temp: `/tmp/l13skel` (scratch, not committed)

- [ ] **Step 1: Create a throwaway L13 skeleton**

Run: `composer create-project laravel/laravel /tmp/l13skel "13.*" --no-scripts --no-interaction`
Expected: a clean L13 app in `/tmp/l13skel`. We copy its structural files in the next tasks; we do **not** copy its `app/`, `resources/`, `routes/web.php` over our domain code.

### Task 3.2: Replace `composer.json` with the L13 dependency set

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Rewrite `require`/`require-dev`**

Set `require` to:

```json
"require": {
    "php": "^8.3",
    "ext-json": "*",
    "ext-sockets": "*",
    "khill/php-duration": "^1.1",
    "laravel/framework": "^13.0",
    "laravel/tinker": "^2.9",
    "laravel/ui": "^4.6",
    "spatie/laravel-responsecache": "^7.7"
},
"require-dev": {
    "barryvdh/laravel-ide-helper": "^3.1",
    "fakerphp/faker": "^1.23",
    "laravel/pail": "^1.1",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.1",
    "phpunit/phpunit": "^11.0"
}
```

Remove entirely: `laravelcollective/html`, `tom-lingham/searchy`, `fideloper/proxy`, `fzaninotto/faker`, `doctrine/dbal`, `barryvdh/laravel-ide-helper` from `require` (it moves to dev).

> The exact compatible versions of `laravel/ui` and `spatie/laravel-responsecache` for L13 must be confirmed in Step 2 — let Composer resolve and adjust the constraints if it reports a conflict.

- [ ] **Step 2: Update autoload + scripts to the L13 skeleton**

Copy the `autoload`, `autoload-dev`, `scripts`, `extra`, and `config` blocks from `/tmp/l13skel/composer.json` into `composer.json`, but **keep `App\\` → `app/`**. Notably: drop the `classmap` for `database/seeds`/`database/factories` (L13 autoloads `database/` via PSR-4 `Database\Seeders\` / `Database\Factories\`). Set:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    }
}
```

- [ ] **Step 3: Do NOT run composer update yet** — structural files must land first (Task 3.3). Continue.

### Task 3.3: Bring in the modern skeleton structure

**Files:**
- Replace: `bootstrap/app.php`, `bootstrap/providers.php` (new), `public/index.php`, `artisan`
- Delete: `app/Http/Kernel.php`, `app/Console/Kernel.php`, `app/Exceptions/Handler.php`, `server.php`
- Regenerate: `config/*` (selectively)

- [ ] **Step 1: Copy skeleton bootstrap + entrypoints**

```bash
cp /tmp/l13skel/bootstrap/app.php bootstrap/app.php
cp /tmp/l13skel/bootstrap/providers.php bootstrap/providers.php
cp /tmp/l13skel/public/index.php public/index.php
cp /tmp/l13skel/artisan artisan
```

- [ ] **Step 2: Wire routing + scheduling into `bootstrap/app.php`**

Edit `bootstrap/app.php` so the schedule and routes are registered (this replaces `app/Console/Kernel.php`):

```php
<?php

use App\Console\Commands\UpdateData;
use App\Console\Commands\UpdateDailySummary;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*'); // replaces fideloper/proxy
    })
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command(UpdateData::class)
            ->everyTenMinutes()
            ->sendOutputTo(storage_path('logs/update_data/'.now()->timestamp.'.log'))
            ->after(function () {
                \Illuminate\Support\Facades\Artisan::call(UpdateDailySummary::class);
                \Illuminate\Support\Facades\Artisan::call('responsecache:clear');
            });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
```

- [ ] **Step 3: Delete legacy kernel/handler/entrypoint files**

```bash
git rm app/Http/Kernel.php app/Console/Kernel.php app/Exceptions/Handler.php server.php
```

(Keep `app/Http/Middleware/*` only if a custom middleware has real logic; the stock `TrustProxies`, `EncryptCookies`, `VerifyCsrfToken`, `TrimStrings`, `RedirectIfAuthenticated` are now framework-provided — delete the unmodified ones. Inspect each; delete if it matches the framework default.)

- [ ] **Step 4: Regenerate config selectively**

Copy the L13 defaults for files we did not customize, then re-apply customizations:

```bash
cp /tmp/l13skel/config/app.php config/app.php
cp /tmp/l13skel/config/auth.php config/auth.php
cp /tmp/l13skel/config/cache.php config/cache.php
cp /tmp/l13skel/config/database.php config/database.php
cp /tmp/l13skel/config/filesystems.php config/filesystems.php
cp /tmp/l13skel/config/logging.php config/logging.php
cp /tmp/l13skel/config/mail.php config/mail.php
cp /tmp/l13skel/config/queue.php config/queue.php
cp /tmp/l13skel/config/services.php config/services.php
cp /tmp/l13skel/config/session.php config/session.php
```

Keep `config/responsecache.php` as-is. Delete `config/broadcasting.php`, `config/hashing.php`, `config/view.php` only if unchanged from old defaults (diff them first). The new `config/app.php` has **no** `providers`/`aliases` arrays — provider registration now lives in `bootstrap/providers.php` (app providers) + package auto-discovery. This drops Searchy/Collective/IdeHelper manual registration automatically.

- [ ] **Step 5: Trim `bootstrap/providers.php`**

Ensure it lists only real app providers that still exist:

```php
<?php

return [
    App\Providers\AppServiceProvider::class,
];
```

Fold any needed logic from `AuthServiceProvider`/`EventServiceProvider`/`RouteServiceProvider`/`BroadcastServiceProvider` into `AppServiceProvider` or delete them if empty/default. (L11+ auto-discovers events, registers policies by convention, and routes are bound in `bootstrap/app.php`.) Delete the now-unused provider files with `git rm`.

### Task 3.4: Convert routes to FQCN syntax, add auth UI, remove debug routes

**Files:**
- Modify: `routes/web.php`
- Verify/Create: `routes/console.php`

- [ ] **Step 1: Rewrite `routes/web.php`**

Replace string actions with imports + FQCN arrays, drop `Auth::routes()` placement to after imports, and **remove the `/test` and `/rules` debug routes** (dead code):

```php
<?php

use App\Http\Controllers\AjaxSearchController;
use App\Http\Controllers\InformationController;
use App\Http\Controllers\MainController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

// Navigation general routes
Route::get('/', [MainController::class, 'home'])->name('home');
Route::get('/about', [MainController::class, 'about'])->name('about');
Route::get('/general', [MainController::class, 'general'])->name('general');
Route::get('/search', [SearchController::class, 'main'])->name('search');

// Edit routes (auth)
Route::get('/tee/edit/{tee_name}', [InformationController::class, 'editPlayer'])->name('editPlayer');
Route::get('/clan/edit/{clan_name}', [InformationController::class, 'editClan'])->name('editClan');
Route::get('/server/edit/{server_id}/{server_name}', [InformationController::class, 'editServer'])->name('editServer');

// Search + detail routes
Route::get('/tee', [SearchController::class, 'searchTee'])->name('tee');
Route::get('/tee/{tee_name}/', [SearchController::class, 'searchTeeByName'])->name('searchTeeByName');
Route::get('/clan', [SearchController::class, 'searchClan'])->name('clan');
Route::get('/clan/{clan_name}/', [SearchController::class, 'searchClanByName'])->name('searchClanByName');
Route::get('/server', [SearchController::class, 'searchServer'])->name('server');
Route::get('/server/{server_name}/', [SearchController::class, 'searchServerByName'])->name('searchServerByName');
Route::get('/server/{server_id}/{server_name}/', [SearchController::class, 'searchServerByIdAndName'])->name('searchServerByIdAndName');
Route::get('/mod', [SearchController::class, 'searchMod'])->name('mod');
Route::get('/mod/{mod_name}/', [SearchController::class, 'searchModByName'])->name('searchModByName');
Route::get('/map', [SearchController::class, 'searchMap'])->name('map');
Route::get('/map/{map_name}/', [SearchController::class, 'searchMapByName'])->name('searchMapByName');

// List routes
Route::get('/tees', [MainController::class, 'players'])->name('players');
Route::get('/clans', [MainController::class, 'clans'])->name('clans');
Route::get('/servers', [MainController::class, 'servers'])->name('servers');
Route::get('/mods', [MainController::class, 'mods'])->name('mods');
Route::get('/maps', [MainController::class, 'maps'])->name('maps');

// Ajax search routes
Route::get('/search/tee', [AjaxSearchController::class, 'searchTee'])->name('searchTeeAjax');
Route::get('/search/clan', [AjaxSearchController::class, 'searchClan'])->name('searchClanAjax');
Route::get('/search/server', [AjaxSearchController::class, 'searchServer'])->name('searchServerAjax');
Route::get('/search/mod', [AjaxSearchController::class, 'searchMod'])->name('searchModAjax');
Route::get('/search/map', [AjaxSearchController::class, 'searchMap'])->name('searchMapAjax');

// Authentication routes (laravel/ui)
Auth::routes();
```

> NOTE: the old file had two routes both named `general` (`/general` and `/` were both `->name('general')`, and `/home` duplicated `home`). The rewrite keeps a single `home` and single `general`. Update the test data provider in `RouteSmokeTest` if `/home` was relied on (it 404s now — that's intended cleanup; remove `/home` from the provider).

- [ ] **Step 2: Install laravel/ui auth scaffolding (Bootstrap 5)**

Deferred until after `composer update` (Task 3.10). For now ensure `routes/console.php` exists (copy `/tmp/l13skel/routes/console.php`) so `withRouting(commands: ...)` resolves.

### Task 3.5: Rewrite factories as class-based with fakerphp

**Files:**
- Create: `database/factories/PlayerFactory.php`, `ClanFactory.php`, `ServerFactory.php`, `UserFactory.php` (overwrite the old global-style ones)
- Modify: `app/Models/Player.php` etc. — add `use HasFactory;`

- [ ] **Step 1: Add the `HasFactory` trait to each model**

In `app/Models/Player.php`, `Clan.php`, `Server.php`, `User.php`, add:

```php
use Illuminate\Database\Eloquent\Factories\HasFactory;
// inside the class:
    use HasFactory;
```

- [ ] **Step 2: Rewrite `PlayerFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'name'    => $this->faker->name(),
            'country' => $this->faker->countryCode(),
        ];
    }
}
```

- [ ] **Step 3: Rewrite `UserFactory.php`** (fix removed `str_random` + bcrypt)

```php
<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'name'           => $this->faker->name(),
            'email'          => $this->faker->unique()->safeEmail(),
            'password'       => Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }
}
```

- [ ] **Step 4: Rewrite `ClanFactory.php` and `ServerFactory.php`**

Port the existing `$factory->define(Clan::class, ...)` / `Server::class` bodies into the same class pattern (namespace `Database\Factories`, `definition(): array`, `$this->faker->...`). Use the exact field set from the old factory files.

### Task 3.6: Namespace seeders and rename the directory

**Files:**
- Rename: `database/seeds/` → `database/seeders/`
- Modify: every seeder class

- [ ] **Step 1: Move the directory**

```bash
git mv database/seeds database/seeders
```

- [ ] **Step 2: Namespace each seeder**

In `database/seeders/DatabaseSeeder.php` and each `*TableSeeder.php`, add `namespace Database\Seeders;`, keep `use Illuminate\Database\Seeder;`. `DatabaseSeeder::run()` becomes:

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            ClansTableSeeder::class,
            PlayersTableSeeder::class,
            ServersTableSeeder::class,
        ]);
    }
}
```

Replace any `factory(Model::class, N)->create()` calls inside seeders with `Model::factory()->count(N)->create()`.

### Task 3.7: Build the FuzzySearch replacement for Searchy (TDD)

**Files:**
- Create: `app/Service/FuzzySearch.php`
- Create: `tests/Unit/FuzzySearchTest.php`

(Per project convention, application services live in `app/Service`, not `app/Domain/Service`.)

- [ ] **Step 1: Write the failing test**

```php
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
        Player::create(['name' => 'gores', 'country' => 'DE']);     // exact
        Player::create(['name' => 'goresw', 'country' => 'DE']);    // prefix
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
```

- [ ] **Step 2: Run to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/FuzzySearchTest.php`
Expected: FAIL — class `App\Service\FuzzySearch` not found.

- [ ] **Step 3: Implement `FuzzySearch`**

```php
<?php

namespace App\Service;

use Illuminate\Database\Eloquent\Builder;

class FuzzySearch
{
    /**
     * Add a weighted `relevance` score to the query based on how the
     * given column matches the term: exact (100), prefix (60), contains (40).
     * Mirrors the prior tom-lingham/searchy single-field behavior so callers
     * can chain ->having('relevance', '>', N)->limit(M)->get().
     */
    public static function on(Builder $query, string $column, string $term): Builder
    {
        $term = trim($term);

        return $query
            ->selectRaw("{$query->getModel()->getTable()}.*")
            ->selectRaw(
                "(CASE
                    WHEN {$column} = ? THEN 100
                    WHEN {$column} LIKE ? THEN 60
                    WHEN {$column} LIKE ? THEN 40
                    ELSE 0
                END) AS relevance",
                [$term, $term.'%', '%'.$term.'%']
            )
            ->orderByDesc('relevance');
    }
}
```

> `getTable()` qualifies `*` to avoid clashing with the computed `relevance` column. `having('relevance', ...)` works on both MySQL/MariaDB and SQLite.

- [ ] **Step 4: Run to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/FuzzySearchTest.php`
Expected: PASS.

### Task 3.8: Swap `SearchController` from Searchy to FuzzySearch

**Files:**
- Modify: `app/Http/Controllers/SearchController.php`

- [ ] **Step 1: Replace the import and each call**

Remove `use TomLingham\Searchy\Facades\Searchy;`, add `use App\Service\FuzzySearch;`. Replace each of the 6 blocks of the form:

```php
Searchy::search('players')
    ->fields('name')
    ->query($tee_name)->getQuery()
    ->having('relevance', '>', 20)
    ->limit(10)
    ->get()->toArray()
```

with:

```php
FuzzySearch::on(Player::query(), 'name', $tee_name)
    ->having('relevance', '>', 20)
    ->limit(10)
    ->get()->toArray()
```

Use the matching model for each (`Player`, `Clan`, `Server`, `Mod`, `Map`). The surrounding `Model::hydrate(...)` calls stay unchanged.

### Task 3.9: Replace `Form::` usage in `search.blade.php` with plain Blade

**Files:**
- Modify: `resources/views/search.blade.php`

- [ ] **Step 1: Convert each `Form::` block**

Replace each `{{ Form::open([...]) }} ... {{ Form::close() }}` with a plain GET `<form>`. The five forms are identical in shape — apply this pattern (shown for the tee form), substituting `tee`/`tee_name`/`Tee name`/`url('tee')` per block:

```blade
<form action="{{ url('tee') }}" method="get">
    <div class="mb-3">
        <label for="tee_name" class="form-label">Tee name</label>
        @if ($errors->has('tee'))
            <input type="text" name="tee_name" id="tee_name"
                   class="form-control is-invalid" placeholder="Tee name">
            <div class="invalid-feedback">{{ $errors->get('tee')[0] }}
                @if (session('teeSuggestions') !== null && !session('teeSuggestions')->isEmpty())
                    , try one of the following:
                    <ul class="list-group">
                        @foreach (session('teeSuggestions') as $suggestion)
                            <li class="list-group-item list-group-item-transparent">
                                <a href="{{ url('tee', urlencode($suggestion['name'])) }}">{{ $suggestion['name'] }}</a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @else
            <input type="text" name="tee_name" id="tee_name"
                   class="form-control" placeholder="Tee name">
        @endif
    </div>
    <div class="mb-3">
        <button type="submit" class="btn btn-primary">Submit</button>
    </div>
</form>
```

(`mr-sm-3`/`form-group`/`form-control-label` are Bootstrap-4 classes; the replacement already uses Bootstrap-5 `mb-3`/`form-label`. The server/mod/map suggestion `url(...)` args follow the original — server uses `[id, name]`, mod uses `$suggestion['mod']`, map uses `$suggestion['map']`.)

### Task 3.10: Install dependencies, scaffold auth UI, fix breakages, go green

- [ ] **Step 1: Update Composer**

Run: `composer update --no-interaction -W`
Expected: resolves to Laravel 13.x. If `laravel/ui` or `spatie/laravel-responsecache` conflict, adjust their constraints to the latest tag that lists `illuminate/*: ^13` in its `composer.json` and re-run. If responsecache has no L13 release, set `RESPONSE_CACHE_ENABLED=false` in `.env` and skip its provider temporarily (note it in the final report).

- [ ] **Step 2: Generate the Bootstrap-5 auth scaffolding**

Run: `php artisan ui bootstrap --auth`
Expected: writes auth controllers/views + `resources/sass`/`resources/js` bootstrap bits. **Do not overwrite** the existing `layouts/app.blade.php` if prompted — keep the project layout; only take the `auth/*` views and the `HomeController`/auth controllers if missing.

- [ ] **Step 3: Run the rector-style fixups the suite reveals**

Run: `vendor/bin/phpunit`
Expected: failures point at concrete L6–L13 API removals. Fix each as it appears, e.g.:
- `str_random`/`str_slug`/`array_get` global helpers → `Str::random`/`Str::slug`/`Arr::get`.
- `->lists()` → `->pluck()`.
- Carbon/`->toDateTimeString()` edge cases.
- `Input::` facade → `$request->`/`request()`.
- Any `\Auth::routes()`-related view name (`home`) → confirm `HomeController@index` or repoint.

Re-run until green.

- [ ] **Step 4: Update Phase-1 test fixtures to L13 factory API**

If any baseline test used `factory(...)`, switch to `Model::factory()`. Re-run the previously-skipped `@group mysql-only` search test (now backed by FuzzySearch on sqlite) and un-skip it.

- [ ] **Step 5: Boot check**

Run:
```bash
php artisan route:list
php artisan about
```
Expected: routes resolve; `about` shows Laravel 13.x, PHP 8.5.x.

- [ ] **Step 6: Commit**

Intended commit: `feat: rebuild on Laravel 13 skeleton, replace dead deps`. Stage all Phase-3 changes, report to user.

**Phase 3 exit criteria:** `composer update` resolves to L13; `vendor/bin/phpunit` green; `php artisan about` reports Laravel 13 / PHP 8.5.

---

## Phase 4 — Frontend modernization (Vite + Bootstrap 5 + Chart.js 4)

Goal: replace laravel-mix/webpack/node-sass with Vite + dart-sass, upgrade Bootstrap 4→5 and Chart.js 2→4. The baseline suite stays green (it does not exercise JS/CSS); visual checks happen at the end.

### Task 4.1: Replace `package.json`

**Files:**
- Modify: `package.json`
- Delete: `webpack.mix.js`

- [ ] **Step 1: Rewrite `package.json`**

```json
{
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "vite build"
  },
  "devDependencies": {
    "axios": "^1.7",
    "laravel-vite-plugin": "^1.0",
    "sass": "^1.77",
    "vite": "^6.0"
  },
  "dependencies": {
    "@popperjs/core": "^2.11",
    "bootstrap": "^5.3",
    "chart.js": "^4.4",
    "humanize-duration": "^3.31",
    "jquery": "^3.7",
    "jquery-ui": "^1.13",
    "jquery-validation": "^1.21"
  }
}
```

Removed: `laravel-mix`, `node-sass`, `sass-loader`, `cross-env`, `lodash`, `vue`, `vue-template-compiler`, `font-awesome` (replace with `@fortawesome/fontawesome-free` if the SCSS imports it — check `font-awesome.scss`), `popper.js` (→ `@popperjs/core`), `jquery.cookie` (unmaintained; if `front.js`/`laravel.js` use `$.cookie`, replace with the `js-cookie` package or vanilla — grep first), `resolve-url-loader` (mix-only).

```bash
git rm webpack.mix.js
```

### Task 4.2: Add `vite.config.js`

**Files:**
- Create: `vite.config.js`

- [ ] **Step 1: Write the config**

```js
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/assets/sass/app.scss',
                'resources/assets/sass/font-awesome.scss',
                'resources/assets/js/app.js',
            ],
            refresh: true,
        }),
    ],
    resolve: {
        alias: {
            '~bootstrap': 'bootstrap',
        },
    },
});
```

> Keep the existing `resources/assets/...` paths (the project does not use the default `resources/js`/`resources/css`). Confirm `app.scss` still `@import`s the sub-scss (`dark_admin_bootstrapius`, `_variables`, etc.).

### Task 4.3: Rewrite the JS entry points for Vite + Bootstrap 5

**Files:**
- Modify: `resources/assets/js/app.js`, `resources/assets/js/bootstrap.js`

- [ ] **Step 1: Rewrite `bootstrap.js`** (Bootstrap 5 bundles Popper; drop lodash/echo cruft)

```js
import axios from 'axios';
import $ from 'jquery';
import 'bootstrap';

window.$ = window.jQuery = $;
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document.head.querySelector('meta[name="csrf-token"]');
if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}
```

- [ ] **Step 2: Rewrite `app.js`** (ESM imports; Chart.js 4 registration)

```js
import './bootstrap';
import 'jquery-ui/ui/widgets/autocomplete.js';
import 'jquery-validation';
import { Chart, registerables } from 'chart.js';
import humanizeDuration from 'humanize-duration';

Chart.register(...registerables);
window.Chart = Chart;
window.humanizeDuration = humanizeDuration;

import './laravel';
import './charthelper';
import './front';
```

> If `front.js`/`laravel.js`/`charthelper.js` reference `$.cookie`, replace with `js-cookie` (`import Cookies from 'js-cookie'`) and add it to deps. Grep: `grep -rn "\.cookie" resources/assets/js`.

### Task 4.4: Switch the layout to `@vite` and Bootstrap-5 attributes

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

- [ ] **Step 1: Replace the asset tags**

Replace lines 18–24 (the `<script src="{{ asset('js/app.js') }}">` and the two `<link ... css>` tags) with:

```blade
    @vite([
        'resources/assets/sass/app.scss',
        'resources/assets/sass/font-awesome.scss',
        'resources/assets/js/app.js',
    ])
```

- [ ] **Step 2: Bootstrap 4 → 5 attribute renames in the layout**

- Line ~67–68: `data-toggle="dropdown"` → `data-bs-toggle="dropdown"`; remove the `v-pre` attribute (Vue leftover).
- Line ~106: `data-toggle="collapse"` → `data-bs-toggle="collapse"`; add `data-bs-target="#list_view_dropdown"` (BS5 needs the explicit target alongside `href`).
- The logout `onclick` inline handler is fine (no CSP on this project — unlike the TYPO3 portal).

### Task 4.5: Fix SCSS for dart-sass + Bootstrap 5

**Files:**
- Modify: `resources/assets/sass/app.scss`, `_variables.scss`, `dark_admin_bootstrapius.scss`, `font-awesome.scss`

- [ ] **Step 1: Update the Bootstrap import**

Wherever Bootstrap 4 is imported (e.g. `@import '~bootstrap/scss/bootstrap';` via node_modules), keep the path but ensure `$enable-*` and removed BS4 variables are gone. dart-sass is stricter: replace `/` division with `math.div()` and add `@use 'sass:math';` at the top of files that do math.

- [ ] **Step 2: Build to surface remaining Sass errors**

Run: `npm install`
Then: `npm run build`
Expected: first run lists concrete Sass deprecations/errors (removed BS4 mixins like `make-col`, `media-breakpoint` changes, `$grays` map). Fix each reported file until the build completes and writes the manifest under `public/build/`.

### Task 4.6: Update `charthelper.js`/`ChartUtility.php` for Chart.js 4

**Files:**
- Modify: `resources/assets/js/charthelper.js`
- Modify (if it emits chart config): `app/Utility/ChartUtility.php`

- [ ] **Step 1: Migrate the Chart.js API**

Chart.js 2 → 4 breaking changes to apply in `charthelper.js`:
- `options.scales.xAxes`/`yAxes` arrays → `options.scales.x`/`y` objects.
- `scaleLabel` → `title`; `ticks.callback` stays.
- Time scale needs an adapter: `import 'chartjs-adapter-date-fns'` (+ add `chartjs-adapter-date-fns` and `date-fns` deps) if any dataset uses a `time` axis.
- Global `Chart.defaults.global.*` → `Chart.defaults.*`.

Run `npm run build` after edits; load the charts page during Task 4.8 to confirm rendering.

### Task 4.7: Bootstrap 4 → 5 sweep across remaining Blade templates

**Files (enumerated — apply the deterministic mapping below to each):**
- `resources/views/general.blade.php`, `about.blade.php`, `main.blade.php`, `down.blade.php`
- `resources/views/list/{server,player,clan,mods,maps}.blade.php`
- `resources/views/detail/{server,mod}.blade.php` (+ any others under `detail/`)
- `resources/views/edit/{server,player,clan}.blade.php`
- `resources/views/auth/{login,register}.blade.php`

- [ ] **Step 1: Apply the BS4→BS5 mapping to every file above**

Deterministic find→replace (verify each hit in context; these are the complete BS4→5 deltas this app uses):

| Bootstrap 4 | Bootstrap 5 |
|---|---|
| `data-toggle=` | `data-bs-toggle=` |
| `data-target=` | `data-bs-target=` |
| `data-dismiss=` | `data-bs-dismiss=` |
| `data-parent=` | `data-bs-parent=` |
| `ml-` / `mr-` | `ms-` / `me-` |
| `pl-` / `pr-` | `ps-` / `pe-` |
| `text-left` / `text-right` | `text-start` / `text-end` |
| `float-left` / `float-right` | `float-start` / `float-end` |
| `font-weight-` | `fw-` |
| `font-italic` | `fst-italic` |
| `badge-secondary` (etc.) | `text-bg-secondary` (etc.) |
| `close` (button class) | `btn-close` (+ remove inner `&times;` span) |
| `form-group` | `mb-3` |
| `form-control-label` | `form-label` |
| `custom-select` | `form-select` |
| `sr-only` | `visually-hidden` |
| `.no-gutters` | `.g-0` |

- [ ] **Step 2: Grep to confirm no BS4 leftovers**

Run:
```bash
grep -rnE "data-toggle=|data-target=|data-dismiss=|\bml-|\bmr-|\bpl-|\bpr-|text-left|text-right|font-weight-|badge-(primary|secondary|success|danger|warning|info|light|dark)|form-group|form-control-label|custom-select|sr-only" resources/views
```
Expected: no matches (or only intentional ones you've confirmed are BS5-safe).

### Task 4.8: Build and visually verify

- [ ] **Step 1: Production build**

Run: `npm run build`
Expected: completes; `public/build/manifest.json` exists.

- [ ] **Step 2: Run the dev server (optional) / load pages**

Ask the user to open the site (`https://twstats.ddev.site`) and confirm: home, `/general` (charts render via Chart.js 4), `/search` (forms + jQuery-UI autocomplete), a list page, a detail page, login/register. Drive the same pages with Playwright (X-Forwarded-Proto:https + Host header) if available, capturing screenshots for the dropdown/collapse interactions.

- [ ] **Step 3: Suite + commit**

Run: `vendor/bin/phpunit`
Expected: green. Intended commit: `feat: modernize frontend to Vite + Bootstrap 5 + Chart.js 4`. Stage Phase-4 changes, report to user.

**Phase 4 exit criteria:** `npm run build` succeeds on Node 22; pages render correctly with Bootstrap 5 and Chart.js 4; suite green.

---

## Phase 5 — Cleanup (CI, docs, env)

### Task 5.1: Update GitHub Actions workflows

**Files:**
- Modify: `.github/workflows/php.yml`
- Modify: `.github/workflows/theme.yml`

- [ ] **Step 1: Rewrite `php.yml`**

```yaml
name: Composer

on: [push, pull_request]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: sockets, json, pdo_sqlite
      - name: Validate composer.json and composer.lock
        run: composer validate --strict
      - name: Install dependencies
        run: composer install --prefer-dist --no-progress
      - name: Run tests
        run: vendor/bin/phpunit
```

- [ ] **Step 2: Rewrite `theme.yml`**

```yaml
name: Theme

on: [push, pull_request]

jobs:
  build:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
      - name: Install dependencies
        run: npm ci
      - name: Build assets
        run: npm run build
```

> `npm ci` requires `package-lock.json` to be regenerated by the Phase-4 `npm install`; commit the new lockfile (the project commits lockfiles).

### Task 5.2: Update README and `.env.example`

**Files:**
- Modify: `README.md`, `.env.example`

- [ ] **Step 1: README requirements section**

Update the Requirements block: `PHP 8.5`, `MariaDB 11.8`, `Node 22`, `Composer 2`. Replace "There are no tests yet" with the test command (`vendor/bin/phpunit`). Replace `npm run production` with `npm run build`.

- [ ] **Step 2: `.env.example`**

- Rename `BROADCAST_DRIVER`→`BROADCAST_CONNECTION`, `QUEUE_DRIVER`→`QUEUE_CONNECTION`, `MAIL_DRIVER`→`MAIL_MAILER` (L13 env names).
- Drop the `MIX_PUSHER_*` lines (mix-only); add `VITE_*` only if any JS reads them (this app doesn't — omit).
- Keep `CRONTASK_INTERVAL`, `RESPONSE_CACHE_ENABLED`, the `DB_*` block.

### Task 5.3: Final end-to-end verification

- [ ] **Step 1: Full check**

Run:
```bash
composer validate --strict
vendor/bin/phpunit
php artisan about
npm run build
```
Expected: all succeed; `about` shows Laravel 13 / PHP 8.5.

- [ ] **Step 2: Scheduler smoke check**

Run: `php artisan schedule:list`
Expected: lists the `UpdateData` ten-minute schedule wired in `bootstrap/app.php`.

- [ ] **Step 3: Final commit**

Intended commit: `chore: update CI, README, and env for the modernized stack`. Stage Phase-5 changes, report to user.

**Phase 5 exit criteria:** CI workflows target PHP 8.5/Node 22 and run tests/build; README + `.env.example` current; full verification passes.

---

## Spec coverage check

- DDEV PHP 8.5 / Node 22 / MariaDB 11.8 → Phase 2.
- Fresh-skeleton Laravel 13 rebuild → Phase 3 (Tasks 3.1–3.3).
- Route FQCN conversion → Task 3.4.
- Dependency replacement map (collective/html, searchy, fideloper/proxy, faker, dbal, tinker, collision, responsecache, ide-helper, laravel/ui) → Tasks 3.2, 3.7–3.10.
- Searchy → in-house FuzzySearch → Tasks 3.7–3.8.
- `laravelcollective/html` removal → Task 3.9.
- Factories/seeders modernization → Tasks 3.5–3.6.
- Vite + dart-sass + Bootstrap 5 + Chart.js 4 → Phase 4.
- Tests-first regression baseline → Phase 1.
- CI / README / env cleanup → Phase 5.

## Known follow-ups / risks carried into execution

- **`spatie/laravel-responsecache` L13 compatibility** — confirmed at Task 3.10 Step 1; fallback is to disable response caching, not downgrade.
- **5.8 on PHP 8.5** during Phase 2 may be noisy; fallback to `php_version: "8.3"` until Phase 3 completes (noted in Phase 2 intro).
- **Bootstrap 5 visual regressions** — not covered by the PHPUnit suite; Task 4.8 adds manual/Playwright visual verification.
- **`$.cookie` / jQuery-UI usage** — grep-gated replacements in Tasks 4.1/4.3; resolve if hits found.
