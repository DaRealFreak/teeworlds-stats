# JS → TypeScript + lint/format pipeline

## Goal

Convert the front-end module set under `resources/assets/js/` from vanilla ES6 to
TypeScript, and add a linting/formatting pipeline (type-check + ESLint + Prettier) that
runs both locally and in CI. jQuery was already fully removed, so the modules are clean
vanilla DOM/Chart.js code — no jQuery typings are involved.

## Constraints

- **The `window.*` global seam is load-bearing.** Four Blade views ship inline
  `<script>` blocks that are *not* TypeScript and will stay plain JS:
  - `general.blade.php` — uses `new Chart(...)` and `humanizeDuration(...)`
  - `detail/server.blade.php`, `detail/clan.blade.php`, `detail/player.blade.php` —
    use `ChartHelper.lineChart/pieChart/radarChart/...`
  These consume `window.Chart`, `window.ChartHelper`, `window.humanizeDuration`, and
  `window.blade` (the `blade(_) => _` passthrough in `laravel.js` that lets `{{ }}`
  interpolation sit inside otherwise-valid JS). The conversion MUST keep setting those
  globals, with identical timing — `charthelper` defines `window.ChartHelper`
  **synchronously at module load** specifically to avoid racing the inline view scripts
  (documented in its header comment); that must be preserved.
- Behavior is unchanged. This is a typing/tooling migration, not a refactor of logic.
- `tsc` is type-check only (`noEmit`); Vite/esbuild remains the bundler.

## Decisions (agreed)

- **TS strictness:** full strict — `strict: true`, `noUncheckedIndexedAccess: true`,
  `noImplicitOverride: true`.
- **Lint stack:** ESLint flat config + `typescript-eslint` (recommended / syntactic
  rules) + Prettier, wired via `eslint-config-prettier`. Type-checked lint rules are
  intentionally omitted — `tsc --noEmit` already gives full type coverage in CI.
- **Enforcement:** CI (extend `theme.yml`) + local npm scripts.
- **Refactor scope:** idiomatic ES modules — drop the now-redundant IIFE wrappers
  (TS modules are already scoped + strict-mode).
- **Commit split:** (1) convert + type + tooling config, files keeping their
  hand-written formatting; (2) `prettier --write` reformat as a separate commit so the
  logic diff stays reviewable. (3) optionally the CLAUDE.md/doc touch-ups.

## Components / changes

### TypeScript config
- `tsconfig.json` (new): strict flags above; `target: ES2022`,
  `module`/`moduleResolution: bundler`, `lib: [ES2022, DOM, DOM.Iterable]`,
  `skipLibCheck: true`, `noEmit: true`, include `resources/assets/js`.
- `resources/assets/js/global.d.ts` (new): the documented seam — augments `Window` with
  `Chart`, `ChartHelper`, `humanizeDuration`, `blade`.
- devDeps: `typescript`, `@types/humanize-duration`. (chart.js + bootstrap ship their
  own types; flag-icons / font-awesome are CSS-only.)

### Module conversion (`.js → .ts`, 9 files)
`app`, `bootstrap`, `laravel`, `charthelper`, `autocomplete`, `front`, `globalsearch`,
`serverbrowser`, `tee`. Drop IIFE wrappers, add DOM types + the null-guards strict mode
requires. `charthelper.ts` imports `humanizeDuration` directly (mirroring its existing
direct `Chart` import) and keeps assigning `window.ChartHelper` at module load.
`app.ts` keeps `window.Chart` / `window.humanizeDuration`. Update `vite.config.js` input
(`app.js → app.ts`) and the `@vite(...)` directive in the layout. Delete `.jshintrc`.

### Lint + format
- `eslint.config.js` (flat): `typescript-eslint` recommended + `eslint-config-prettier`.
- `.prettierrc`: 4-space indent, single quotes, semicolons, `printWidth: 120` (chosen to
  match existing style and minimize reflow). `.prettierignore`: `public/build`,
  `node_modules`, `vendor`.
- devDeps: `eslint`, `typescript-eslint`, `prettier`, `eslint-config-prettier`,
  `globals`.
- npm scripts: `type-check` (`tsc --noEmit`), `lint` (`eslint .`), `lint:fix`,
  `format` (`prettier --write`).

### CI
Extend `.github/workflows/theme.yml`: `npm ci → npm run type-check → npm run lint →
npm run build`.

### Docs
Update `CLAUDE.md`: drop the stale "jQuery + jQuery UI" line; note TS + ESLint/Prettier
+ the `type-check`/`lint` commands.

## Verification

- `npm run type-check` clean, `npm run lint` clean, `npm run build` succeeds.
- Manually confirm charts still render on `/general` (inline `new Chart` +
  `humanizeDuration`) and a detail page (`ChartHelper.*`) — the global seam is the only
  real regression risk.

## Out of scope

- The four inline Blade `<script>` blocks stay plain JS (not converted, not linted).
- No logic refactors beyond what strict typing forces.
