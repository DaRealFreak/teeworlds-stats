# Map-version diff — UX refinements

Date: 2026-06-19
Status: Design approved, pending spec review
Refines: [2026-06-19-map-version-physics-diff-design.md](2026-06-19-map-version-physics-diff-design.md)
(the original feature, already merged to master)

## Problem

Two pieces of feedback on the shipped physics-diff feature:

1. **The A/B radio selection is clunky.** Picking a base (A) and target (B) from two
   radio columns plus a separate "Compare A → B" button is more ceremony than the task
   needs. The version you are *currently viewing* is the natural anchor — the comparison
   should hang off it with a single per-row button.
2. **A "different dimensions" comparison silently shows nothing on the map.** When two
   versions can't be overlaid (different game dimensions → structural-only), the map
   renders with no highlights and the only signal is the breakdown panel below. A user's
   first instinct is to look at the *map* for the differences, so the map area must say
   why there's nothing to see.

## Decisions (locked during brainstorming)

- **Per-row Compare button replaces the radios.** Remove the A/B radio columns and the
  standalone "Compare A → B" button. Each fetchable version row — except the one being
  viewed — gets a **Compare** button in the actions cell, ordered **before** View and
  Download.
- **The currently-viewed version is the base.** Clicking **Compare** on row *X* navigates
  to `?version=<X>&compare=<currently-viewed version>`. So the version you were viewing
  becomes the **base** (`?compare`), *X* becomes the displayed **target** (`?version`),
  and the diff is overlaid on *X*. This reuses the existing diff direction unchanged
  (`?compare` = base, `?version` = target); only the per-row link wiring is new.
  - **green** = present in *X* but not the base (X added it)
  - **red** = present in the base but not *X* (X removed it)
  - **amber** = changed
- **The compare URL is shareable.** `?version=<X>&compare=<base>` fully encodes the
  comparison, so the page is bookmarkable / survives reload — no extra mechanism.
- **`base` badge.** When a comparison is active (`?compare` resolved), the base row shows
  a `base` badge, mirroring the existing `viewing` badge on the target row, so it's clear
  which version the displayed map is being compared against.
- **Viewer-area notice for the no-overlay cases (both).** A banner directly above the map
  stage, set by `MapCompare` after it computes the diff:
  - **Different dimensions** (`!aligned`): ⚠ *"These versions have different dimensions,
    so the changes can't be shown on the map — they're likely different maps. See the
    breakdown below."*
  - **Identical physics** (aligned, zero changed cells): ℹ *"No physics-layer differences
    to highlight."*
  - **Aligned with changes**: banner hidden (the coloured overlay is the answer).

## Components & changes

### `resources/views/detail/map.blade.php`

- **Remove:** the `A`/`B` `<th>` columns and their radio `<td>` cells; the standalone
  `#mapCompareBtn` "Compare A → B" button block; the `@checked($compare && ...)` A-radio
  precheck.
- **Actions cell** (per `$version` row, `$key = contentKey`, `$isSelected` = the viewed
  version, `$pickable` = availability ≠ Unavailable): render, in order,
  1. **Compare** — shown when `$canCompare && $pickable && !$isSelected` *and* the viewed
     version `$selected` is itself pickable. Links to
     `route('searchMapByName', ['map_name' => $map->name, 'version' => $key, 'compare' => $selected->contentKey()])`.
  2. **View** — existing, shown `@unless($isSelected)`.
  3. **Download** — existing, always.
- **`base` badge:** in the Version cell, add `@if($compare && $compare->is($version))<span
  class="badge bg-warning text-dark">base</span>@endif` alongside the existing
  `current`/`viewing` badges.
- **Notice banner:** add `<div id="mapCompareNotice" class="alert" role="status" hidden></div>`
  immediately above the `map-viewer` block (inside `@if($map->hasDownloadableFile())`), so
  it sits at the top of the map area. Populated by `MapCompare`.

### `resources/assets/js/map/compare.ts`

- `RunOptions` gains `noticeEl: HTMLElement | null`.
- After `diffPhysics`, set the notice:
  - `!diff.aligned` → show the different-dimensions warning (e.g. add classes
    `alert-warning`, set text), `noticeEl.hidden = false`.
  - `diff.aligned` and `totals.added + totals.removed + totals.modified === 0` → show the
    identical-physics info note (`alert-info`), `noticeEl.hidden = false`.
  - otherwise (aligned with changes) → `noticeEl.hidden = true` (and clear classes).
- The overlay call is unchanged: `if (diff.aligned) viewer.setDiffOverlay(diff.cells)`.
- Guard for `noticeEl` being null (defensive), as with `panelEl`.

### Blade inline script (the existing viewer `.then(viewer => …)` wiring)

- Pass `noticeEl: document.getElementById('mapCompareNotice')` into the existing
  `window.MapCompare.run({...})` call. No other change to the wiring.

### Controller (`SearchController::searchMapByName`)

- **Unchanged.** `$compare` (resolved base) and `$canCompare` are still produced and
  passed; the removed precheck was Blade-only. The base/target query-param contract is
  the same.

## Testing

Rework `tests/Feature/MapVersionCompareTest.php` for the new UI:

- **Remove / replace** the now-obsolete assertions that referenced `name="cmpA"`,
  `name="cmpB"`, `id="mapCompareBtn"`, the disabled-radio regex, and the A-radio
  `checked` regression assertion.
- **Per-row Compare link:** on `/map/Linear` (viewing the default/newest version), assert
  a non-viewed pickable row renders a Compare anchor whose href is
  `/map/Linear?version=<row key>&compare=<viewed key>` (or assert both query params are
  present pointing the right way). Assert the **viewed** row has **no** Compare link.
- **No radios:** assert `name="cmpA"`/`name="cmpB"` are absent.
- **Unavailable row:** assert it has no Compare link (not fetchable).
- **`base` badge + notice element:** on a `?version=…&compare=…` request, assert the
  `base` badge renders on the base row and `id="mapCompareNotice"` is present.
- Keep the Task-4 controller view-data tests (`compare` resolves to a pickable version;
  unavailable → null; `canCompare` true/false) — those still hold.

The notice's *runtime* behaviour (which message shows) is DOM/canvas-driven and not
node-unit-testable; it's covered by the feature test (element present) plus a manual
browser verification on `/map/Linear` (different-dimensions pair → warning; identical
pair → info note; aligned-with-changes → no banner).

## Non-goals (YAGNI)

- No change to the diff engine (`diff.ts`), the overlay painting, or the diff direction.
- No multi-select / N-way compare; one base (current) vs one target (clicked row).
- No new routes, migrations, or persistence.
