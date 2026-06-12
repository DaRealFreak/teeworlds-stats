# Track AFK time during sessions

## Problem

The scraper records a player's `afk` flag (idle in-game) as a single boolean snapshot on
the player, refreshed every scrape from the DDNet feed. That tells you whether a player is
AFK *right now*, but nothing about how much of their play time was spent idle. We want to
accumulate **AFK time within each session** and surface it on the player page.

Idle status is reported only by some sources — the DDNet HTTP feed sets a real bool, while
UDP-only sightings (0.6/0.7 browser queries) carry no afk. So rather than diluting AFK across
play time we couldn't measure, we express it as an **exact** percentage of the time the
player spent on servers that *do* report idle status, and label it as such — e.g.
"10% afk (1h) on servers tracking idle status".

## Background

- `App\Service\SessionRecorder::record(Player, Server, Map, Mod)` is called once per player
  per scrape from `UpdateData::updatePlayers`. It extends the player's open session (adding
  `CRONTASK_INTERVAL` to `minutes`) when they are still on the same server, or closes the old
  session and opens a new one.
- `afk` comes only from the DDNet HTTP feed: `DiscoveredClient::$afk` is a real bool for feed
  sightings and `null` for UDP-only sightings.
- The player page (`resources/views/detail/player.blade.php`) already shows session-derived
  KPI tiles (Sessions, with an "avg … · longest …" hint) and a "Recent sessions" list with a
  per-row duration.

## Goals

- Per session, store **both** the AFK minutes and the minutes during which idle status was
  actually reported (the measured denominator).
- Surface AFK on the player page as a standalone, exact stat with its scope made explicit, and
  annotate individual sessions with their AFK portion.

## Non-goals

- No AFK tracking in `player_histories`. AFK lives in `player_sessions` only.
- No backfill: both counters start at 0 for existing rows and accrue from the next scrape on.
- No change to the `afk` boolean snapshot on `players` (the "AFK right now" flag).

## Design

### 1. Schema

A migration adds two columns to `player_sessions`:

```php
$table->unsignedInteger('afk_minutes')->default(0);          // minutes observed idle
$table->unsignedInteger('afk_tracked_minutes')->default(0);  // minutes idle status was reported
```

`afk_minutes ≤ afk_tracked_minutes ≤ minutes` always. `afk_tracked_minutes` is the honest
denominator: minutes spent where the source exposed the afk field at all. `default(0)`
backfills existing rows. Run `php artisan migrate`.

### 2. SessionRecorder — accrue both counters

`record()` takes the observation's afk explicitly (cleaner than reading the just-mutated
`$player->afk` snapshot):

```php
public function record(Player $player, Server $server, Map $map, Mod $mod, ?bool $afk = null): PlayerSession
```

Per scrape:

```php
$afkDelta     = $afk === true ? $interval : 0;  // observed idle
$trackedDelta = $afk !== null ? $interval : 0;  // idle status was reported (true or false)
```

- **Extend path:** `afk_minutes += $afkDelta`, `afk_tracked_minutes += $trackedDelta`
  (alongside the existing `minutes += $interval`).
- **Create path:** `'afk_minutes' => $afkDelta`, `'afk_tracked_minutes' => $trackedDelta`.

The `= null` default keeps existing callers — including the current `SessionRecorderTest`
cases — compiling and behaving unchanged (they accrue 0 on both counters).

`UpdateData::updatePlayers` passes the observation through:

```php
$this->sessionRecorder->record($playerModel, $serverModel, $mapModel, $modModel, $client->afk);
```

### 3. Aggregates

- On `Player`, beside the existing session helpers:
  - `totalAfkMinutes(): int` → `sessions()->sum('afk_minutes')`
  - `afkTrackedMinutes(): int` → `sessions()->sum('afk_tracked_minutes')`
  - `afkPercent(): int` → `round(totalAfkMinutes / afkTrackedMinutes * 100)`, returning 0
    when `afkTrackedMinutes` is 0 (÷0 guard)
  - `hasAfkData(): bool` → `afkTrackedMinutes() > 0` (gates the display)

These are session-based — the only place AFK is known.

### 4. Display — `resources/views/detail/player.blade.php`

- **New KPI tile, shown only when `$player->hasAfkData()`** (so a player never seen on an
  idle-tracking server gets no tile at all — no misleading "0%"):
  - label: `AFK`
  - value: `{{ $player->afkPercent() }}%`
  - hint: `{{ $humanize($player->totalAfkMinutes()) }} on servers tracking idle status`

  i.e. it reads `AFK / 10% / "1h on servers tracking idle status"`. A player who *was* on
  idle-tracking servers but never idle shows `0%` honestly (the tile is present, denominator
  non-zero).
- **Per-session annotation** in the Recent sessions list `session-item__meta`: append
  `· {{ $humanize($session->afk_minutes) }} afk` with a muted Font Awesome `fa-bed` marker,
  shown only when `$session->afk_minutes > 0`. No emoji — matches the page's icon style.

There is **no** general disclaimer copy: the tile's own hint ("on servers tracking idle
status") and its conditional rendering carry the meaning precisely.

### 5. Tests

- **SessionRecorderTest** (`tests/Feature`):
  - extend with `afk: true` → both `afk_minutes` and `afk_tracked_minutes` grow by `interval`;
  - extend with `afk: false` → only `afk_tracked_minutes` grows (idle reported, not idle);
  - extend with `afk: null` → neither grows (idle status not reported);
  - a new session opened with `afk: true` starts at `afk_minutes = afk_tracked_minutes = interval`.
- **Player aggregates** (`tests/Feature`): `afkPercent` is computed over the *tracked*
  denominator (e.g. 30 afk / 60 tracked = 50%, even if total `minutes` is larger);
  `hasAfkData()` is false for a player whose sessions have only untracked minutes.
- **Player page** (`tests/Feature`): the AFK tile (with the "tracking idle status" hint) and a
  per-session AFK annotation render for a player with tracked idle data; the AFK tile is
  **absent** for a player whose sessions were all untracked.
- Migration applies against the dev DB.

## Risks / edge cases

- **Exactness over coverage:** the percentage denominator is the tracked time, so the figure
  is exact for the measured activity; players never seen on idle-tracking servers show no AFK
  stat (tile hidden) rather than a diluted or misleading number.
- **Invariants:** each scrape adds `interval` to `minutes`, at most `interval` to
  `afk_tracked_minutes`, and at most that to `afk_minutes`, so
  `afk_minutes ≤ afk_tracked_minutes ≤ minutes` holds and `afkPercent ≤ 100`.
- **Signature change is additive:** the new `?bool $afk = null` parameter is optional, so no
  existing caller breaks.
