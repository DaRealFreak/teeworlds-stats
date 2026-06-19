# Map-version diff UX refinements — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the A/B radio compare selection with a per-row **Compare** button (the currently-viewed version is the base), and add a viewer-area notice explaining the two cases where no overlay is drawn.

**Architecture:** Pure-logic change in `diff.ts` (a tiny `overlayState` classifier, unit-tested) consumed by `compare.ts` to drive a notice banner; the rest is a Blade rewrite of the versions-table actions cell + the removal of the radio UI. The diff engine, overlay painting, and `?version`(target)/`?compare`(base) contract are unchanged — only the per-row link wiring and the notice are new.

**Tech Stack:** PHP 8.3 / Laravel 13 (Blade), TypeScript (strict, no jQuery), Vite, vitest (node env), PHPUnit (SQLite `:memory:`).

## Global Constraints

- PHP 8.3+, Laravel 13. Runs inside the DDEV web container — bare `php artisan`, `npm`, `vendor/bin/phpunit`.
- Front-end bundle is strict TypeScript, no jQuery. CI runs `npm run type-check` + `npm run lint` — both MUST stay green.
- Rebuild assets after any JS/Blade change (`npm run build`) and run `php artisan responsecache:clear` before manually testing.
- The map detail view reaches the bundle only through `window.*` globals (the inline-script seam) — the Blade inline scripts are plain JS, not part of the TS build, so `type-check`/`lint` do NOT cover them.
- vitest runs in the **node** environment (no DOM/canvas): only pure logic is unit-tested; DOM/canvas behaviour is verified by the feature test (element present) + a manual run.
- Diff direction is unchanged: `?compare` = base, `?version` = target (rendered). Green = added in target vs base; red = removed; amber = changed.
- No new routes, migrations, or persistence. The controller is unchanged.
- Comments explain WHY the current code exists (present tense). Working docs in English.

---

### Task 1: `overlayState` classifier + notice support in `compare.ts`

A pure `overlayState(diff)` in `diff.ts` (unit-tested) classifies a diff into `shown` / `misaligned` / `identical`. `compare.ts` accepts an optional `noticeEl` and uses `overlayState` to populate a banner (and to gate the panel legend, replacing the inline condition). No DOM unit test for the banner (node env); the classifier carries the testable logic.

**Files:**
- Modify: `resources/assets/js/map/diff.ts`
- Modify: `tests/js/map/diff.test.ts`
- Modify: `resources/assets/js/map/compare.ts`

**Interfaces:**
- Consumes: `PhysicsDiff` (from `./diff`).
- Produces (relied on by Task 2's Blade wiring): `MapCompare.run(opts)` now accepts an optional `noticeEl?: HTMLElement | null` on `RunOptions`. New export `overlayState(diff: PhysicsDiff): 'shown' | 'misaligned' | 'identical'`.

- [ ] **Step 1: Write the failing `overlayState` tests**

Append to `tests/js/map/diff.test.ts` (the `layer`/`sceneOf` helpers already exist in this file; reuse them), after the existing `describe` blocks:

```ts
describe('overlayState', () => {
    it('returns "shown" for an aligned diff with at least one changed cell', () => {
        const d = diffPhysics(sceneOf(layer([1, 1, 0, 0], 2, 2)), sceneOf(layer([1, 2, 1, 0], 2, 2)));
        expect(overlayState(d)).toBe('shown');
    });
    it('returns "identical" for an aligned diff with no changed cells', () => {
        const d = diffPhysics(sceneOf(layer([1], 1, 1)), sceneOf(layer([1], 1, 1)));
        expect(overlayState(d)).toBe('identical');
    });
    it('returns "misaligned" when the game dimensions differ', () => {
        const d = diffPhysics(sceneOf(layer([1, 1], 2, 1)), sceneOf(layer([1, 1, 1, 1], 2, 2)));
        expect(overlayState(d)).toBe('misaligned');
    });
});
```

Add `overlayState` to the existing import at the top of the test file:

```ts
import { diffPhysics, formatVerdict, overlayState } from '../../../resources/assets/js/map/diff';
```

(If the existing import line lists only `diffPhysics, formatVerdict`, extend it to include `overlayState`.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npm test -- diff`
Expected: FAIL — `overlayState is not exported` / `not a function`.

- [ ] **Step 3: Add `overlayState` to `diff.ts`**

Append to `resources/assets/js/map/diff.ts` (after `formatVerdict`):

```ts
export type OverlayState = 'shown' | 'misaligned' | 'identical';

/**
 * What the map overlay can express for a diff: the changed cells are `shown`, or there are none to
 * show because the maps are `misaligned` (different game dimensions, so cells can't be matched) or
 * `identical` (aligned but no physics cell changed). The viewer notice keys off this.
 */
export function overlayState(diff: PhysicsDiff): OverlayState {
    if (!diff.aligned) return 'misaligned';
    if (diff.totals.added + diff.totals.removed + diff.totals.modified === 0) return 'identical';
    return 'shown';
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npm test -- diff`
Expected: PASS — the three `overlayState` cases green, plus all pre-existing `diffPhysics`/`formatVerdict` cases.

- [ ] **Step 5: Wire the notice + legend gate into `compare.ts`**

In `resources/assets/js/map/compare.ts`:

(a) Extend the imports on line 2 to include `overlayState`:

```ts
import { diffPhysics, formatVerdict, overlayState, DIFF_COLORS, type PhysicsDiff, type DiffCategory } from './diff';
```

(b) Add `noticeEl` to `RunOptions` (after `panelEl`):

```ts
    panelEl: HTMLElement;
    noticeEl?: HTMLElement | null; // viewer-area banner for the no-overlay cases; optional
```

(c) Replace the legend condition in `renderPanel` to reuse `overlayState` (keeps the "legend only when cells are shown" rule in one place):

```ts
    if (overlayState(diff) === 'shown') {
        root.append(legend(diff));
    }
```

(d) Add an `applyNotice` helper (place it just above the `MapCompare` class):

```ts
/** Populate the viewer-area notice for the cases where no overlay is drawn; hide it when cells show. */
function applyNotice(noticeEl: HTMLElement, diff: PhysicsDiff): void {
    noticeEl.classList.remove('alert-warning', 'alert-info');
    const state = overlayState(diff);
    if (state === 'shown') {
        noticeEl.hidden = true;
        noticeEl.textContent = '';
        return;
    }
    if (state === 'misaligned') {
        noticeEl.classList.add('alert-warning');
        noticeEl.textContent =
            "These versions have different dimensions, so the changes can't be shown on the map — they're likely different maps. See the breakdown below.";
    } else {
        noticeEl.classList.add('alert-info');
        noticeEl.textContent = 'No physics-layer differences to highlight.';
    }
    noticeEl.hidden = false;
}
```

(e) Update `MapCompare.run` to reset + apply the notice (reset on entry, apply after the diff; guard the optional element):

```ts
    static async run(opts: RunOptions): Promise<void> {
        const { panelEl, noticeEl } = opts;
        panelEl.hidden = false;
        if (noticeEl) noticeEl.hidden = true;
        panelEl.replaceChildren(el('p', 'mb-0', 'Comparing versions…'));
        try {
            const [base, target] = await Promise.all([loadMapScene(opts.baseUrl), loadMapScene(opts.targetUrl)]);
            const diff = diffPhysics(base, target);
            if (diff.aligned) opts.viewer.setDiffOverlay(diff.cells);
            renderPanel(panelEl, diff, opts.labels);
            if (noticeEl) applyNotice(noticeEl, diff);
        } catch {
            panelEl.replaceChildren(el('p', 'mb-0 text-danger', 'Could not load one of the versions to compare.'));
        }
    }
```

- [ ] **Step 6: Type-check, lint, build, full vitest**

Run: `npm run type-check && npm run lint && npm test && npm run build`
Expected: no type/lint errors; vitest all green (existing + 3 new `overlayState` cases); build emits a fresh `public/build/assets/app-*.js`.

- [ ] **Step 7: Commit**

```bash
git add resources/assets/js/map/diff.ts tests/js/map/diff.test.ts resources/assets/js/map/compare.ts
git commit -m "feat(map-diff): overlayState classifier + viewer-notice support in MapCompare"
```

---

### Task 2: Per-row Compare button, base badge, notice element + wiring (Blade) + feature tests

Rewrite the versions-table actions cell to a per-row Compare button, remove the radio UI and its nav-handler script, add the `base` badge and the `#mapCompareNotice` banner, and pass `noticeEl` into the existing `MapCompare.run(...)` call. Rework the feature tests for the new UI.

**Files:**
- Modify: `resources/views/detail/map.blade.php`
- Modify: `tests/Feature/MapVersionCompareTest.php`

**Interfaces:**
- Consumes: `MapCompare.run`'s new `noticeEl` option (Task 1); view vars `compare`, `canCompare`, `selected`, `versionAvailability` (already provided by the unchanged controller).

- [ ] **Step 1: Rework the feature tests (write them first; they will fail)**

In `tests/Feature/MapVersionCompareTest.php`: **keep** the two controller view-data tests (`test_compare_query_param_resolves_to_a_pickable_base_version`, `test_compare_ignores_an_unavailable_base_version`) and the `mapWithVersions()` helper unchanged. **Replace** the two HTML tests (`test_versions_panel_renders_compare_radios_and_disables_unavailable` and `test_compare_param_renders_base_file_url_for_the_overlay`) with these:

```php
    public function test_versions_panel_renders_per_row_compare_links_and_no_radios(): void
    {
        $map = Map::create(['name' => 'Linear', 'sha256' => str_repeat('a', 64)]);
        $b = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('b', 64), 'crc' => null]);
        $c = MapVersion::factory()->for($map)->create(['sha256' => str_repeat('c', 64), 'crc' => null]);
        $bad = MapVersion::factory()->for($map)->create([
            'sha256' => str_repeat('d', 64),
            'crc' => null,
            'acquisition_status' => MapVersion::STATUS_UNAVAILABLE,
        ]);

        // View version b explicitly so the "currently-viewed" base is deterministic.
        $html = $this->get('/map/Linear?version=' . $b->sha256)->assertOk()->getContent();

        // the radio UI is gone
        $this->assertStringNotContainsString('name="cmpA"', $html);
        $this->assertStringNotContainsString('name="cmpB"', $html);
        // a non-viewed, fetchable row (c) gets a Compare link with the viewed version (b) as the base
        // (route() renders the query string version-then-compare; Blade escapes & as &amp;)
        $this->assertStringContainsString('version=' . $c->sha256 . '&amp;compare=' . $b->sha256, $html);
        // the viewed row (b) has no Compare link of its own
        $this->assertStringNotContainsString('version=' . $b->sha256 . '&amp;compare=', $html);
        // the unavailable row (d) has no Compare link
        $this->assertStringNotContainsString('version=' . $bad->sha256 . '&amp;compare=', $html);
        // the viewer-area notice element is present
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }

    public function test_compare_param_renders_base_badge_notice_and_overlay_wiring(): void
    {
        [, $base, $target] = $this->mapWithVersions();

        $html = $this->get('/map/Linear?version=' . $target->sha256 . '&compare=' . $base->sha256)
            ->assertOk()->getContent();

        // overlay wiring still points at the base version's file
        $this->assertStringContainsString('window.MapCompare.run', $html);
        $this->assertStringContainsString('/map/Linear/file/' . $base->sha256, $html);
        // the base row is badged, and the notice element exists
        $this->assertStringContainsString('>base</span>', $html);
        $this->assertStringContainsString('id="mapCompareNotice"', $html);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: FAIL — the two new methods fail (`name="cmpA"` still present from the old Blade; no per-row `version=…&amp;compare=…` link; no `>base</span>`; the radio assertions are gone).

- [ ] **Step 3: Remove the radio UI from the versions table**

In `resources/views/detail/map.blade.php`, delete the A/B header columns (the `@if($canCompare)` block with `<th ...>A</th>`/`<th ...>B</th>`, currently lines 63–66) so the `<thead>` row is:

```blade
                                <tr>
                                    <th scope="col">Version</th>
                                    <th scope="col">File</th>
                                    <th scope="col">Size</th>
                                    <th scope="col">Last seen</th>
                                    <th scope="col">Servers</th>
                                    <th scope="col"></th>
                                </tr>
```

Delete the radio `<td>` cells (the `@if($canCompare) ... cmpA ... cmpB ... @endif` block, currently lines 86–90) entirely.

- [ ] **Step 4: Add the base-pickability flag, the `base` badge, and the per-row Compare button**

In the same file, immediately before the `@foreach($map->versions as $version)` line, add a one-time flag for whether the viewed version is itself fetchable (the compare base must be loadable):

```blade
                            @php($selectedPickable = $selected && ($versionAvailability[$selected->id] ?? null) !== \App\Service\MapAvailability::Unavailable)
                            <tbody>
                                @foreach($map->versions as $version)
```

(Place `@php($selectedPickable = …)` right before `<tbody>`/the `@foreach`, whichever is cleaner — it must be in scope inside the loop.)

In the Version `<td>` (currently lines 77–81), add the `base` badge after the `viewing` badge:

```blade
                                        <td>
                                            <code>{{ \Illuminate\Support\Str::substr($key, 0, 12) }}</code>
                                            @if($isCurrent)<span class="badge bg-secondary">current</span>@endif
                                            @if($isSelected)<span class="badge bg-info">viewing</span>@endif
                                            @if($compare && $compare->is($version))<span class="badge bg-warning text-dark">base</span>@endif
                                        </td>
```

Replace the actions `<td>` (currently lines 91–96) with the Compare-first version:

```blade
                                        <td class="text-end">
                                            @if($canCompare && $pickable && !$isSelected && $selectedPickable)
                                                <a href="{{ route('searchMapByName', ['map_name' => $map->name, 'version' => $key, 'compare' => $selected->contentKey()]) }}" class="btn btn-sm btn-outline-info">Compare</a>
                                            @endif
                                            @unless($isSelected)
                                                <a href="{{ route('searchMapByName', ['map_name' => $map->name, 'version' => $key]) }}" class="btn btn-sm btn-outline-secondary">View</a>
                                            @endunless
                                            <a href="{{ route('mapDownload', ['map_name' => $map->name, 'version' => $key]) }}" class="btn btn-sm btn-outline-primary">Download</a>
                                        </td>
```

Note: `$pickable` is already computed in the loop for the (now-removed) radios — it is defined as `@php($pickable = $avail !== \App\Service\MapAvailability::Unavailable)`. That `@php($pickable = …)` line lived inside the deleted `@if($canCompare)` radio block, so **move it up** to just after `@php($avail = $versionAvailability[$version->id])` so it is always defined:

```blade
                                    @php($avail = $versionAvailability[$version->id])
                                    @php($pickable = $avail !== \App\Service\MapAvailability::Unavailable)
```

- [ ] **Step 5: Remove the standalone Compare button + its nav-handler script**

Delete the standalone-button block (currently lines 102–107):

```blade
                    @if($canCompare)
                        <div class="mt-2">
                            <button type="button" id="mapCompareBtn" class="btn btn-sm btn-primary" disabled>Compare A → B</button>
                            <span class="text-muted small">Pick a base (A) and a target (B), then compare what changed.</span>
                        </div>
                    @endif
```

Delete the entire nav-handler `<script>` block in `@section('scripts')` (currently lines 120–146, the `@if($canCompare) <script> (function () { … cmpA … cmpB … })(); </script> @endif`).

- [ ] **Step 6: Add the notice banner above the viewer**

In `@section('content')`, inside `@if($map->hasDownloadableFile())` and immediately before the `<div class="block map-viewer">` (currently line 15), add:

```blade
            @if($map->hasDownloadableFile())
                <div class="alert mb-2" id="mapCompareNotice" role="status" hidden></div>
                <div class="block map-viewer">
```

- [ ] **Step 7: Pass `noticeEl` into the `MapCompare.run` call**

In the viewer inline script's `window.MapCompare.run({ ... })` call (currently the object with `baseUrl`/`targetUrl`/`viewer`/`panelEl`/`labels`), add the notice element. Change the `panelEl` line region to:

```blade
                        viewer: viewer,
                        panelEl: comparePanel,
                        noticeEl: document.getElementById('mapCompareNotice'),
                        labels: {
```

- [ ] **Step 8: Run the feature tests to verify they pass**

Run: `vendor/bin/phpunit --filter MapVersionCompareTest`
Expected: PASS — all four methods (the two kept controller tests + the two reworked HTML tests) green.

- [ ] **Step 9: Full verification**

Run: `npm run type-check && npm run lint && npm test && npm run build && php artisan responsecache:clear && vendor/bin/phpunit`
Expected: type-check/lint clean, vitest green, build emits the bundle, responsecache cleared, full PHP suite green.

- [ ] **Step 10: Commit**

```bash
git add resources/views/detail/map.blade.php tests/Feature/MapVersionCompareTest.php
git commit -m "feat(map-diff): per-row Compare button + base badge + viewer notice"
```

- [ ] **Step 11: Manual verification (controller handles the browser step)**

Not the implementer's job — the controller verifies in a real browser on `/map/Linear`:
- per-row **Compare** button appears before View/Download on non-viewed fetchable rows; the viewed row has none; the unavailable row has none;
- clicking Compare on another version navigates to `?version=<that row>&compare=<current>`, the base row shows the `base` badge, and the diff overlays on the now-displayed target;
- different-dimensions pair → ⚠ banner above the map; identical pair → ℹ banner; aligned-with-changes → no banner.

---

## Self-Review

**Spec coverage:**
- Per-row Compare button before View/Download, current = base, `?version=<row>&compare=<current>` → Task 2 Step 4. ✓
- Compare shown only on fetchable non-viewed rows when the viewed version is fetchable → Task 2 Step 4 (`$canCompare && $pickable && !$isSelected && $selectedPickable`). ✓
- Radios + standalone button + A-precheck removed → Task 2 Steps 3 & 5. ✓
- `base` badge on the base row → Task 2 Step 4. ✓
- Viewer-area notice for both no-overlay cases (different dimensions ⚠ / identical ℹ), hidden when cells show → Task 1 (`applyNotice` + `overlayState`) + Task 2 Steps 6–7. ✓
- Diff direction / overlay / controller unchanged → no task touches `diff.ts` diff logic, the overlay, or the controller (only `overlayState` added). ✓
- Tests reworked (per-row link, no radios, base badge, notice element) → Task 2 Step 1. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step shows complete code and exact commands. ✓

**Type consistency:** `overlayState` defined in Task 1 (diff.ts) is imported in Task 1 (compare.ts, diff.test.ts) with the same name and return union. `RunOptions.noticeEl?` defined in Task 1 is the option Task 2 Step 7 passes. `$pickable`/`$selectedPickable`/`$compare`/`$canCompare`/`$selected` used in Task 2 are all defined in the loop/controller as noted. ✓
