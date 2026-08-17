# Project Roadmap

A quarterly capacity-planning app for engineering teams. It answers three questions the average roadmap deck can't: **what are we building, who is actually staffed on it, and does the math work out?**

Epics get planned into quarters, engineers get allocated to epics week by week, and everything else — points, spans, over-allocation warnings, roadmap bars — is *derived* from those allocations rather than typed in. Editing someone's weekly capacity updates every downstream total; there are no stale numbers to chase.

## Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 12 (PHP 8.2+), Jetstream for auth + teams |
| Frontend | Livewire 4 with single-file page components, Alpine.js |
| UI kit | [Flux](https://fluxui.dev) **+ Flux Pro** (licensed — see setup below) |
| Styling | Tailwind CSS v4 via Vite |
| Database | Postgres in development, in-memory SQLite for tests |
| Auth extras | Google sign-in via Socialite |
| Email | Resend |
| Tests | Pest 4 |

Local development is served by [Laravel Herd](https://herd.laravel.com) at `http://projectroadmap.test` — no dev server needed beyond `npm run dev` for Vite.

## How the domain fits together

- **Team** — the Jetstream tenant. Everything below belongs to a team.
- **Squad** — a group of engineers with a color. Squad rollups are always computed from members, never entered.
- **Engineer** — a person with a title, optional linked user account (for profile photos), and per-quarter / per-week capacity in points.
- **Epic** — a unit of work with a priority (critical/high/medium/low), an Eisenhower position (importance × urgency), a category, and a status.
- **Status** — a team-defined board column. The app reads only two flags from it: `is_complete` (this column means "done") and `requires_reason` (moving here records a pause with a reason). Everything else is just a name and a color.
- **Allocation** — the atom of planning: one engineer on one epic for one week. Points are *not* stored here; a cell is worth the engineer's capacity that week × share. `CapacityService` derives all totals, spans, and over-allocation flags from these rows.
- **EpicQuarterPlan** — which quarter(s) and squad(s) an epic is planned into.

## Pages

- **Now** — what's in flight this week, what's gone quiet, what's paused and why.
- **Matrix** — Eisenhower grid (importance × urgency) with drag-and-drop.
- **Roadmap** — calendar and timeline views; bars reflect real staffing, not wishful dates.
- **Planning** — the engineer × week allocation grid where staffing actually happens.
- **Epics / Engineers / Squads / Statuses / Categories** — CRUD plus the derived stats for each.

## Getting started

1. **Flux Pro credentials** (required before `composer install` will resolve `livewire/flux-pro`):

   ```sh
   composer config http-basic.composer.fluxui.dev your-email your-flux-license-key
   ```

2. **Install and boot:**

   ```sh
   composer run setup   # composer install, .env, key, migrate, npm install + build
   ```

   Point `.env` at a local Postgres database first (see `.env.example`), then seed:

   ```sh
   php artisan db:seed
   ```

3. **Log in** with a seeded account:
   - `hre0001@outlook.com` / `password`
   - `priya@example.com` / `password` (teammate on the same team)

4. **Run it.** Under Herd the site is just there at `http://projectroadmap.test`; run `npm run dev` for hot reload. Without Herd, `composer run dev` starts server, queue, logs, and Vite together.

## Tests

```sh
php artisan test
```

Pest feature tests against in-memory SQLite. `SmokeTest` renders every page; note that `Allocation.week_start` is deliberately normalized to a bare `Y-m-d` string so date lookups behave identically on SQLite and Postgres.

## Conventions worth knowing

- **Single-file Livewire components.** Pages live in `resources/views/components/` as `⚡name.blade.php` files (yes, the lightning bolt is part of the filename) — PHP class on top, Blade below, routed with `Route::livewire()` in `routes/web.php`.
- **Derive, don't store.** If a number can be computed from allocations and capacity, it is. Resist adding stored totals.
- **Statuses are stated, not inferred.** An epic is where somebody moved it. The app flags drift (e.g. "In progress" but unstaffed for 3 weeks) instead of silently "fixing" it.
- **Drag-and-drop ordering** uses the shared `Sortable` trait + Flux `x-sort` (see `app/Traits/Sortable.php`).
- **Colors belong to data.** Squads and statuses carry their own hex colors; UI tints derive from them (`{color}1f` backgrounds). Avatars stay neutral zinc on purpose.
- **UI work is done with Claude Code** using the `frontend-design` skill, prototyping options as artifacts before building the winner into the app. Keep new UI inside the Flux + zinc design system rather than inventing one-off styles.
- `php artisan pint` before committing PHP.
