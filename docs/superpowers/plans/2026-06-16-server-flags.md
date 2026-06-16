# Server Flags (password / timescore) — capture, skip, display

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development. Steps use `- [ ]`.

**Goal:** Capture the Teeworlds server-info `flags` bitfield (currently read-and-discarded), persist it, **skip
password-protected servers as UDP download candidates** (their download can't work — we send an empty
password and 0.6/DDNet validates it before MAP_CHANGE; live-confirmed `server de teste` flags=1 →
connect_failed), and **show the flags in the server browser**.

**Flag values** (`ddnet/src/engine/shared/protocol.h`): `SERVER_FLAG_PASSWORD = 1<<0`, `SERVER_FLAG_TIMESCORE = 1<<1`. 0.7 carries only PASSWORD (bit 0); TIMESCORE is DDNet/0.6.

**Data flow:** `SixInfoCodec`/`SevenInfoCodec` → `SixInfoPacket` / 0.7 packet → `Teeworlds06Source`/`Teeworlds07Source` → `DiscoveredServer` → `ServerMerger` (takes `$primary`'s fields) → `ServerPersister` (sets server attrs) → `Server` → `MainController::liveServers` → `resources/views/list/live.blade.php`.

**Conventions:** TDD (red→green→commit); comments explain *why*; stage ONLY touched files (NEVER `git add -A/-u/.` — untracked `ddnet/`, `teeworlds/`, etc. must stay untracked); commit msg ends `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`. `Map`/`Mod` have no factory (`::create`); `Server` has one. Suite is SQLite `:memory:`; dev DB is MySQL (`php artisan migrate`).

---

## Task A: capture `flags` end-to-end → `servers.flags`

**Files:** migration `database/migrations/2026_06_16_000200_add_flags_to_servers_table.php`; `app/TwStats/Protocol/Six/SixInfoCodec.php` + `SixInfoPacket.php`; `app/TwStats/Protocol/Seven/SevenInfoCodec.php` + its packet DTO; `app/TwStats/Model/DiscoveredServer.php`; `app/TwStats/Discovery/Teeworlds06Source.php` + `Teeworlds07Source.php`; `app/TwStats/Discovery/ServerMerger.php`; `app/TwStats/Persistence/ServerPersister.php`; `app/Models/Server.php`. Tests under `tests/Unit/Protocol/...` + `tests/Feature/...`.

- [ ] Migration: `$table->unsignedSmallInteger('flags')->default(0)->after('flavor');` (down: drop).
- [ ] `SixInfoCodec`: stop discarding `flags` (`SixInfoPacket.php:63` area) — parse it as `(int)` and pass to `SixInfoPacket` (add a `public readonly int $flags = 0` field). The 0.6 field is a decimal string between gametype and the player counts.
- [ ] `SevenInfoCodec`: capture the `flags` int (currently `$unpacker->getInt(); // flags`) into the 0.7 packet DTO (add a `flags` field).
- [ ] `DiscoveredServer`: add `public readonly int $flags = 0` (trailing/defaulted so existing call sites compile).
- [ ] `Teeworlds06Source` + `Teeworlds07Source`: pass `flags: $header->flags` when building `DiscoveredServer`.
- [ ] `ServerMerger::merge`: set `flags: $primary->flags` on the merged `DiscoveredServer` (consistent with the other primary-sourced fields).
- [ ] `ServerPersister`: `$model->setAttribute('flags', $server->flags);` alongside the other attrs (~line 49).
- [ ] `Server` model: constants `FLAG_PASSWORD = 1`, `FLAG_TIMESCORE = 2`; cast `flags` → integer; `isPasswordProtected(): bool` = `((int)$this->flags & self::FLAG_PASSWORD) !== 0`; `usesTimeScore(): bool` = `((int)$this->flags & self::FLAG_TIMESCORE) !== 0`.
- [ ] Tests: (1) `SixInfoCodec` parses `flags` (build an inf3/iext payload with flags=1, assert packet `->flags === 1`); a 0.7 codec test if a fixture helper exists. (2) `ServerPersister` writes `flags` (persist a `DiscoveredServer` with flags=3, assert the row). (3) `Server` model accessors (flags 0/1/2/3 → the two booleans). Reuse existing codec test fixtures (`tests/Unit/Protocol/Six/*`).
- [ ] `php artisan migrate`; full suite green; commit.

## Task B: skip password servers as UDP download candidates

**Files:** `app/Service/GameServerMapSource.php`, `app/Service/MapArchiveFiller.php`; tests.

- [ ] `GameServerMapSource::downloadFromCandidates`: add a filter excluding servers whose `flags & 1` is set. The candidate query is over `server_histories`; join/`whereNotExists`-style exclude against `servers` where `(flags & 1) = 1` (use a `whereNotIn('server_id', Server passworded ids)` or a `whereNotExists` on `servers` with `whereRaw('servers.id = server_histories.server_id and (servers.flags & 1) = 1')`). Portable for SQLite + MySQL (both support `&`). Comment WHY (download can't authenticate).
- [ ] `MapArchiveFiller::liveHostSet`: add the same `(flags & 1) = 0` constraint so a password host never counts as a "healthy live host".
- [ ] Tests: a version whose only live host is password-protected → not dialed (connection factory never invoked; records `no_hosts`); a non-password host serving the same version is still dialed. Model on `tests/Feature/Connection/GameServerMapSourceBackoffTest.php`.
- [ ] Full suite green; commit.

## Task C: show flags in the server browser

**Files:** `resources/views/list/live.blade.php` (+ `app/Http/Controllers/MainController::liveServers` only if `$serverEntry` is a transformed DTO rather than the `Server` model — check first); test `tests/Feature/LiveServerBrowserTest.php` (extend).

- [ ] In the "Server" cell (~line 116, next to `server-name`), render a small Font-Awesome 4.7 indicator: `@if($serverEntry->isPasswordProtected())<i class="fa fa-lock" title="Password protected" aria-label="Password protected"></i>@endif` and `@if($serverEntry->usesTimeScore())<i class="fa fa-clock-o" title="Time-based scores"></i>@endif`. If `$serverEntry` is not the `Server` model, expose `flags`/the two booleans on whatever it is.
- [ ] Keep it accessible (title + aria-label); escape everything.
- [ ] Test: render `/serverbrowser` (or the view) with a password server in the fixture → assert the lock markup/`title="Password protected"` is present, and absent for an open server. Reuse `LiveServerBrowserTest` patterns.
- [ ] `php artisan responsecache:clear` noted for manual testing (the route is response-cached). No JS/SCSS change → no `npm run build`.
- [ ] Full suite green; commit.

## Final
- [ ] Full suite green; smoke `/serverbrowser` after `responsecache:clear`; update memory; report.
