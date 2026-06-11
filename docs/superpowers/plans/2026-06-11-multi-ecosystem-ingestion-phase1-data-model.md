# Multi-ecosystem Ingestion — Phase 1: Data Model — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reshape the schema so a server is a *logical* entity owning a set of protocol-tagged addresses, and players can hold a last-seen cosmetic snapshot — the foundation for combining the 0.6 / 0.7 / DDNet ecosystems.

**Architecture:** Add a `server_addresses` child table (the address set is the server's identity, mirroring DDNet's `servers.json` grouping); drop the old `servers(ip,port)` uniqueness and add a derived `flavor` label; add nullable cosmetic columns to `players`. No data migration — the dev DB is rebuilt with `php artisan migrate:fresh`.

**Tech Stack:** Laravel 13, Eloquent, SQLite `:memory:` for tests (`phpunit.xml`), MySQL in DDEV. Run bare `php artisan` / `vendor/bin/phpunit` inside the DDEV web container.

**Spec:** `docs/superpowers/specs/2026-06-11-multi-ecosystem-server-ingestion-design.md` (§6 Data model, §7 Identity, §9 Classification).

**Scope note:** This is the first of five phase plans. Later phases (DdnetHttpSource, merge/dedup engine + UpdateData wiring, Teeworlds07Source, 0.6 refactor) get their own plans once this lands.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_06_12_000000_create_server_addresses_table.php` | New `server_addresses` table (ip, port, protocol, is_canonical, FK→servers, `unique(ip,port,protocol)`). |
| `app/Models/ServerAddress.php` | Eloquent model for one protocol-tagged endpoint; `belongsTo(Server)`. |
| `database/factories/ServerAddressFactory.php` | Test factory for `ServerAddress`. |
| `app/Models/Server.php` (modify) | Add `addresses()` / `canonicalAddress()` relationships + `protocols()` helper. |
| `database/migrations/2026_06_12_000100_add_flavor_and_drop_unique_on_servers_table.php` | Drop `servers(ip,port)` unique; add nullable `flavor`. |
| `database/migrations/2026_06_12_000200_add_cosmetics_to_players_table.php` | Add nullable `skin`, `color_body`, `color_feet`, `afk`, `skin_parts` to `players`. |
| `app/Models/Player.php` (modify) | Add `$casts` for the cosmetic columns. |
| `tests/Feature/ServerAddressTest.php` | Address relationships, protocol set, uniqueness. |
| `tests/Feature/ServerFlavorTest.php` | Flavor persistence; (ip,port) no longer unique. |
| `tests/Feature/PlayerCosmeticsTest.php` | Cosmetic snapshot persistence + `skin_parts` JSON round-trip. |

---

## Task 1: `server_addresses` table, model, factory + Server relationships

**Files:**
- Create: `tests/Feature/ServerAddressTest.php`
- Create: `database/migrations/2026_06_12_000000_create_server_addresses_table.php`
- Create: `app/Models/ServerAddress.php`
- Create: `database/factories/ServerAddressFactory.php`
- Modify: `app/Models/Server.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ServerAddressTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_has_many_addresses_and_exposes_its_protocol_set(): void
    {
        $server = Server::factory()->create();

        ServerAddress::create([
            'server_id'    => $server->id,
            'ip'           => '127.0.0.1',
            'port'         => 8303,
            'protocol'     => 6,
            'is_canonical' => true,
        ]);
        ServerAddress::create([
            'server_id'    => $server->id,
            'ip'           => '127.0.0.1',
            'port'         => 8303,
            'protocol'     => 7,
            'is_canonical' => false,
        ]);

        $server->refresh();

        $this->assertCount(2, $server->addresses);
        $this->assertSame([6, 7], $server->protocols());
        $this->assertSame(6, $server->canonicalAddress->protocol);
        $this->assertTrue($server->canonicalAddress->is_canonical);
    }

    public function test_same_ip_port_protocol_cannot_be_inserted_twice(): void
    {
        $server = Server::factory()->create();

        ServerAddress::create([
            'server_id' => $server->id,
            'ip'        => '127.0.0.1',
            'port'      => 8303,
            'protocol'  => 6,
        ]);

        $this->expectException(QueryException::class);

        ServerAddress::create([
            'server_id' => $server->id,
            'ip'        => '127.0.0.1',
            'port'      => 8303,
            'protocol'  => 6,
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ServerAddressTest`
Expected: FAIL — `Class "App\Models\ServerAddress" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_12_000000_create_server_addresses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A logical server is reachable through one or more protocol-tagged endpoints
        // (a DDNet "sixup" server answers both 0.6 and 0.7). The address set is the
        // server's identity, mirroring how the DDNet master groups a server's addresses[].
        Schema::create('server_addresses', function (Blueprint $table) {
            $table->increments('id');
            $table->timestamps();

            $table->unsignedInteger('server_id');
            $table->string('ip');
            $table->unsignedInteger('port');
            $table->unsignedTinyInteger('protocol'); // 6 or 7
            $table->boolean('is_canonical')->default(false);

            $table->foreign('server_id')->references('id')->on('servers')->onDelete('cascade');
            $table->unique(['ip', 'port', 'protocol']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_addresses');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/ServerAddress.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * App\Models\ServerAddress
 *
 * @property int $id
 * @property int $server_id
 * @property string $ip
 * @property int $port
 * @property int $protocol
 * @property bool $is_canonical
 */
class ServerAddress extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected $casts = [
        'protocol'     => 'integer',
        'is_canonical' => 'boolean',
    ];

    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
```

- [ ] **Step 5: Create the factory**

Create `database/factories/ServerAddressFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\ServerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ServerAddress>
 */
class ServerAddressFactory extends Factory
{
    protected $model = ServerAddress::class;

    public function definition(): array
    {
        return [
            'server_id'    => Server::factory(),
            'ip'           => $this->faker->ipv4(),
            'port'         => $this->faker->numberBetween(1, 65535),
            'protocol'     => 6,
            'is_canonical' => true,
        ];
    }
}
```

- [ ] **Step 6: Add the relationships + helper to `Server`**

In `app/Models/Server.php`, add these methods inside the `Server` class (e.g. directly after the existing `players()` method). `ServerAddress` is in the same `App\Models` namespace, so no import is needed:

```php
    /**
     * every protocol-tagged endpoint this logical server is reachable through
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function addresses()
    {
        return $this->hasMany(ServerAddress::class);
    }

    /**
     * the preferred endpoint for display/contact
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function canonicalAddress()
    {
        return $this->hasOne(ServerAddress::class)->where('is_canonical', true);
    }

    /**
     * distinct, sorted protocol generations this server answers (e.g. [6, 7] = dual-stack);
     * drives the server-type classification and the serverbrowser badge
     *
     * @return int[]
     */
    public function protocols(): array
    {
        return $this->addresses->pluck('protocol')->unique()->sort()->values()->all();
    }
```

- [ ] **Step 7: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ServerAddressTest`
Expected: PASS (2 tests, both green).

- [ ] **Step 8: Commit**

```bash
git add app/Models/ServerAddress.php app/Models/Server.php \
        database/factories/ServerAddressFactory.php \
        database/migrations/2026_06_12_000000_create_server_addresses_table.php \
        tests/Feature/ServerAddressTest.php
git commit -m "feat(serverbrowser): add server_addresses table for logical-server identity

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: `servers.flavor` column + drop the (ip,port) unique constraint

**Files:**
- Create: `tests/Feature/ServerFlavorTest.php`
- Create: `database/migrations/2026_06_12_000100_add_flavor_and_drop_unique_on_servers_table.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ServerFlavorTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServerFlavorTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_stores_a_flavor_label(): void
    {
        $server = Server::factory()->create(['flavor' => 'ddnet']);

        $this->assertSame('ddnet', $server->fresh()->flavor);
    }

    public function test_two_logical_servers_may_share_an_ip_and_port(): void
    {
        Server::factory()->create(['ip' => '127.0.0.1', 'port' => 8303]);
        // before the unique constraint was dropped this second insert threw a QueryException
        Server::factory()->create(['ip' => '127.0.0.1', 'port' => 8303]);

        $this->assertDatabaseCount('servers', 2);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter ServerFlavorTest`
Expected: FAIL — `test_two_logical_servers_may_share_an_ip_and_port` throws a `QueryException` (UNIQUE constraint), and `flavor` has no column.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_12_000100_add_flavor_and_drop_unique_on_servers_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            // Identity now lives in server_addresses. ip/port remain on servers only as the
            // denormalised canonical pointer, so the old (ip,port) uniqueness no longer holds
            // (a sixup server has several protocol-tagged addresses at the same ip/port).
            $table->dropUnique(['ip', 'port']);
            // derived server-type label for display/filtering: ddnet | vanilla_06 | vanilla_07
            $table->string('flavor')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('flavor');
            $table->unique(['ip', 'port']);
        });
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter ServerFlavorTest`
Expected: PASS (2 tests green).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_12_000100_add_flavor_and_drop_unique_on_servers_table.php \
        tests/Feature/ServerFlavorTest.php
git commit -m "feat(serverbrowser): add servers.flavor and drop (ip,port) uniqueness

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: player cosmetic snapshot columns + casts

**Files:**
- Create: `tests/Feature/PlayerCosmeticsTest.php`
- Create: `database/migrations/2026_06_12_000200_add_cosmetics_to_players_table.php`
- Modify: `app/Models/Player.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PlayerCosmeticsTest.php`:

```php
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
        $player = Player::create([
            'name'       => 'vin',
            'country'    => -102,
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

        $player = Player::create([
            'name'       => 'glow',
            'country'    => -1,
            'skin_parts' => $parts,
        ]);

        $this->assertSame($parts, $player->fresh()->skin_parts);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit --filter PlayerCosmeticsTest`
Expected: FAIL — no `skin` / `skin_parts` column (`SQLSTATE` "no column named skin").

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_06_12_000200_add_cosmetics_to_players_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            // Last-seen cosmetic snapshot, populated only from the DDNet HTTP feed — no UDP
            // info payload (0.6 vanilla/extended, 0.7 inf3) carries skins or afk. Null for
            // players only ever seen over UDP.
            $table->string('skin')->nullable();
            $table->integer('color_body')->nullable();
            $table->integer('color_feet')->nullable();
            $table->boolean('afk')->nullable();
            $table->json('skin_parts')->nullable(); // 0.7 six-part skin: {body:{name,color}, ...}
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropColumn(['skin', 'color_body', 'color_feet', 'afk', 'skin_parts']);
        });
    }
};
```

- [ ] **Step 4: Add the casts to `Player`**

In `app/Models/Player.php`, add a `$casts` property directly below the existing `protected $guarded = ['id', 'created_at', 'updated_at'];` line:

```php
    protected $casts = [
        'color_body' => 'integer',
        'color_feet' => 'integer',
        'afk'        => 'boolean',
        'skin_parts' => 'array',
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `vendor/bin/phpunit --filter PlayerCosmeticsTest`
Expected: PASS (2 tests green).

- [ ] **Step 6: Commit**

```bash
git add app/Models/Player.php \
        database/migrations/2026_06_12_000200_add_cosmetics_to_players_table.php \
        tests/Feature/PlayerCosmeticsTest.php
git commit -m "feat(serverbrowser): add last-seen cosmetic snapshot columns to players

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Rebuild the dev DB and confirm the whole suite is green

**Files:** none (verification only).

- [ ] **Step 1: Rebuild the dev schema**

Run: `php artisan migrate:fresh`
Expected: all migrations run in order, including the three new ones; no errors.

- [ ] **Step 2: Run the full test suite**

Run: `vendor/bin/phpunit`
Expected: the entire suite (existing `tests/Unit` + `tests/Feature` plus the three new feature tests) is green. If any pre-existing test referenced `servers(ip,port)` uniqueness, note it — none is expected to.

- [ ] **Step 3: Commit (only if anything changed in Step 1–2)**

No code changes are expected here; if `migrate:fresh` surfaced a needed fixup, commit it with a clear message. Otherwise skip.

---

## Self-Review

**Spec coverage (Phase 1 slice of §6/§7/§9):**
- `server_addresses` table with `unique(ip,port,protocol)` + FK → Task 1. ✓
- `servers` logical identity: `unique(ip,port)` dropped, `flavor` added → Task 2. ✓ (ip/port kept on `servers` as the denormalised canonical pointer so existing consumers keep working until the Phase 3 wiring — a deliberate, documented refinement of §6.)
- Player cosmetic snapshot (`skin`, `color_body`, `color_feet`, `afk`, `skin_parts`) → Task 3. ✓
- `protocols()` helper backing the §9 server-type classification → Task 1. ✓
- Out of scope for Phase 1 (own later plans): the three source adapters, the merge/dedup engine, `UpdateData` wiring, and the serverbrowser badge/filter. The `flavor` value is only *stored* here; it is *computed* in the classification phase.

**Placeholder scan:** none — every step contains full code and an exact command.

**Type consistency:** `protocol` is an `int` cast everywhere; `is_canonical` a `bool`; `protocols()` returns `int[]` and is asserted as `[6, 7]`; `skin_parts` is an `array` cast and asserted via round-trip. Migration timestamps are strictly increasing (`000000` < `000100` < `000200`) so `migrate` and `migrate:fresh` both apply them in dependency order (`server_addresses` FK target `servers` already exists from 2018).
