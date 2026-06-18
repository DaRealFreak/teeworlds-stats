# Map-version physics diff (map detail page)

Date: 2026-06-19
Status: Design approved, pending spec review

## Problem

A map *name* is not unique: `map_versions` already records the byte-different files
seen under one name (keyed by `sha256`/`crc`). The detail page (`/map/{name}`) lists
these versions but offers no way to see *how they differ*. For a map like `Linear`,
four versions exist — two within ~100 bytes of each other (almost certainly the same
map re-saved), one ~6× larger (likely a different map reusing the name), and one we
have no file for at all (`acquisition_status = unavailable`).

We want a **changelog-style comparison**: pick two versions and see *what changed in
the playable layout*, shown visually on the map plus a structured breakdown.

## Goal & scope decisions (locked during brainstorming)

- **Goal:** "what changed in the layout" — a changelog, not just a same/different verdict.
- **Diff scope:** **physics layers only** — `game`, `front`, `tele`, `speedup`,
  `switch`, `tune`. No design-layer, quad, image, envelope, or metadata diffing.
- **Dimension mismatch:** per-cell visual diff **only when the two `game` layers share
  the same width/height**. Otherwise: structural-only output ("dimensions changed
  A×B → C×D — likely a different map"). No best-offset alignment.
- **Entry point:** Compare selectors (two radio columns, **A = base** / **B = target**)
  **in the existing Versions panel**. `unavailable` versions cannot be picked.
- **Launch via navigation:** `MapViewer` is built once at page-load around a single
  fixed map and has no in-place map-swap or teardown. So Compare **navigates** to
  `?version=<B>&compare=<A>`: the page reloads showing B through the existing
  version-selection path, then the diff is computed and overlaid. This is the smallest,
  lowest-risk option (decided during plan-writing, overriding the original
  "same page, no reload" wording). The diff overlay still paints onto the existing
  viewer's overlay canvas after the reload.
- **Base render:** the reloaded page shows **version B (the target)** as `?version=`;
  changes are annotated relative to **version A (the base)**, carried as `?compare=`.
- **The comparison pair lives in the URL** (`?version=<B>&compare=<A>`) — a side effect
  of the navigation approach, so a comparison is incidentally shareable / survives the
  back button. (This overrides the earlier "no shareable URL" non-goal.)
- **Two versions at a time** (A vs B). No N-way.
- **No new DB columns, migrations, or persisted state.** Read-time client feature.

## Architecture & data flow

Entirely client-side, reusing the existing TypeScript parser and WebGL viewer. **No
new backend route** — it fetches each version's bytes from the existing
`GET /map/{name}/file/{version?}` endpoint (`MapFileController`), which already resolves
a version by `sha256` *or* `crc`.

```
Versions panel (Blade)
  ─ pick A (base) + B (target) radios → "Compare" button
        │ inline handler navigates to ?version=<B>&compare=<A>
        ▼
Page reloads: SearchController selects B (?version), resolves A (?compare),
viewer inits on B; inline <script> sees the compare key and calls →
  window.MapCompare.run({ baseUrl: A.file, targetUrl: B.file, viewer, panelEl, labels }) (new TS)
        │  fetch both /file/{key}  →  parseMap() ×2  →  MapScene ×2 (base=A, target=B)
        ▼
  diffPhysics(base, target): PhysicsDiff        (new PURE module, unit-tested)
        │
        ├─ aligned  → viewer renders target + viewer.setDiffOverlay(cells)
        │             (changed cells painted on the existing 2D overlay canvas)
        └─ !aligned → no overlay; structural breakdown only
        ▼
  Breakdown panel (built in JS): verdict line + colour legend + per-kind table + file facts
```

### Why client-side

All `.map` parsing already lives in `resources/assets/js/map/` (`parseMap` →
`MapScene`); there is no PHP-side parser. The viewer (`MapViewer`) already fetches a
version's bytes by URL, parses, and renders with a synced 2D overlay canvas. Adding a
diff means parsing a *second* scene and painting an overlay — both natural extensions of
existing client code. A PHP-side diff would require porting the whole parser.

## Components

### `resources/assets/js/map/diff.ts` (new, pure)

`diffPhysics(base: MapScene, target: MapScene): PhysicsDiff`

- No DOM, no WebGL — pure data in / data out, so it is unit-testable in isolation.
- Considers the six physics kinds, **matched by `kind`** (each is a singleton per map
  by `.map` convention, so there is no layer-index ambiguity). Non-physics layers
  (`kind === 'tiles'`, quad layers) are ignored.
- **Alignment gate:** compares the `game` layers' `width`/`height`. If they differ,
  returns `{ aligned: false }` with per-kind dimension info and **no cell list**.
- **Per-cell category** (tile `id === 0` ≡ empty/air):
  - `added` — empty in base, non-empty in target.
  - `removed` — non-empty in base, empty in target.
  - `modified` — non-empty in both but the cells differ.
- **"Differs" is kind-aware**, not just tile `id`:
  - `game`, `front`: `id` + `flags`.
  - `tele`, `tune`: `id` + physics `number`.
  - `switch`: `id` + `number` + `delay` + `flags`.
  - `speedup`: `id` + `force` + `maxSpeed` + `angle`.

  (So a re-numbered teleporter or a changed speedup force counts as `modified` even
  when the tile `id` is unchanged.)
- A physics kind present in only one version is reported structurally as
  "layer added"/"layer removed" — not expanded into a wall of per-cell changes.

Return shape (illustrative):

```ts
interface PhysicsDiff {
  aligned: boolean;               // false ⇒ game dims differ ⇒ no cells
  gameDims: { a: [number, number]; b: [number, number] };
  layers: Array<{
    kind: PhysicsKind;            // game | front | tele | speedup | switch | tune
    inA: boolean;
    inB: boolean;
    added: number;
    removed: number;
    modified: number;
  }>;
  totals: { added: number; removed: number; modified: number };
  cells: Array<{ x: number; y: number; kind: PhysicsKind; category: 'added' | 'removed' | 'modified' }>;
}
```

### `resources/assets/js/map/viewer.ts` (extend)

Add `setDiffOverlay(cells)` (and a matching clear) that stores the changed cells and
paints them on the **existing 2D overlay canvas** (`#mapOverlay`) during the render
loop, reusing the viewer's existing tile→screen transform — the same transform the
entities overlay and legend-jump highlight already use. Diff painting is therefore an
additional overlay pass, not a new coordinate system. No change to the WebGL tile/quad
render path.

Colours (semi-transparent fill + solid outline):

- `added` → green `#3fb950`
- `removed` → red `#f85149` (drawn as a ghost at coordinates that exist only in A)
- `modified` → amber `#d29922`

### Compare orchestrator (new TS, exposed as a `window.*` global)

Follows the existing inline-script seam: the detail view's inline `<script>` already
calls `window.MapViewer.init(...)`. The orchestrator is exposed the same way
(`window.MapCompare`). After the page has reloaded with `?version=<B>&compare=<A>` and
the viewer has finished initialising on **B**, the inline script — seeing a compare key
— calls `window.MapCompare.run({ baseUrl, targetUrl, viewer, panelEl, labels })`, where
`baseUrl` is A's `mapFile` URL, `targetUrl` is B's `mapFile` URL (the same URL the
viewer used). It:

1. Fetches A's and B's bytes from their `mapFile` URLs, `parseMap`s both into scenes
   (base = A, target = B).
2. Runs `diffPhysics(base, target)`.
3. If `aligned`: calls `viewer.setDiffOverlay(diff.cells)` (the viewer already renders B).
4. Renders the structured breakdown panel from the diff result + `labels`.
5. Surfaces a clear in-panel error if a fetch/parse fails.

Note: B is parsed once by the orchestrator and again by `MapViewer` when it renders the
base. Re-parsing a ~187 KB map is negligible; the large (~1 MB) version only ever hits
the structural-only path, so its overlay is empty. If this ever matters, `MapViewer`
can expose its parsed scene later — out of scope now.

### `app/Http/Controllers/SearchController.php` (extend `searchMapByName`)

- Read a new `?compare=` query param (same shape as `?version=`: a `sha256`/`crc`
  content-key) and resolve it to one of `$map->versions` whose file is available.
- Pass the resolved base version (or `null`) to the view so the Blade can build A's
  `mapFile` URL and short label. No new route.

### `resources/views/detail/map.blade.php` (extend)

- Add two radio columns to the Versions table: **A (base)** and **B (target)**, one
  radio each per row. Rows whose version is `unavailable` (no cached file) render the
  radios **disabled**. Shown only when at least two versions have files.
- Add a **Compare** button. A small inline handler reads the selected A/B keys and, when
  both are set and differ, navigates to
  `route('searchMapByName', [name]) + '?version=' + B + '&compare=' + A`.
- Add an initially-hidden **breakdown panel** container (`#mapCompareResult`) below the
  versions panel, populated by the orchestrator.
- In the existing viewer inline `<script>`, after `MapViewer.init(...).then(viewer => …)`
  resolves, if a `compare` base URL was rendered into the page, invoke
  `window.MapCompare.run(...)`.

## Breakdown panel contents

- **Verdict line**, e.g.
  *"Same dimensions — 142 physics cells changed: +30 / −12 / ~100 across game, tele."*
  or *"Different dimensions 300×50 → 1024×80 — likely a different map."*
- **Colour legend** mapping green/red/amber → added/removed/modified, with totals.
- **Per-kind table:** kind, present-in-A, present-in-B, dims, added/removed/modified.
- The panel header names the two versions by their short content-keys
  (`Comparing <A> → <B>`).

**File facts are intentionally not duplicated in the panel.** Size, `sha256`/`crc`,
last seen, and server count for every version already appear in the Versions table
directly above the panel (the table the A/B selectors live in), so the breakdown panel
stays focused on the diff itself rather than repeating that data. (Decision taken during
implementation; recorded here so the spec matches the shipped panel.)

## Testing

- `tests/js/map/diff.test.ts` (vitest) — unit-tests `diffPhysics` against hand-built
  minimal `MapScene` literals (no binary fixtures or WebGL needed):
  - identical scenes → zero changes;
  - one edited game cell → exactly one `modified`;
  - air→solid game cell → one `added`; solid→air → one `removed`;
  - re-numbered `tele` cell (same tile id, different `number`) → one `modified`;
  - a physics kind present in only one scene → reported `inA`/`inB` with no cells;
  - mismatched `game` dims → `aligned: false`, empty `cells`.
- A Feature test asserting the Versions panel renders Compare controls and disables the
  radios for `unavailable` versions.
- `npm run type-check`, `npm run lint`, `npm test`, `npm run build` all green; then
  `php artisan responsecache:clear` and manual verification on `/map/Linear`
  (v2 vs v150 → aligned visual diff; v2 vs v6159 → structural-only).

## Non-goals (YAGNI)

- No diffing of design layers, quads, images, envelopes, or metadata.
- No best-offset / fuzzy alignment.
- No N-way comparison; two versions at a time.
- No new DB columns, migrations, or routes. The only server-side change is reading a new
  `?compare=` query param in the existing `searchMapByName` action and passing the
  resolved base version to the view. No persistence.
- No in-place viewer map-swap / teardown — Compare reloads the page (see "Launch via
  navigation" above).
