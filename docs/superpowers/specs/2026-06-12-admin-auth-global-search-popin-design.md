# Design: Admin-only auth, global search, and a fixed server-browser popin

- **Date:** 2026-06-12
- **Project:** twstats (Laravel 13)
- **Status:** Approved — ready for implementation planning

## Overview

Three independent, user-requested changes bundled into one spec. Each becomes its
own phase of the implementation plan.

1. **Admin-only authentication** — remove the dead "Facebook login" promise and lock
   the existing email+password login down to admins only.
2. **Global search** — a sticky navbar search box with a live, grouped, cross-entity
   results dropdown, backed by a new unified endpoint that reuses the existing
   `FuzzySearch` ranker.
3. **Server-browser popin fix** — stop the player-roster popin from insta-closing when
   a user opens a tee link in a new tab.

### Findings that shaped the design

- **Facebook is a ghost, not a leftover.** There is no Socialite package, no OAuth
  config, and no Facebook routes/controllers anywhere. The only trace is a
  "future feature" sentence in `resources/views/about.blade.php` (lines 30–39).
  Laravel's `laravel/ui` scaffold (email+password login, registration, password reset)
  already exists and works.
- **Almost nothing is behind auth today.** Only three routes require login, and all
  three render a "Work in progress" placeholder: `POST /tee/edit/{name}`,
  `POST /clan/edit/{name}`, `POST /server/edit/{id}/{name}`
  (gated in `app/Http/Controllers/InformationController.php`).
- **A global-search popup was designed and abandoned half-built.** The navbar contains
  a dormant full-screen `.search-panel` overlay (styled in
  `dark_admin_bootstrapius.scss`) that no JavaScript ever opens — `front.js` even notes
  "search-popup hooks are not used by any view."
- **The search backend already exists.** Per-type AJAX endpoints
  (`/search/{tee,clan,server,mod,map}?term=`) in `AjaxSearchController`, a weighted
  `FuzzySearch::on()` relevance ranker, and a reusable vanilla `autocomplete.js`.
- **The popin's root cause is the `focus` trigger.** The roster popover is initialised
  with `trigger: 'hover focus'` in `serverbrowser.js`. Ctrl/middle-clicking a tee link
  to open a new tab fires `focusout`, and Bootstrap's tooltip close handler hides the
  popover before the new tab opens.

---

## Workstream 1 — Admin-only authentication

**Goal:** delete the dead Facebook promise; restrict the existing email+password login to admins.

### Changes

- **Close registration.** `Auth::routes()` → `Auth::routes(['register' => false])` in
  `routes/web.php`. Remove the **Register** link from the navbar
  (`resources/views/layouts/app.blade.php`). Delete the now-unreachable
  `app/Http/Controllers/Auth/RegisterController.php` and
  `resources/views/auth/register.blade.php`.
- **Keep login, logout, and password reset.** Password reset stays available but
  requires mail to be configured to actually send — this is pre-existing and unchanged.
- **No role column.** With registration closed, every row in `users` is by definition
  an admin. "Admin-only" therefore needs no `is_admin` flag or roles — authenticated ==
  admin. The existing `users` table (id, name, email, password, remember_token,
  timestamps) is untouched.
- **Provision admins via Artisan** rather than a committed seeder, to keep credentials
  out of git. New command `app/Console/Commands/CreateAdmin.php`, signature
  `admin:create`, prompting for name, email, and a hidden password, then creating the
  hashed user (and erroring on a duplicate email).
- **Remove Facebook references** from `resources/views/about.blade.php` (lines 30–39).

### Testing

Per the user's explicit instruction, **no automated tests for the auth changes.**

### Login identifier

Email + password (Laravel default) — the existing login form is unchanged.

---

## Workstream 2 — Global search (sticky navbar box)

**Goal:** a persistent navbar search input with a live, grouped, cross-entity dropdown.

### Backend

New endpoint `GET /search/global?term=` (added to `AjaxSearchController`, or a small
`GlobalSearchController` if that file grows too large). Returns results grouped by type,
each with a server-built URL, reusing `FuzzySearch::on()` with the existing relevance
threshold and ~5 results per type:

```json
{
  "players": [{ "name": "…", "url": "/tee/…" }],
  "clans":   [{ "name": "…", "url": "/clan/…" }],
  "servers": [{ "name": "…", "id": 7, "url": "/server/7/…" }],
  "maps":    [{ "name": "…", "url": "/map/…" }],
  "mods":    [{ "name": "…", "url": "/mod/…" }]
}
```

- URLs are built server-side (the server owns the route patterns and `urlencode`s
  names; servers need both `id` and `name`).
- Results are ordered by relevance within each group.

### Frontend

- **Navbar markup** (`resources/views/layouts/app.blade.php`): a search `<input>` in the
  navbar, collapsing to a search icon that expands on small (`< lg`) screens.
- **New JS module** `resources/assets/js/globalsearch.js`, imported in
  `resources/assets/js/app.js`. Responsibilities:
  - Debounced fetch (~200ms, mirroring `autocomplete.js`) to `/search/global?term=`.
  - Render a grouped dropdown (Players / Clans / Servers / Maps / Mods), each item an
    `<a href>` to the result URL, with a Font Awesome type icon per group.
  - Keyboard nav: arrow up/down across the flattened result list, Enter navigates to
    the highlighted (or top) result, Esc/click-away closes.
  - Global shortcuts `/` and `Ctrl/Cmd+K` focus the input; ignored when focus is already
    in an input/textarea so typing `/` is not hijacked.
- **Remove the dormant `.search-panel` overlay** markup and its SCSS so there are not two
  competing search UIs.

### Security

Player/clan/server names are user-controlled (sourced from Teeworlds) and can contain
HTML. The dropdown renders names via `textContent` / `createElement` — **never
`innerHTML`** — to avoid XSS. A minimum 2-character term is required; an empty/short term
closes the dropdown and issues no fetch.

### v1 non-goal

No per-result canvas tee sprites (they would render one `<canvas>` per row and slow the
dropdown). Font Awesome type icons are used instead. Tee sprites in results can be a
later enhancement.

### Testing

A feature test on `GET /search/global` asserting the grouped JSON shape, the built URLs,
relevance ordering, the per-type limit, and empty/short-term handling. (No JS test
harness exists; the JS is verified manually.)

---

## Workstream 3 — Server-browser popin (click-to-pin)

**Goal:** the player-roster popin must not close when a user opens a tee link in a new tab.

**Files:** `resources/assets/js/serverbrowser.js` (popover init), `resources/views/list/live.blade.php`.

### Change

Switch the Bootstrap popover from `trigger: 'hover focus'` to **`trigger: 'manual'`** with
custom open/close control. This removes Bootstrap's `focusout` close path entirely — the
root cause.

- Click the player-count badge → toggle the roster; opening one closes any other open
  roster (only one open at a time).
- A **document click handler closes the roster only when the click lands outside both the
  badge and the popover content.** Clicking — or ctrl/middle-clicking — a tee link inside
  the roster is "inside", so it never closes it; opening a tee in a new tab leaves the
  roster pinned.
- **Esc** closes the open roster.
- Tee rendering inside the roster is unchanged.

### Testing

No JS test harness exists for this. Verify behaviour with Playwright: open a roster,
ctrl-click a tee link, and assert the roster is still open. Treat this as the regression
check for the fix.

---

## Cross-cutting

- Run `npm run build` after any SCSS/JS change (built assets live in the git-ignored
  `public/build/`).
- Run `vendor/bin/phpunit` for the PHP tests.
- Run the JS linter if one is configured (`npm run lint`).
- Commit per workstream (committing is allowed in this project; do not push unless asked).

## Out of scope

- Roles / multi-tier permissions (registration is closed, so all users are admins).
- Actually implementing the WIP edit pages (they remain "Work in progress" stubs, still
  gated behind auth).
- Social/OAuth login of any kind.
- Per-result tee sprites in the search dropdown.
