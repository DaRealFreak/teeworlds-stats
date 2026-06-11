# Schema-default cleanup — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Give the legacy NOT-NULL-without-default columns real schema defaults / nullability, and remove the per-call creation-default workarounds that Phase 3b added to `UpdateData`.

**Background:** `server_histories.minutes`, `player_histories.minutes` (NOT NULL int, no default) and `clans.introduction`, `clans.website`, `players.country` (NOT NULL string/text, no default) relied on MySQL's non-strict implicit defaults; strict SQLite (and a strict MySQL) reject inserts that omit them. Phase 3b worked around this with creation-defaults scattered in `UpdateData`. Centralizing in the schema removes the workarounds and restores the carried history helpers to verbatim.

**Tech Stack:** Laravel 13 `->change()` migrations (native, no doctrine/dbal needed in L13), SQLite `:memory:` tests + MySQL dev. Run `vendor/bin/phpunit` in the DDEV web container.

---

## Task 1: Add the schema defaults + revert the UpdateData workarounds

**Files:**
- Create: `database/migrations/2026_06_12_000300_default_legacy_not_null_columns.php`
- Modify: `app/Console/Commands/UpdateData.php`

- [ ] **Step 1: Create the migration**

Create `database/migrations/2026_06_12_000300_default_legacy_not_null_columns.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // These columns were NOT NULL with no default; the scraper relied on MySQL's non-strict
        // implicit defaults. Give them real defaults/nullability so every source's player/clan/
        // history inserts work on strict drivers without per-call workarounds in the command.
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(0)->change();
        });
        Schema::table('player_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(0)->change();
        });
        Schema::table('clans', function (Blueprint $table) {
            $table->text('introduction')->nullable()->change();
            $table->string('website')->nullable()->change();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->string('country')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('server_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(null)->change();
        });
        Schema::table('player_histories', function (Blueprint $table) {
            $table->unsignedInteger('minutes')->default(null)->change();
        });
        Schema::table('clans', function (Blueprint $table) {
            $table->text('introduction')->nullable(false)->change();
            $table->string('website')->nullable(false)->change();
        });
        Schema::table('players', function (Blueprint $table) {
            $table->string('country')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 2: Run the suite to confirm the schema change is clean**

Run: `vendor/bin/phpunit`
Expected: all green (the migration applies under `RefreshDatabase`; nothing depends on these columns being non-nullable). If `->change()` errors on SQLite, STOP and report — do not work around it silently.

- [ ] **Step 3: Revert the four creation-default workarounds in `UpdateData`**

In `app/Console/Commands/UpdateData.php`:

a) In `updatePlayers`, change the player lookup back to a single-key `firstOrCreate`:

```php
            $playerModel = Player::firstOrCreate(['name' => $client->name]);
```
(remove any second `['country' => ...]` defaults argument.)

b) In `updatePlayers`, change the clan lookup back to a single-key `firstOrCreate`:

```php
                $clanModel = Clan::firstOrCreate(['name' => $client->clan]);
```
(remove any second `['introduction' => '', 'website' => '']` defaults argument.)

c) In `updateServerHistory`, remove the `'minutes' => 0,` line from the `ServerHistory::create([...])` call (the column now defaults to 0; the next line still does `setAttribute('minutes', ... + CRONTASK_INTERVAL)`).

d) In `updatePlayerHistory`, remove the `'minutes' => 0,` line from the `PlayerHistory::create([...])` call.

- [ ] **Step 4: Run the suite to confirm the reverts are safe**

Run: `vendor/bin/phpunit`
Expected: all green — in particular `UpdateDataTest` still passes (minutes now default to 0 at the DB level; country is set via `setAttribute` before `save`; clan intro/website are nullable).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_12_000300_default_legacy_not_null_columns.php app/Console/Commands/UpdateData.php
git commit -m "refactor(serverbrowser): default legacy NOT NULL columns at the schema level

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Rebuild the dev DB and confirm

**Files:** none (verification only).

- [ ] **Step 1:** Run `php artisan migrate:fresh --force` — expect all migrations (incl. the new `->change()` one) apply on MySQL with no error.
- [ ] **Step 2:** Run `vendor/bin/phpunit` — expect all green.

---

## Self-Review

- The migration centralizes the insert-time defaults the command was supplying ad hoc; reverting the four `UpdateData` workarounds restores the carried `updateServerHistory`/`updatePlayerHistory` to truly verbatim and simplifies the player/clan `firstOrCreate` calls.
- `players.country` becomes nullable but is always set by `setAttribute` before `save`, so no consumer ever sees null in practice.
- `clans.introduction`/`website` become nullable (TEXT cannot carry a MySQL default pre-8.0.13, so nullable is the portable choice).
- No behaviour change to histories: `minutes` starts at 0 (now via schema default) then accumulates `CRONTASK_INTERVAL` exactly as before.
