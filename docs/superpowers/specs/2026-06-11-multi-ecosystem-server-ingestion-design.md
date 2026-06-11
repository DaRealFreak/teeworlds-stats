# Multi-ecosystem server ingestion (Teeworlds 0.6 / 0.7 / DDNet)

**Status:** Design approved 2026-06-11
**Scope:** `app/TwStats/`, `app/Console/Commands/UpdateData.php`, `app/Models/Server.php`, server-related migrations, the serverbrowser view.

## 1. Context & problem

twstats scrapes Teeworlds-family game servers and tracks players, clans, maps, mods and play time.
The current scraper (`app/TwStats/Controller/MasterServerController` + `GameServerController`) speaks a
single protocol generation — Teeworlds **0.6** UDP, plus DDNet's extended info packets — and discovers
servers via the legacy UDP master protocol (`req2`/`lis2`) pointed at `master1..4.teeworlds.com`.

Three things have diverged from that world, all verified against the reference checkouts at
`/var/www/html/ddnet` (DDNet `master`) and `/var/www/html/teeworlds` (Teeworlds `0.7.5`):

1. **DDNet moved discovery to HTTP.** The UDP master list protocol (`req2`/`lis2`/`cou2`/`siz2`) is gone
   from DDNet entirely; servers register to and are listed from
   `https://master1..4.ddnet.org/ddnet/15/servers.json`. twstats's UDP `MasterServerController` cannot
   see modern DDNet servers at all.
2. **Teeworlds core is now 0.7**, a different protocol generation: connless packets ride a security-token
   handshake, and the server-info payload gained `hostname` + `skill_level` fields and inverted the
   per-client player flag. twstats's current requests are neither framed nor parsed for 0.7.
3. **The "max clients = 16" misdetection** is intrinsic to the 0.6 *vanilla* info packet, which hard-caps
   every count to `VANILLA_MAX_CLIENTS = 16`. The real limit is only available from the DDNet extended
   packet or the HTTP `servers.json` (`max_clients`, now up to 128 since DDNet raised `MAX_CLIENTS` 64→128).

All three ecosystems are active and must be tracked together, without duplicating servers or players when
one physical server is reachable through several protocols/sources (e.g. a DDNet "sixup" server answers
both 0.6 and 0.7 and reports the same player list on each).

## 2. Goals / non-goals

**Goals**
- Discover and track servers from all three live ecosystems (0.6, 0.7, DDNet) in one stats DB.
- One physical server reachable via multiple protocols = **one logical server**; its players are
  **never double-counted**.
- Every server carries a displayable, filterable **type** (0.6 / 0.7 / DDNet / dual-stack).
- Eliminate the "max = 16" misdetection by preferring extended / HTTP limits.
- Keep `player_histories`, `server_histories`, and `SessionRecorder` working downstream unchanged.

**Non-goals**
- No data migration. We only hold today's data; the schema may be dropped and recreated fresh.
- No player identity beyond the existing name(+clan) model — the protocols carry no authenticated accounts.
- No client/connection emulation beyond what info+master querying requires.

## 3. Architecture overview

```
                 ┌─────────────────────┐
 DdnetHttpSource │ servers.json (HTTP)  │──┐
 Teeworlds07Src  │ 0.7 master + info    │──┤   normalized
 Teeworlds06Src  │ 0.6 master + info    │──┤   DiscoveredServer(s)
                 └─────────────────────┘  │   (protocol-tagged addresses
                                          │    + optional info snapshot)
                                          ▼
                              ┌───────────────────────┐
                              │  Merge / dedup engine  │  grouping authority = servers.json addresses[]
                              │  → logical servers      │  players deduped by (name, clan)
                              └───────────┬───────────┘
                                          ▼
                              ┌───────────────────────┐
                              │  Persistence           │  servers + server_addresses,
                              │  (UpdateData)          │  then players / histories / sessions
                              └───────────────────────┘
```

Each source is an isolated adapter behind a common interface; the merge engine and persistence are
source-agnostic. This keeps each protocol's quirks contained and independently testable.

## 4. Source adapters (three native paths)

Common interface (working name `ServerSource`) producing normalized `DiscoveredServer` value objects:
each has a list of protocol-tagged `Address(ip, port, protocol)` and an optional `InfoSnapshot`
(name, map, gametype, version, max_clients, max_players, players[], location).

### 4.1 `DdnetHttpSource`
- GET `https://master{1..4}.ddnet.org/ddnet/15/servers.json` with mirror failover.
- Parse `servers[]`: `addresses[]` (e.g. `tw-0.6+udp://ip:port`, `tw-0.7+udp://ip:port`), `info`
  (`name`, `map.name`, `game_type`, `version`, `max_clients`, `max_players`, `clients[]` with
  `name`/`clan`/`country`/`score`/`is_player`/`afk`), and `location`.
- This is DDNet's native channel **and the grouping authority** (§6): its `addresses[]` array defines
  which endpoints belong to one logical server. Provides real, un-capped limits and full player lists
  with no UDP traffic.

### 4.2 `Teeworlds07Source`
- 0.7 UDP. Implements the connless **token handshake** (see §5.3), then:
  - Discovery: `req2` → `lis2` against `master1..4.teeworlds.com` over the token framing.
  - Info: `gie3` → `inf3` parsed with the **0.7 payload layout** (§5.2).
- Covers vanilla 0.7 servers that register only to the Teeworlds master.

### 4.3 `Teeworlds06Source`
- The existing 0.6 path, refactored out of `GameServerController`: `gie3`/`inf3` vanilla +
  DDNet-extended `iext`/`iex+` (the `"xe"`-header extended request).
- There is no live standalone 0.6 master (teeworlds.com moved to 0.7), so 0.6 endpoints come from the
  `tw-0.6` addresses surfaced by `DdnetHttpSource`. The legacy `req2`/`lis2` 0.6 master query is retained
  only as best-effort fallback and may be removed if it yields nothing.
- Info stays native (vanilla + extended) so 0.6 data is first-hand; **always prefer the extended (`iext`)
  response** for counts to avoid the 16-cap.

## 5. Protocol reference (verified facts we depend on)

### 5.1 Wire tokens (identical across 0.6 / 0.7 / DDNet)
`gie3`/`inf3` (info), `req2`/`lis2` (master list), `cou2`/`siz2` (count), DDNet `iext`/`iex+` (extended).
Same opcodes — but framing and payload differ by generation, so they cannot share one parser.

### 5.2 Info payload layout difference
- **0.6 / DDNet vanilla:** `version, name, map, gametype, flags, numplayers, maxplayers, numclients,
  maxclients, [name, clan, country, score, is_player(=1 for player)]`.
- **0.7:** `version, name, `**`hostname`**`, map, gametype, flags, `**`skill_level`**`, numplayers,
  maxplayers, numclients, maxclients, [name, clan, country, score, player_flag(=0 for player, 1 spec,
  2 bot)]`. Note the two inserted fields and the **inverted** player flag.
- **DDNet extended (`iext`):** vanilla prefix + `map_crc`, `map_size`, and a reserved per-client string;
  carries the real (un-capped) counts.

### 5.3 0.7 connless token handshake (the main new cost)
0.7 connless packets use a 9-byte header: `[flag|version]` + 4-byte **token** + 4-byte **response token**
(`teeworlds/src/engine/shared/network.cpp:146-163`). The server token must first be obtained via a
`NET_CTRLMSG_TOKEN` control exchange (`network_token.cpp`). Practically: **one token round-trip per UDP
target** before any `req2`/`gie3`. The non-blocking chunked socket loop the scraper already uses extends
to this with an extra handshake stage.

### 5.4 The 16-cap
0.6 vanilla info clamps all counts to `VANILLA_MAX_CLIENTS = 16` (`ddnet/src/engine/server/server.cpp`
`CacheServerInfo`). Real limits come from extended info or `servers.json` (`max_clients`, up to 128).

## 6. Data model (fresh schema, no migration)

The schema is dropped and recreated; no preservation of existing rows.

- **`servers`** = the *logical* server. Keeps logical metadata: `name`, `version`, `flavor`, `last_seen`,
  timestamps. The direct `unique(ip, port)` is removed (identity now lives in addresses). Other tables
  (`server_histories`, etc.) continue to FK `server_id` to this logical id — unaffected.
- **`server_addresses`** (new): `id`, `server_id` (FK), `ip`, `port`, `protocol` (6|7), `is_canonical`
  (bool, the preferred display/contact address), timestamps. `unique(ip, port, protocol)`.

## 7. Identity & dedup (mirror DDNet's address-set grouping)

- **Logical server = a set of protocol-tagged addresses.**
- **Grouping authority = `servers.json` `addresses[]`.** Two endpoints are one logical server iff DDNet
  groups them. Endpoints seen only via the native 0.6/0.7 paths are single-address logical servers.
- **Cross-cycle identity:** on each cycle, for every observed address, look up `server_addresses` by
  `(ip, port, protocol)`. Match on **any** known address → reuse that `server_id`; otherwise create a new
  logical server. New addresses for an existing server are added; addresses can change over time without
  splitting the server.
- **Per-cycle merge pipeline:**
  1. Build the logical-server set from `servers.json` groupings.
  2. Fold in 0.7-master / native-discovered addresses, attaching to an existing logical server when an
     address matches, else creating a new single-address one.
  3. For each logical server, gather player observations from **every** source/address that reported it
     this cycle and **dedup by (name, clan)** → the server's playerbase. This is the anti-duplication
     step: a sixup server reports the same humans on its 0.6 and 0.7 info.

## 8. Info resolution policy

Per logical server per cycle there may be several info snapshots (servers.json + native 0.6 + native 0.7):

- **Players:** union across the server's sources, deduped by `(name, clan)`.
- **Metadata** (map, gametype, version, max_clients/max_players): prefer highest fidelity in order
  **`servers.json` → native 0.7 → 0.6-extended → 0.6-vanilla** (vanilla last — it is the 16-capped one).
- **Skip redundant polling (approved):** servers already fully described by `servers.json` are **not**
  re-UDP-polled on their DDNet/0.6 addresses (large network-load saving). Only 0.7-only and 0.6-only
  servers are natively polled. (The rejected alternative re-polls every endpoint for protocol-pure player
  lists at the cost of a token handshake + RTT per server across thousands of servers.)

## 9. Classification / display

- Per address: `protocol` (6 / 7).
- Per logical server: derived `flavor` — `ddnet` when the version string carries a DDNet build
  (e.g. `0.6.4, 19.1`), else `vanilla_06` / `vanilla_07` — plus the protocols set from its addresses
  (e.g. {6,7} → "dual-stack").
- Surface a "server type" badge and filter in the existing serverbrowser view.

## 10. Orchestration changes

`UpdateData::handle()` becomes: run the 3 sources → merge into logical servers → resolve info →
persist (`servers` + `server_addresses` upsert keyed by logical id) → `updatePlayers` over the deduped
playerbase → histories / `SessionRecorder` unchanged.

Refactor map:
- `MasterServerController` → `App\TwStats\Discovery\{DdnetHttpSource, Teeworlds07Source, Teeworlds06Source}`.
- `GameServerController` → `App\TwStats\Protocol\{SixInfoCodec, SevenInfoCodec}` (+ extended in Six).
- New `App\TwStats\Net\SevenTokenHandshake`.
- New merge engine, e.g. `App\TwStats\Discovery\ServerMerger`.

## 11. Testing

- **Unit (golden packets):** each codec parses a captured reference packet — 0.6 vanilla, 0.6 extended,
  0.7 (with `hostname`/`skill_level` + inverted flag), and a `servers.json` fixture. The 0.7 token
  handshake is unit-tested against a recorded exchange.
- **Feature (merge/dedup):** a sixup server present in `servers.json` (0.6+0.7) **and** independently via
  the 0.7 master must persist as **one** logical server with a deduped playerbase.
- **Regression:** per project convention, a regression test asserting the 16-cap fix (a 64/128-slot server
  reports its real limit, not 16).
- Tests use `RefreshDatabase` + factories; `Map`/`Mod` via `::create(['name' => ...])`; keep
  `CRONTASK_INTERVAL` set.

## 12. Phasing (drives the implementation plan)

1. **Data model** — `servers` logical + `server_addresses`, fresh schema (drop & recreate).
2. **`DdnetHttpSource`** + info-resolution + classification (biggest coverage win, no new UDP).
3. **Merge/dedup engine** + serverbrowser "type" display.
4. **`Teeworlds07Source`** — token handshake + 0.7 codec.
5. **0.6 refactor** into `Teeworlds06Source`; retire the dead 0.6 master query.

## 13. Risks & verification items

- **Master liveness:** confirm `master1..4.teeworlds.com` still answer the 0.7 master protocol, and that
  the DDNet `servers.json` mirrors are reachable from the DDEV container. (Network-dependent; verify at
  implementation time.)
- **0.7 token RTT scaling:** a handshake per server adds an RTT before info; validate the chunked
  non-blocking loop keeps total cycle time within the 10-minute schedule for the full server population.
- **`servers.json` size/shape drift:** treat the feed defensively (validate types, tolerate missing
  fields) — it is an external contract.
- **Flavor heuristic:** `ddnet` detection keys off the version string format; revisit if DDNet changes
  its version reporting.
