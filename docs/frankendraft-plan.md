# Frankendraft mode — implementation plan

Companion to [`frankendraft-spec.md`](./frankendraft-spec.md). Section references (§) point there.

## Status (2026-09-06)

| Phase | State | Notes |
|---|---|---|
| 0 — Environment and branch | **done** | Docker + PHP 8.2 container running; baseline suite was green at 484 core / 14 509 data tests. Still on `main`, no branch cut. |
| 1 — Component data pipeline | **done** | `bin/sync-franken-data.php`, idempotent (`--offline --check` matches). 2 457 components, 326 errata entries, 45 sources, 187 factions. |
| 2 — Domain model | **done** | `app/Franken/**`. Full drafts play end to end for 2–8 players against the real data. |
| 3 — Persistence and concurrency | **done** | `PersistableDraft`, `DraftFactory`, `mutate()` with `flock` / S3 `If-Match`. Verified with a two-process lock test. |
| 4 — HTTP layer | **done** | 4 new endpoints + mode branching on generate/view/get/claim/restore. |
| 5 — Mode selection UI | **done** | Mode switch, source picker (24 contributing sources), `draft_order_mode`, advanced limits panel. |
| 6 — Draft UI | **done** | `templates/franken-draft.php` + `js/franken-draft.js` + styles. Driven in Chrome: claimed a seat, took a pick through the confirm dialog, hand and bag counters updated, no console errors. |
| 7 — Assembly, slice builder, map | **done** | Assembly (grants, swaps, slice) built and server-validated. Map tab reuses `js/generate-map.js` unchanged via an adapter; verified in Chrome — full hyperlane map, per-player slices in speaker order, tile list and TTS map string. |
| 8 — Admin, polish, hardening | **done** | Admin undo (with cross-round bag reconstruction), force-complete for a stuck hand, regenerate-before-first-pick, README section. Force-ready comes free via the ready endpoint with admin credentials. All three admin actions verified in Chrome. |

Quality gates as of this status: **phpstan level 5 clean**, **15 150 tests / 42 947 assertions passing**,
`php-cs-fixer` clean on all new files. Milty mode verified unchanged end to end (generate → view → API).

Known gaps and deviations from the spec, all deliberate:

- `Assembly` does not store `granted` (spec §7 showed it). Grants follow from the hand plus the
  errata, so they are derived on read instead — one source of truth.
- Franken admin **undo** only works while the bag draft is running. Rewinding a draft that has
  reached faction building would leave rounds and ready flags in a state nobody could reason about,
  so it is refused rather than guessed at.
- The Map tab is drawn by swapping a Milty-shaped object into the global `draft` for the duration of
  the `generate_map()` call, and it reads home systems out of hidden `data-faction` /
  `data-homesystem` elements. That is deliberate: it keeps the shared renderer byte-identical to
  upstream so Milty fixes stay mergeable.
- The hyperlane map templates only cover 3–8 players, so a 2-player Franken draft draws no map. The
  Map tab says so.
- Two Discordant Stars red tiles (4224, 4225 — faction-specific anomalies tied to a hero ability,
  not reused elsewhere) have no art in `img/tiles` and could not be sourced. Rather than draft them
  as a bare id, `bin/sync-franken-data.php` leaves any tile it cannot find art for out of the pool
  entirely (`buildTileComponents()` / the `HOMESYSTEM` branch of `buildFactionComponents()`); the
  sync script still reports which ones and why.
- The S3 `If-Match` path is covered by `S3DraftRepositoryTest` against a mocked AWS handler (the
  conditional write, a 412 retried against the newer version, retry exhaustion, and other errors
  propagating). What that cannot prove is that **DigitalOcean Spaces itself** honours `If-Match` on
  `PutObject` and answers a losing write with 412 — that only shows against the live service. The
  retry check accepts both signals (HTTP 412 and a `PreconditionFailed` error code). Local storage,
  the default, is covered for real including a two-process lock test.
- `codex1`, `codex2` and `codex4` contribute no faction components, so they are hidden from the
  source picker even though they are listed in `sources.json`.

Guiding constraints:

1. **Milty mode is untouched.** Every change is additive. Existing draft JSON files keep loading.
2. **No new toolchain.** PHP 8 + vanilla JS + jQuery, no build step, dependencies stay minimal —
   the upstream README asks for exactly this.
3. **Test beside the class.** The repo has a `*Test.php` next to nearly every class; new code
   follows suit and stays green under `composer phpstan` and `composer cs:check`.

---

## Phase 0 — Environment and branch

| # | Task | Files |
|---|---|---|
| 0.1 | Start Docker Desktop, `docker compose up -d --build`, `docker compose exec app composer install` | — |
| 0.2 | Create `.env` from `.env.example`; `STORAGE=local`, set `STORAGE_PATH` | `.env` |
| 0.3 | Confirm the baseline app runs and a Milty draft can be generated and played | — |
| 0.4 | Run the existing suite as a green baseline: `composer paratest` | — |
| 0.5 | Branch `feature/frankendraft` off `master`; leave `origin` pointing upstream so Milty fixes stay mergeable | — |

**Acceptance:** a Milty draft can be created, claimed and drafted locally; the test suite is green.

---

## Phase 1 — Component data pipeline (no UI)

Produces the content the whole mode reads. Independent of everything else, so it goes first.

| # | Task | Files |
|---|---|---|
| 1.1 | `bin/sync-franken-data.php` — fetch the AsyncTI4 JSON trees listed in §5 into a temp dir (Guzzle is already a dependency) | `bin/sync-franken-data.php` |
| 1.2 | Normalise factions → per-category component records; derive ABILITY/TECH/AGENT/COMMANDER/HERO/MECH/FLAGSHIP/PN/STARTINGTECH/STARTINGFLEET/COMMODITIES/BREAKTHROUGH per the §5 table | same |
| 1.3 | Map faction `homeSystem` onto `data/tiles.json` tiles; drop home systems with no tile and report them in the run summary (§4.1) | same |
| 1.4 | Build BLUETILE/REDTILE items straight from `data/tiles.json` (`type` = blue/red) | same |
| 1.5 | Merge the five `franken_errata` files into one `CATEGORY:itemId`-keyed map | same |
| 1.6 | Emit `sources.json` with the official/community flag and a `contributes` flag (a source with zero components is hidden in the UI) | same |
| 1.7 | Apply the excluded-factions list (§3.5) at sync time and record it in the output for auditability | same |
| 1.8 | Print a summary: components per category per source, dropped items with reasons | same |
| 1.9 | Commit the generated `data/franken/*.json`; add a `docs/` note on re-running and on the art-asset licence carve-out | `data/franken/*` |

**Tests:** `data/FrankenDataTest.php` — mirroring the existing `data/FactionDataTest.php`:
every component has a unique id, a category, a known source; every errata target resolves to a real
component; every HOMESYSTEM resolves to a real tile in `tiles.json`; no excluded faction leaks in.

**Acceptance:** `php bin/sync-franken-data.php` is re-runnable and byte-stable, and with
base + PoK + codices enabled there are enough items per category for 8 players.

---

## Phase 2 — Domain model

Pure PHP, no HTTP, no templates. The whole rules engine lives here and is fully unit-testable.

| # | Task | Files |
|---|---|---|
| 2.1 | `DraftCategory` enum (16 cases) with `bagCount()`, `handLimit()`, `finalLimit()`, `label()`, `isActiveFor(FrankenSettings)` | `app/Franken/DraftCategory.php` |
| 2.2 | `ComponentId`, `Component`, `ComponentSource`, `ComponentRepository` (static cache, same shape as `Faction::all()`) | `app/Franken/` |
| 2.3 | `Errata` — loads `errata.json`; `isUndraftable()`, `alwaysAddToPool()`, `swapsFor()`, `grantsFor()`, `textFor()` | `app/Franken/Errata.php` |
| 2.4 | `FrankenSettings` + `validate()`, following `App\Draft\Settings`' pattern and exception style | `app/Franken/FrankenSettings.php` |
| 2.5 | `BuildComponentPools` — sources ∩ errata ∩ exclusions → one shuffled pool per category; throws `InsufficientComponentsException` with per-category detail (§11) | `app/Franken/Commands/BuildComponentPools.php` |
| 2.6 | `BuildBags` — deal `bagCount` items per category into N bags, seeded via the existing `Seed` object so drafts stay reproducible | `app/Franken/Commands/BuildBags.php` |
| 2.7 | `Bag`, `Hand` (per-category counts + `canAccept()`), `FrankenPlayer`, `Round` | `app/Franken/` |
| 2.8 | `FrankenDraft` aggregate: `fromJson()` / `toArray(?PlayerId $viewer, bool $isAdmin)` / `toFileContent()` per §7 and §9 | `app/Franken/FrankenDraft.php` |
| 2.9 | Commands `FrankenPick`, `FrankenUnpick`, `FrankenSetReady`, `AdvanceRound` (barrier + rotation), `UndoFrankenPick` | `app/Franken/Commands/` |
| 2.10 | `Assembly` + `SubmitAssembly`: resolve grants, apply swaps, trim to `finalLimit`, validate the 5-tile slice and home system | `app/Franken/` |

**Tests (one file per class, plus these behaviours):**

* bag generation is deterministic for a given seed; no item appears in two bags;
  bag size is 33 / 31 / 30 depending on active categories.
* pick legality: category at hand limit, item not in your bag, quota spent, wrong round.
* round barrier: all-ready → rotate + increment + reset; a player with no legal item is auto-ready;
  quota clamps to remaining hand capacity.
* the draft reaches `stage = assembly` with every hand exactly full, in 11 rounds at 3/2 picks.
* errata: `undraftable` never deals; `alwaysAddToPool` always deals; grants and swaps resolve.
* redaction: a spectator payload contains no bag items; a player sees only their own bag; admin sees all.

**Acceptance:** a full N-player draft can be played end-to-end in a test with no HTTP layer.

---

## Phase 3 — Persistence and concurrency

The riskiest change; done before any UI so it is exercised by Phase 2's tests.

| # | Task | Files |
|---|---|---|
| 3.1 | `PersistableDraft` interface (`id`, `mode()`, `toFileContent()`); `Draft` and `FrankenDraft` implement it | `app/Draft/`, `app/Franken/` |
| 3.2 | Repository `load()` dispatches on `$data['mode'] ?? 'milty'` | `app/Draft/Repository/*` |
| 3.3 | Add `mutate(string $id, callable $fn)` to `DraftRepository` | `DraftRepository.php` |
| 3.4 | `LocalDraftRepository::mutate()` via `flock(LOCK_EX)` read-modify-write | `LocalDraftRepository.php` |
| 3.5 | `S3DraftRepository::mutate()` via ETag + `If-Match` conditional put, 3 retries on 412 | `S3DraftRepository.php` |
| 3.6 | `FakeDraftRepository` gains `mutate()` so tests keep working | `FakeDraftRepository.php` |
| 3.7 | Route all Franken write commands through `mutate()` | `app/Franken/Commands/*` |

**Tests:** a concurrency test firing two picks against the same draft through `mutate()` and
asserting both land; a legacy-fixture test proving a pre-existing Milty JSON still loads
(reuse `data/historic-test-data/`).

**Acceptance:** no lost updates under simultaneous picks; every existing repository test still green.

---

## Phase 4 — HTTP layer

| # | Task | Files |
|---|---|---|
| 4.1 | Add the §8 routes | `app/routes.php` |
| 4.2 | `HandleGenerateDraftRequest` branches on `mode`; extract `frankenSettingsFromRequest()` | `app/Http/RequestHandlers/HandleGenerateDraftRequest.php` |
| 4.3 | `HandleViewDraftRequest` picks the template by mode | same dir |
| 4.4 | `HandleGetDraftRequest` accepts `player`+`secret` / `admin` and redacts (§9) | same dir |
| 4.5 | New handlers: `HandleFrankenPickRequest`, `HandleFrankenUnpickRequest`, `HandleFrankenReadyRequest`, `HandleFrankenAssemblyRequest` | same dir |
| 4.6 | `HandleUndoRequest` branches on mode | same dir |
| 4.7 | Auth reuses `Secrets::checkPlayerSecret()` / `checkAdminSecret()` unchanged | — |

**Tests:** one `*Test.php` per handler, following `RequestHandlerTestCase`: happy path, wrong
secret → 403, stale round → 400, unknown draft → 404, redaction of another player's bag.

**Acceptance:** a complete draft can be played through HTTP calls alone.

---

## Phase 5 — Mode selection UI

| # | Task | Files |
|---|---|---|
| 5.1 | Mode switch at the top of the form; hidden `mode` input; two mode sections | `templates/generate.php` |
| 5.2 | Franken settings block: source checkboxes grouped Official/Community with select-all, `draft_order_mode`, collapsed advanced limits | same |
| 5.3 | JS: toggle sections, keep shared fields, validate before submit, post to `/api/generate` | `js/main.js` |
| 5.4 | Styling for the mode switch and the source grid | `css/style.scss` |
| 5.5 | FAQ entry explaining Frankendraft and linking the rules summary | `templates/faq.php` |

**Acceptance:** picking Franken and submitting yields a Franken draft URL; picking Milty behaves
exactly as before (verified against the existing generate-request tests).

---

## Phase 6 — Draft UI

| # | Task | Files |
|---|---|---|
| 6.1 | `templates/franken-draft.php` — tabs, popups, loading overlay, claim/session blocks copied from `draft.php` | `templates/franken-draft.php` |
| 6.2 | Component card partial: name, category badge, faction origin, rules text (errata `alternateText` wins), source tag, disabled state + reason | same |
| 6.3 | `js/franken-draft.js`: render bag/hand/player rail, pick + unpick + pass, confirm popup reusing the existing pattern | `js/franken-draft.js` |
| 6.4 | 5 s polling while active, paused on hidden tab, plus the existing `visibilitychange` refresh | same |
| 6.5 | Round-barrier UX: "waiting for Ben, Cleo", bag hand-off animation, "Round 5 — new bag" | same |
| 6.6 | Log tab rendering Franken log entries | same |
| 6.7 | `css/_franken.scss` imported from `style.scss`; rebuild `style.css` | `css/` |

**Acceptance:** three browser sessions can draft simultaneously; the round only advances when all
three are ready; a spectator never sees bag contents.

---

## Phase 7 — Assembly, slice builder, map

| # | Task | Files |
|---|---|---|
| 7.1 | *My Faction* tab: grants list, swap choices, per-category final selection | `templates/franken-draft.php`, `js/franken-draft.js` |
| 7.2 | Slice builder: 5 slots in the existing layout + home-system slot; click/drag from your drafted tiles | same |
| 7.3 | Live slice stats (optimal res/inf, wormholes, legendaries) and the advisory adjacent-anomaly warning, reusing `App\Draft\Slice` server-side for the authoritative values | `app/Franken/Assembly.php` |
| 7.4 | Feed assembled slices + drafted draft order into `js/generate-map.js` unchanged | `js/franken-draft.js` |
| 7.5 | TTS map string and tile-gather list on the Map tab, as in Milty | same |
| 7.6 | Faction summary export (plain text / clipboard) listing the finished franken faction | same |

**Acceptance:** with all players confirmed, the Map tab renders a full map and a valid TTS string.

---

## Phase 8 — Admin, polish, hardening

| # | Task |
|---|---|
| 8.1 | Admin: undo last pick, force-ready a player, force-complete a stuck category (§11) |
| 8.2 | Regenerate before the first pick (mirror `canRegenerate()`) |
| 8.3 | `composer phpstan` clean; `composer cs:fix`; full `composer paratest` green |
| 8.4 | A recorded end-to-end fixture draft under `data/test-drafts/` for regression |
| 8.5 | README section: Franken mode, how to re-run the data sync, the art/licence note |

---

## Sequencing and effort

```
P0 env ──► P1 data ──► P2 domain ──► P3 persistence ──► P4 http ──► P6 draft UI ──► P7 assembly ──► P8 polish
                                                          └──► P5 mode select ──┘
```

| Phase | Size | Notes |
|---|---|---|
| P0 environment | XS | Docker Desktop must be running; PHP/Composer live in the container |
| P1 data pipeline | M | Mostly mapping work; the home-system↔tile mapping is the fiddly part |
| P2 domain | L | The bulk of the value; fully testable without a browser |
| P3 persistence | M | Small diff, high risk — needs its own careful tests |
| P4 http | S | Thin handlers over Phase 2 |
| P5 mode select | S | Form work |
| P6 draft UI | L | Largest frontend piece |
| P7 assembly + map | M | Slice reuse keeps this cheap |
| P8 polish | S | |

Phases 1–4 are shippable as a headless, test-covered rules engine before any UI exists.

---

## Risk register

| Risk | Impact | Mitigation |
|---|---|---|
| Lost updates on simultaneous picks | Corrupt draft state | Phase 3 `mutate()` before any UI; explicit concurrency test |
| Home-system ↔ tile mapping gaps for community sources | Missing HOMESYSTEM items | Drop-and-report at sync time (§4.1); faction's other components stay draftable |
| A category runs dry mid-circulation | A player cannot finish their faction | Pool sufficiency check at generation; in-draft warning; admin force-complete |
| AsyncTI4 renames ids/categories on a later sync | Errata stops resolving | Pin a ref; `FrankenDataTest` fails loudly on unresolvable errata targets |
| Card text is FFG IP; AsyncTI4's licence excludes art | Legal exposure if published | Text-only rendering, no art import, licence note in the repo (§5) |
| Franken JSON blobs are much larger than Milty's | Slower load, S3 cost | Bags are ids only, not embedded components; components resolve client-side from the static `components.json` |
| Upstream miltydraft changes conflict | Painful merges | Additive-only changes; new namespace, new templates, new JS; touch shared files in as few places as possible |

---

## Files touched in the existing codebase

Shared files that must change (kept as small as possible):

* `app/routes.php` — new routes
* `app/Draft/Repository/{DraftRepository,LocalDraftRepository,S3DraftRepository,FakeDraftRepository}.php` — `mutate()` + mode dispatch
* `app/Draft/Draft.php` — implement `PersistableDraft`, write `"mode": "milty"`
* `app/Http/RequestHandlers/{HandleGenerateDraftRequest,HandleViewDraftRequest,HandleGetDraftRequest,HandleUndoRequest}.php` — mode branching
* `templates/generate.php`, `js/main.js` — mode switch
* `templates/faq.php`, `css/style.scss`, `README.md`

Everything else is new: `app/Franken/**`, `bin/sync-franken-data.php`, `data/franken/**`,
`templates/franken-draft.php`, `js/franken-draft.js`, `css/_franken.scss`.
