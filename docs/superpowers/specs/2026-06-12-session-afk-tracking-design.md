# Track AFK time during sessions

## Problem

The scraper records a player's `afk` flag (idle in-game) as a single boolean snapshot on
the player, refreshed every scrape from the DDNet feed. That tells you whether a player is
AFK *right now*, but nothing about how much of their play time was spent idle. We want to
accumulate **AFK time within each session**, the same way `player_sessions.minutes`
accumulates total session time, and surface it on the player page.

## Background

- `App\Service\SessionRecorder::record(Player, Server, Map, Mod)` is called once per player
  per scrape from `UpdateData::updatePlayers`. It extends the player's open session (adding
  `CRONTASK_INTERVAL` to `minutes`) when they are still on the same server, or closes the
  old session and opens a new one.
- `afk` comes only from the DDNet HTTP feed: `DiscoveredClient::$afk` is a real bool for feed
  sightings and `null` for UDP-only sightings (0.6/0.7 browser queries carry no afk). So AFK
  time can only be measured for feed-covered players — the same population that has skins.
- The player page (`resources/views/detail/player.blade.php`) already shows session-derived
  KPI tiles (Sessions, with an "avg … · longest …" hint) and a "Recent sessions" list with a
  per-row duration.

## Goals

- Store AFK minutes per session as a subset of the session's total minutes.
- Surface it on the player page: a player-level "Active time" tile with a "% afk" hint, and a
  per-session AFK annotation in the Recent sessions list.

## Non-goals

- No AFK tracking in `player_histories` (the aggregated weekday/hour/map/mod minutes). AFK
  lives in `player_sessions` only.
- No backfill of historical AFK (we never recorded per-scrape afk before). `afk_minutes`
  starts at 0 for existing rows and accrues from the next scrape onward.
- No change to the `afk` boolean snapshot on `players` (it stays as the "AFK right now" flag).

## Design

### 1. Schema

A migration adds one column to `player_sessions`:

```php
$table->unsignedInteger('afk_minutes')->default(0);
```

`minutes` remains the session total; `afk_minutes` is the AFK subset; active minutes are the
difference. The `default(0)` backfills existing rows. Run `php artisan migrate`.

### 2. SessionRecorder — accrue AFK alongside minutes

`record()` takes the observation's afk explicitly (cleaner and more honest than reading the
just-mutated `$player->afk` snapshot):

```php
public function record(Player $player, Server $server, Map $map, Mod $mod, ?bool $afk = null): PlayerSession
```

An interval counts as AFK only when we positively observed `$afk === true`; `false` and
`null` (UDP, unknown) both count as active:

```php
$afkDelta = $afk === true ? $interval : 0;
```

- **Extend path:** `afk_minutes = afk_minutes + $afkDelta` (alongside the existing
  `minutes + $interval`).
- **Create path:** `'afk_minutes' => $afkDelta`.

The `= null` default keeps existing callers — including the current `SessionRecorderTest`
cases — compiling and behaving unchanged (they accrue 0 AFK).

`UpdateData::updatePlayers` passes the observation through:

```php
$this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel, $client->afk);
```

### 3. Aggregates

- `PlayerSession::activeMinutes(): int` → `minutes - afk_minutes` (per-row display).
- On `Player`, beside the existing session helpers (`totalSessions`, `averageSessionMinutes`,
  `longestSessionMinutes`):
  - `totalAfkMinutes(): int` → `sessions()->sum('afk_minutes')`
  - `activeMinutes(): int` → `totalSessionMinutes - totalAfkMinutes`, where
    `totalSessionMinutes = sessions()->sum('minutes')`
  - `afkPercent(): int` → `round(afk / sessionMinutes * 100)`, returning 0 when there are no
    session minutes (÷0 guard)

  These are **session-based** — the only place AFK is known — and deliberately separate from
  the history-based `totalHoursOnline()`.

### 4. Display — `resources/views/detail/player.blade.php`

- **New KPI tile "Active time"**, placed among the session-derived tiles:
  - value: `humanize($player->activeMinutes())`
  - hint (shown when `$player->totalSessions() > 0`): `{{ $player->afkPercent() }}% afk`
  - A player with sessions but no feed observations simply shows `0% afk` — we do not
    distinguish "0% idle" from "not measured" (kept simple by design decision).
- **Per-session annotation** in the Recent sessions list `session-item__meta`: append
  `· {{ $humanize($session->afk_minutes) }} afk` with a muted Font Awesome `fa-bed` marker
  shown only when `$session->afk_minutes > 0`. No emoji — matches the page's icon style.

### 5. Tests

- **SessionRecorderTest** (`tests/Feature`): extending an open session with `afk: true` adds
  `afk_minutes`; `afk: false` and `afk: null` add 0; a brand-new session opened with
  `afk: true` starts at `afk_minutes = interval`. (Existing cases keep passing via the
  defaulted param.)
- **Player aggregates** (`tests/Feature`): `totalAfkMinutes` / `activeMinutes` / `afkPercent`
  over seeded sessions, including the empty (no sessions → 0%) case.
- **Player page** (`tests/Feature`): the "Active time" tile and a per-session AFK annotation
  render for a player with an AFK-bearing session.
- Migration applies against the dev DB.

## Risks / edge cases

- **Coverage:** AFK accrues only on feed-observed `afk = true` intervals; FNG/0.7-vanilla
  -exclusive players show ~0% AFK. This is an inherent data limit, surfaced honestly as 0%.
- **`afk_minutes` never exceeds `minutes`:** each scrape adds `interval` to `minutes` and at
  most `interval` to `afk_minutes`, so `activeMinutes()` is always ≥ 0.
- **Signature change is additive:** the new `?bool $afk = null` parameter is optional, so no
  existing caller breaks.
