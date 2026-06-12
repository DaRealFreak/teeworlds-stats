# Multi-ecosystem Ingestion — Phase 5: Remove the Legacy UDP Scraper — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Delete the now-dead legacy 0.6 UDP scraper (controllers, transient DTOs, the IPv6 helper, and its parser test), which nothing references since `UpdateData` cut over to the DDNet HTTP + Teeworlds 0.7 pipeline.

**Background & scope decision:** Phase 4 made the live scraper source from `DdnetHttpSource` + `Teeworlds07Source`. The spec's "native 0.6 source" is intentionally **not** built — but note the reason: the legacy `teeworlds.com:8300` 0.6 master is **still alive** (it answers the plain `xe…req2` browse request and returns ~1256 addresses; `master1` is flaky but `master4` answers instantly). The native 0.6 source is omitted because it is **redundant**, not dead: a 2026-06-12 measurement found the DDNet `servers.json` feed already contains 1247/1248 of the 0.6 master's servers (gap of exactly 1 transient server) and carries strictly richer data — real player lists, skins, colors, afk — than a 0.6 UDP `inf3` re-query could. So Phase 5 is the cleanup half only. (Future resilience note: if DDNet's HTTP master ever goes away, a native 0.6 source querying `teeworlds.com:8300` — all four masters, aggregated — is the natural fallback for the vanilla-0.6 population. Not built now: YAGNI.)

**Dependency graph (verified):** the legacy classes form a closed island — `NetworkController`, `MasterServerController`, `GameServerController`, the `App\TwStats\Models\{GameServer,MasterServer,Server,Player}` DTOs, and `App\TwStats\Utility\IPv6Utility` are referenced only by each other and by `tests/Unit/TwStatsParseTest.php`. `App\TwStats\Utility\Countries` is NOT part of the island (used by `UpdateData` + `ChartUtility`) and stays.

**Tech Stack:** Laravel 13. Run `vendor/bin/phpunit`.

---

## Task 1: Delete the legacy island + verify

**Files (delete):**
- `app/TwStats/Controller/NetworkController.php`
- `app/TwStats/Controller/MasterServerController.php`
- `app/TwStats/Controller/GameServerController.php`
- `app/TwStats/Models/GameServer.php`
- `app/TwStats/Models/MasterServer.php`
- `app/TwStats/Models/Server.php`
- `app/TwStats/Models/Player.php`
- `app/TwStats/Utility/IPv6Utility.php`
- `tests/Unit/TwStatsParseTest.php`
- `tests/Fixtures/server_info_response.bin`

- [ ] **Step 1: Re-confirm the island is closed**

Run: `grep -rnE "MasterServerController|GameServerController|NetworkController|TwStats\\\\Models\\\\(GameServer|MasterServer|Server|Player)|IPv6Utility" app/ tests/ | grep -vE "app/TwStats/Controller/|app/TwStats/Models/|app/TwStats/Utility/IPv6Utility|tests/Unit/TwStatsParseTest"`
Expected: NO output (nothing outside the island references it). If anything prints, STOP and report — a live consumer still exists.

- [ ] **Step 2: Delete the files**

```bash
git rm app/TwStats/Controller/NetworkController.php \
       app/TwStats/Controller/MasterServerController.php \
       app/TwStats/Controller/GameServerController.php \
       app/TwStats/Models/GameServer.php \
       app/TwStats/Models/MasterServer.php \
       app/TwStats/Models/Server.php \
       app/TwStats/Models/Player.php \
       app/TwStats/Utility/IPv6Utility.php \
       tests/Unit/TwStatsParseTest.php \
       tests/Fixtures/server_info_response.bin
```

- [ ] **Step 3: Confirm the autoloader and suite are clean**

Run: `composer dump-autoload` (drops any cached references to the deleted classes), then `vendor/bin/phpunit`.
Expected: all green. The total test count drops by the `TwStatsParseTest` cases; everything else passes unchanged (no production code referenced the deleted classes).

- [ ] **Step 4: Confirm the live scraper still runs**

Run: `php artisan migrate:fresh --force` then `timeout 120 php artisan data:update` then a quick count:

```bash
php artisan tinker --execute="echo App\Models\Server::count().' servers, '.App\Models\Player::count().' players'.PHP_EOL;"
```
Expected: the scrape completes with no error (it uses only the new pipeline) and persists a plausible server/player count. Confirms nothing operational depended on the deleted code.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor(serverbrowser): remove the dead legacy 0.6 UDP scraper

The live scraper sources from the DDNet HTTP feed and the native Teeworlds
0.7 UDP source; the old master/game-server controllers and their transient
DTOs are no longer referenced. No native 0.6 source is added — there is no
live 0.6 master to discover from and the DDNet feed already covers the 0.6
population with richer data.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage (Phase 5):**
- Remove the dead legacy controllers + DTOs → Task 1. ✓
- Native 0.6 source: consciously omitted because it is **redundant** (the live `teeworlds.com:8300` 0.6 master IS reachable — ~1256 servers — but the DDNet feed mirrors it to within 1/1248 and carries richer data) — documented in this plan. The `a79499e` commit message states the older, incorrect "no live 0.6 master" rationale; this plan supersedes it. ✓
- `App\TwStats\Utility\Countries` retained (live consumer: `UpdateData`/`ChartUtility`). ✓

**Placeholder scan:** none.

**Safety:** the deletion is gated by a re-confirmation grep (Step 1) that no consumer outside the island remains, a green suite (Step 3), and a successful live scrape (Step 4). The only test removed is the legacy-parser `TwStatsParseTest`; the `App\TwStats\Discovery`/`Protocol\Seven`/`Persistence` codecs and the new e2e `UpdateDataTest` remain the coverage for the live pipeline.
