# Map Viewer — Phase E (UDP in-game map download) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Acquire `.map` files for non-DDNet maps (vanilla 0.6 / 0.7 and base-protocol mods) by
connecting to a currently-online server hosting the map over UDP and running the in-game map
download — a new `GameServerMapSource` implementing the existing `App\Service\MapSource`
interface, so the viewer/download (Phases A–C) gain coverage with zero rework.

**Architecture:** A new `app/TwStats/Connection/` layer (parallel to `Discovery/`) builds a
minimal connected Teeworlds client: a reliable packet codec (vital/sequence/ack, control
messages), 0.7 Huffman (de)compression, per-protocol connect handshakes (0.6 token + 0.7
control-token), and a `MapDownloadSession` that drives `MAP_CHANGE → REQUEST_MAP_DATA → MAP_DATA`
to completion. `GameServerMapSource` selects a live host for a map from the scraper's server
rows and runs a session. It is appended to the `MapFileStore` source chain after
`DdnetHttpMapSource`. Phase A's `crc` column is finally populated here (validated as hex at the
source — the path-traversal guardrail noted in the spec).

**Tech Stack:** PHP 8.3, the existing `UdpTransport`/`Unpacker`/`VariableInt`/`SevenConnless`
primitives, PHPUnit with **recorded byte fixtures** + `FakeUdpTransport` (no live network in CI).

## Authoritative references (consult per task — do NOT guess the wire format)
- **libtw2 docs** (in `ddnet-web/www/docs/libtw2/`): `packet`, `packet7`, `huffman`, `connection`.
- **DDNet source:** `ddnet/src/engine/shared/network.{h,cpp}`, `network_conn.cpp`,
  `network_client.cpp`, `network_chunk*.cpp`, `compression.cpp` (Huffman), `protocol.h`
  (0.6 NETMSG_*), `protocol7.h` (0.7 NETMSG_* + NET_CTRLMSG_*), and `engine/client/client.cpp`
  (`OnMapDownload`/`ResetMapDownload`/`SendMapRequest`).
- **Existing primitives to reuse:** `app/TwStats/Net/UdpTransport.php` + `SocketUdpTransport`,
  `tests/Support/FakeUdpTransport.php`, `app/TwStats/Protocol/Seven/{SevenConnless,Unpacker,VariableInt}.php`,
  `app/TwStats/Protocol/Six/SixConnless.php`, `App\TwStats\Discovery\FlavorClassifier`.

## Protocol facts (the load-bearing summary; verify byte layouts against the refs per task)
- **Packet header:** 0.6 = 3 bytes (flags<<2 | ack-hi, ack-lo, num_chunks) + payload; flags:
  CONTROL=1, CONNLESS=2(0.6)/8(0.7 via SevenConnless), COMPRESSION=4, RESEND=8(0.6). 0.7 adds a
  token to the header and uses Huffman on the payload (see `SevenConnless` for the framing already
  implemented; the *connected* header differs — see `packet7` doc).
- **Chunk header (per message in a packet):** flags (VITAL, RESEND) + size + (if vital) a 10-bit
  sequence number. Vital messages must be ack'd; the receiver tracks `ack`, the sender resends on
  loss/timeout.
- **Huffman (BOTH 0.6 and 0.7):** the same fixed-table Huffman compresses the payload of *any*
  connected packet (0.6 or 0.7) when the `COMPRESSION` flag (1<<5) is set — `network.cpp`
  Send/UnpackPacket call the shared `ms_Huffman` for both. (Only *connless* packets are never
  compressed.) Table + algorithm: `ddnet/src/engine/shared/huffman.cpp` (Task 1 ported it). The
  0.7 header additionally carries a 4-byte token (header 7 bytes vs 0.6's 3) and remaps the flag
  bits (`PacketFlags_SevenToSix`).
- **Connect handshake:** 0.6 — optional security token (`TKEN`, DDNet-0.6) then
  `CTRLMSG_CONNECT → CTRLMSG_CONNECTACCEPT → (NETMSG_INFO) → CTRLMSG_ACCEPT`; 0.7 — obtain token
  via `CTRLMSG_TOKEN` (already implemented in `SevenConnless::tokenRequest`), then
  `CTRLMSG_CONNECT(token) → CTRLMSG_ACCEPT`, then `NETMSG_INFO`.
- **Map download flow:** after the handshake + `NETMSG_INFO` (+ password if needed), the server
  sends `NETMSG_MAP_CHANGE` (0.6: name, crc, size; 0.7/DDNet: name, sha256, size, num_chunks).
  0.6: the client requests each chunk with `NETMSG_REQUEST_MAP_DATA`; 0.7: the server streams
  `NETMSG_MAP_DATA` proactively in a window. The client reassembles `NETMSG_MAP_DATA` payloads in
  order, verifies the total size and crc (+ sha256 when given), then disconnects (`CTRLMSG_CLOSE`).

## File structure

| File | Responsibility | Tested by |
| --- | --- | --- |
| `app/TwStats/Connection/Huffman.php` | 0.7 Huffman encode/decode | fixtures |
| `app/TwStats/Connection/PacketCodec.php` | packet header + chunk framing + ack, per protocol | fixtures |
| `app/TwStats/Connection/ControlMessages.php` | build/parse CTRL_* (connect/accept/token/close) | fixtures |
| `app/TwStats/Connection/GameConnection.php` | handshake state machine + reliable send/recv | FakeUdpTransport |
| `app/TwStats/Connection/MapDownloadSession.php` | MAP_CHANGE→request→MAP_DATA→reassemble→verify | recorded sequence |
| `app/Service/GameServerMapSource.php` | pick a live host + run a session; validate crc hex | feature (faked) |
| `app/Console/Commands/UpdateData.php` (modify) | populate `crc` from 0.6/0.7 info (capture mapCrc) | UpdateDataTest |
| `app/TwStats/Protocol/Six/SixInfoCodec.php` (modify) | stop discarding mapCrc; surface it | SixInfoCodecTest |
| `app/Service/MapFileStore.php` (modify) | append `GameServerMapSource`; assert hex cache key | MapFileAcquisitionTest |
| `tests/Fixtures/udp/*.bin` | recorded packet bytes (handshake, MAP_CHANGE, MAP_DATA) | — |

---

## Task 1: 0.7 Huffman codec

**Files:** `app/TwStats/Connection/Huffman.php`, `tests/Unit/Connection/HuffmanTest.php`.

- [ ] Port the Teeworlds Huffman table + decode (and encode, needed to build 0.7 connected
  packets) from `ddnet/src/engine/shared/compression.cpp` + the libtw2 `huffman` doc. The
  frequency table is a fixed 256+1 array; build the canonical tree once.
- [ ] **Test (fixtures):** round-trip `decode(encode(x)) === x` for several byte strings; and a
  recorded `(compressed, plaintext)` pair captured from a real 0.7 packet (store the bytes in the
  test as hex). Assert `decode(compressed) === plaintext`.
- [ ] type-check via PHP; `vendor/bin/phpunit --filter Huffman`. Commit.

## Task 2: PacketCodec (header + chunk framing + ack)

**Files:** `app/TwStats/Connection/PacketCodec.php`, `tests/Unit/Connection/PacketCodecTest.php`.

- [ ] Implement, per protocol (0.6 and 0.7): `buildPacket(flags, ack, chunks[], token?)` and
  `parsePacket(bytes, token?)` → `{flags, ack, chunks: [{vital, seq, data}]}`. 0.7 applies Huffman
  to the (non-control) payload via Task 1. Mirror `network.cpp` `CNetBase::SendPacket`/`Unpack`.
- [ ] **Test:** round-trip a packet with two vital chunks (assert sequence numbers + ack survive);
  parse a recorded server packet fixture and assert the chunk boundaries; a control packet has no
  chunks. Commit.

## Task 3: ControlMessages

**Files:** `app/TwStats/Connection/ControlMessages.php`, `tests/Unit/Connection/ControlMessagesTest.php`.

- [ ] Build/parse `CTRLMSG_CONNECT`, `CONNECTACCEPT`, `ACCEPT`, `CLOSE`, `TOKEN`, `KEEPALIVE` for
  0.6 (incl. the DDNet-0.6 security token) and 0.7 (reuse `SevenConnless` token logic). 
- [ ] **Test:** assert each builder's bytes match a recorded fixture; parse a recorded
  `CONNECTACCEPT` and extract the token. Commit.

## Task 4: GameConnection (handshake + reliable layer)

**Files:** `app/TwStats/Connection/GameConnection.php`, `tests/Feature/Connection/GameConnectionTest.php`.

- [ ] A state machine over a `UdpTransport`: `connect(address, flavor)` runs the per-protocol
  handshake to `ONLINE`; `sendVital(msg)` / `flush()` with sequence + resend-on-timeout; `recv()`
  returns parsed messages and auto-acks vital ones + replies to keepalive; `disconnect()`.
  Bounded retries + an overall timeout. Use `FlavorClassifier` to choose 0.6 vs 0.7 behavior.
- [ ] **Test (FakeUdpTransport):** queue a scripted handshake (token→connect→accept) and assert
  the connection reaches ONLINE and emits the right outbound bytes; a dropped accept triggers a
  resend; a `CLOSE` transitions to disconnected. (Model on the existing `UpdateDataTest`
  FakeUdpTransport scripting for the 0.7 source.) Commit.

## Task 5: MapDownloadSession

**Files:** `app/TwStats/Connection/MapDownloadSession.php`, `tests/Feature/Connection/MapDownloadSessionTest.php`.

- [ ] Given an established `GameConnection`: send `NETMSG_INFO` (empty password), read
  `NETMSG_MAP_CHANGE` (name/crc/size[/sha256/num_chunks]); drive the per-protocol chunk loop
  (0.6: send `NETMSG_REQUEST_MAP_DATA` per chunk; 0.7: receive the proactive window, ack); collect
  `NETMSG_MAP_DATA` payloads in order; on completion verify total size + crc (+ sha256 if present);
  return the bytes or a typed failure; then `disconnect()`.
- [ ] **Test (recorded sequence):** feed a `FakeUdpTransport` a recorded MAP_CHANGE + the
  `NETMSG_MAP_DATA` chunks of a small real map (e.g. a recording of downloading gravity.map from a
  local 0.6 server, or a hand-built minimal sequence) and assert the reassembled bytes equal the
  fixture `.map` and the crc matches. A truncated/crc-mismatch sequence → typed failure. Commit.

## Task 6: capture `crc` in the scraper (populate Phase A's column)

**Files:** `app/TwStats/Protocol/Six/SixInfoCodec.php`, `app/TwStats/Protocol/Seven/SevenInfoCodec.php`
(modify), `app/Console/Commands/UpdateData.php` (modify), their tests.

- [ ] `SixInfoCodec` extended-info path currently `array_shift`s `mapCrc`/`mapSize` — surface
  `mapCrc` on `SixInfoPacket` and through `Teeworlds06Source` → `DiscoveredServer.mapCrc`. Same for
  the 0.7 codec where it carries crc. `UpdateData.updateMapIdentity` already coalesces `crc`
  (Phase A) — so once the source provides it, the `maps.crc` column populates with no further
  change. Normalize crc to lower-case 8-hex at the codec (the cache-key safety contract).
- [ ] **Test:** `SixInfoCodecTest` surfaces `mapCrc`; an `UpdateDataTest` asserts a 0.6 sighting
  populates `maps.crc` (and never clobbers a DDNet sha256). Commit.

## Task 7: GameServerMapSource + wire into the store

**Files:** `app/Service/GameServerMapSource.php`, `app/Service/MapFileStore.php` (modify),
`tests/Feature/MapFileAcquisitionTest.php` (modify).

- [ ] `GameServerMapSource implements MapSource`: `fetch(Map): ?string` — find a currently-online
  server hosting `$map->name` (query the scraper's `servers`/`server_histories` for a recent host +
  its address/flavor), open a `GameConnection`, run a `MapDownloadSession`, return verified bytes
  (or null). Respect a short overall timeout; never throw out of `fetch`.
- [ ] **Cache-key guardrail:** in `MapFileStore::ensure`, assert the cache key
  (`sha256 ?? crc`) is hex (`ctype_xdigit`) before building `maps/<key>.map` — closes the
  path-traversal vector now that `crc` is written (spec Phase E note).
- [ ] Append `new GameServerMapSource()` to the default `MapFileStore` sources (after
  `DdnetHttpMapSource`). Broaden `Map::hasDownloadableFile()` to also return true when a `crc` +
  a known online host exist (or keep it sha256-only and let the store try UDP lazily — choose the
  simpler: the store already tries all sources, so make `hasDownloadableFile` return
  `sha256 !== null || crc !== null`).
- [ ] **Test (faked):** inject a `MapFileStore` with a stub `GameServerMapSource` (or a faked
  `GameConnection`/transport) and assert a non-DDNet map (crc only) is downloaded + cached; the
  cache-key hex assertion rejects a non-hex key. Commit.

## Task 8: phase exit gate + verification

- [ ] `vendor/bin/phpunit` (full suite green), `npm run test` / `type-check` / `lint` / `build`
  (unaffected — Phase E is PHP-only) green.
- [ ] **Live smoke (manual, not CI):** against a real online 0.6 server hosting a known map, run a
  one-off `GameServerMapSource::fetch` and confirm the downloaded `.map` parses (feed it to the
  Phase B parser) and renders (Phase C). Document the result; this is the only true end-to-end
  check (CI uses recorded fixtures).
- [ ] Note operational caveats already in the spec (briefly occupies a slot; some servers disable
  download; non-base mods out of scope) — degrade to "unavailable", never an error.

## Self-review notes (spec coverage)
- Spec **Phase E** = `app/TwStats/Connection/` (PacketCodec, GameConnection, Huffman,
  ControlMessages, MapDownloadSession) + `GameServerMapSource` + crc population + the cache-key
  guardrail. Mapped: Tasks 1–7; Task 8 is the gate + live smoke.
- **Verification model differs from A–D:** no live UDP in CI — every protocol unit is tested
  against **recorded byte fixtures** + `FakeUdpTransport`; the only true end-to-end is the manual
  live smoke (Task 8). Capturing the fixtures (real handshake + MAP_DATA bytes) is itself work —
  if no live server is reachable to record from, hand-build minimal sequences from the wire-format
  refs and clearly label them as synthetic.
- **Biggest risks:** 0.6-vs-0.7 packet-header + Huffman differences (Task 2/1), and the reliable
  resend/timeout edge cases (Task 4). Keep 0.6 and 0.7 paths clearly separated; lean on the
  recorded fixtures to lock the wire format.

---

## Task 8 — live smoke RESULTS (recorded; this is where the next effort resumes)

Tasks 1–7 are implemented, unit-tested (256 suite green), and reviewed (4+ real wire bugs
caught & fixed: 0.7 chunk Split=6, `connect07` 513-byte payload, 0.6 security-token suffix on
data packets, peer-`RESEND` re-queue). The connection codecs are exact-byte-verified (Huffman
compat vector). The branch is `feature/map-viewer-phase-e`, **unmerged**.

**Live smoke (real servers from the DDNet master, 2026-06-13):**
- ✅ **`Monster_Rain`** (43.139.181.32:8310, vanilla 0.6.2, 5855 B ≈ 6 chunks): connected ONLINE,
  downloaded the full file, `magic='DATA' version=4`, crc valid. **This proves the entire stack
  end-to-end against a real server** — handshake, packet/chunk codec, Huffman, reliable layer,
  MAP_CHANGE→REQUEST→MAP_DATA reassembly all work for a single-window map.
- ❌ **`fng`** (35.204.119.214:8338, vanilla 0.6.3, 106 KB): connects ONLINE, MAP_CHANGE parses,
  but **no MAP_DATA ever arrives** after `REQUEST_MAP_DATA(0)` — instrumented loop shows
  `recv=0 want=0` indefinitely (NOT a windowing/timeout issue; the windowed fix in `824abb2`
  did not change it).
- ❌ **`Icicle`** (45.141.57.45:8306, DDNet 0.6): handshake times out (DDNet-0.6 token path).

**Diagnosis / where to resume (next focused effort):** the `fng` case (MAP_DATA never delivered
despite a clean handshake + MAP_CHANGE) needs **raw-datagram packet capture** to determine
whether the server's MAP_DATA is arriving-but-dropped by our reliable layer (sequence/ack) vs
never-sent. Prime suspects, in order: (1) `NETMSG_INFO` `GAME_NETVERSION` string mismatch causing
the server to withhold map data (try the exact per-server version, or the legacy vanilla string);
(2) a required intermediate step the server expects before sending map data; (3) a reliable-layer
ack nuance specific to this server. The `Icicle`/DDNet-0.6 handshake is lower priority (DDNet maps
are HTTP-downloadable via Phase A anyway). **Do not merge Phase E until at least the `fng`-class
0.6 case downloads** — the core is proven, but multi-server robustness is unfinished.
