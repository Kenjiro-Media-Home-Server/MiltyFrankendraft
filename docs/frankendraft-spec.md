# Frankendraft mode — specification

Status: **draft v1**, not yet implemented.
Scope: add a second, self-contained draft mode ("Franken") next to the existing Milty draft.
The Milty mode's behaviour, URLs, stored drafts and JSON format must remain **unchanged**.

---

## 1. What Frankendraft is

Frankendraft is a fan-made TI4 variant: instead of picking a published faction, every faction is
broken into its individual parts, the parts are shuffled into per-player bags, and each player
drafts a hand of parts that is then assembled into a custom ("franken") faction.

There is no official rules PDF. The de-facto reference implementation is the **AsyncTI4 Discord
bot** (`AsyncTI4/TI4_map_generator_bot`, Unlicense/public-domain for code and data, *art assets
explicitly excluded*). This spec follows its `FrankenDraft` variant (the "standard" Franken) and
reuses its component database and its Franken errata data verbatim.

Reference classes in that repo:
`src/main/java/ti4/draft/{BagDraft,FrankenDraft,DraftCategory,DraftItem}.java`,
data under `src/main/resources/data/`.

---

## 2. Mode selection

The landing page (`/`) keeps rendering `templates/generate.php`, but the form gains a **mode
switch** at the very top:

```
[ Milty Draft ]  [ Franken Draft ]     <- two large toggle buttons, Milty preselected
```

* The switch sets a hidden input `mode` (`milty` | `franken`).
* Shared settings stay visible in both modes: game name, player names/count, seed, preset draft
  order.
* Mode-specific settings live in two sibling `<section>`s; JS shows exactly one.
* Both modes post to the same endpoint `POST /api/generate`.
  `HandleGenerateDraftRequest` branches on `mode`.
* A generated draft stores its mode. `GET /d/{id}` branches on the stored mode and renders either
  `templates/draft.php` (Milty, unchanged) or `templates/franken-draft.php`.
* Drafts saved before this feature have no `mode` key and are read as `milty`.

### 2.1 Franken settings block

| Setting | Type | Default | Notes |
|---|---|---|---|
| `mode` | hidden | `milty` | |
| `players[]`, `num_players` | shared | — | 2–8 |
| `game_name`, `seed`, `preset_draft_order` | shared | — | |
| `sources[]` | checkbox group | base + pok + codex1-4 | grouped "Official" / "Community", select-all per group |
| `draft_order_mode` | radio | `drafted` | `drafted` = draft order is a drafted category; `preset` = seat order fixed at generation, category disabled |
| `include_breakthroughs` | derived | — | on iff a Thunder's Edge-era source is enabled |
| bag/keep numbers | advanced, collapsed | standard Franken preset | rendered read-only in v1; the data model already carries them per category |

Alliance/team mode is **not** supported in Franken v1.

---

## 3. Rules implemented

### 3.1 Draft categories

16 categories, named exactly as AsyncTI4 names them, because the errata data references them:

`ABILITY`, `TECH` (faction technology), `AGENT`, `COMMANDER`, `HERO`, `MECH`, `FLAGSHIP`, `PN`
(promissory note), `HOMESYSTEM`, `STARTINGTECH`, `STARTINGFLEET`, `COMMODITIES`, `BLUETILE`,
`REDTILE`, `DRAFTORDER`, `BREAKTHROUGH`.

A category is **active** for a given draft only if the enabled sources yield enough items for it
(§11). `BREAKTHROUGH` is inactive without Thunder's Edge content. `DRAFTORDER` is inactive when
`draft_order_mode = preset`.

### 3.2 Item identity

Every draftable item is addressed as **`CATEGORY:itemId`** — e.g. `ABILITY:mitosis`,
`MECH:muaat_mech`, `BLUETILE:26`, `DRAFTORDER:3`. This is exactly the format the AsyncTI4 errata
uses in `optionalSwaps` / `additionalComponents`, so errata maps 1:1 with no translation layer.

### 3.3 Bag composition and keep limits (standard Franken)

Each player receives one bag. Per bag, per category:

| Category | In bag | Max in hand |
|---|---:|---:|
| ABILITY | 3 | 3 |
| BLUETILE | 3 | 3 |
| TECH | 2 | 2 |
| REDTILE | 2 | 2 |
| AGENT, COMMANDER, HERO, MECH, FLAGSHIP, PN, HOMESYSTEM, STARTINGTECH, STARTINGFLEET, COMMODITIES, BREAKTHROUGH | 2 each | 1 each |
| DRAFTORDER | 1 | 1 |

* Bag size = **33** items (31 without `BREAKTHROUGH`, 30 without `DRAFTORDER` as well).
* Full hand = **22** items (20 / 19 respectively).
* Items are drawn without replacement from one shuffled pool per category, so no item exists twice
  across all bags.
* `DRAFTORDER` contributes exactly one item per bag = exactly N positions for N players, which is
  what makes seat order self-balancing.

### 3.4 Draft procedure

Synchronous, round-based bag passing:

1. **Round 1**: every player may take **3** items from their bag. Rounds 2+: **2** items.
2. A player's round ends when they have taken their quota, **or** their hand is full, **or** no
   item left in their bag is legally draftable for them (every category it offers is at their hand
   limit) — in the latter two cases they are marked ready automatically. A player may also press
   *Pass* to end their round early.
3. Picks within a round are **simultaneous** — players do not wait for each other.
4. When **all** players are ready, the round ends: bags rotate one seat (fixed direction, stored
   player order), the round counter increments, ready flags reset, and the next round opens.
5. A player may take fewer than the quota if fewer legal items remain: the quota for a round is
   `min(round_quota, remaining hand capacity)`.
6. The bag draft ends when **every** player's hand is full. With the standard numbers that is after
   11 rounds (3 + 2×9 = 21, plus a final single pick).
7. Within their own current round a player may **un-pick** an item they just took (it goes back into
   the bag) as long as they have not been marked ready. After the round barrier, only an admin undo
   can reverse a pick.

### 3.5 Errata layer

`data/franken/errata.json`, merged from AsyncTI4's
`franken_errata/{pok,ds,thundersedge,theodisi,keleres}.json` (~326 entries). Each entry targets one
`CATEGORY:itemId` and carries any of:

| Field | Effect |
|---|---|
| `undraftable: true` | The item never enters a pool. (48 entries in PoK alone: Mitosis, Zeal, Quantum Entanglement, the Ghost flagship, the Cabal hero, …) |
| `alwaysAddToPool: true` | Franken-only replacement card, force-added to its pool even though the base version is undraftable (`edict_y`, `imperia_y`, `mahactcommander_y`, `mahact_mech_y`). |
| `additionalComponents: [ID]` | Holding this item also grants those items for free at assembly. Example: `TECH:lw2` grants `ABILITY:mitosis`. |
| `optionalSwaps: [ID]` | At assembly the player *may* exchange this item for one of the listed items. Example: `ABILITY:star_forge` ↔ `MECH:muaat_mech` / `BREAKTHROUGH:muaatbt`. |
| `alternateText` | Franken-specific rules text that replaces the printed text when the card is displayed (Imperia, Riftmeld, the Cabal hero, Muaat's starting fleet, …). |

Also honoured: AsyncTI4's **excluded factions** list — `lazax`, `admins`, `franken`, `keleresm`,
`keleresx`, `miltymod`, `qulane`, `neutral`, `kaltrim`, `xin`, `sarcosa`, `obsidian` — whose
components never enter any pool.

The errata engine is data-driven; no rule is hardcoded in PHP.

### 3.6 Assembly stage

After the bag draft, each player independently completes their faction. This is the "pick your
concrete playing material out of what you drafted" step:

1. **Grants** — `additionalComponents` from every held item are listed as automatically gained.
2. **Swaps** — every held item with `optionalSwaps` shows a choice: keep it, or take one of the
   offered alternatives. Choices are recorded, not destructive.
3. **Trim** — where the hand holds more of a category than the final faction may use, the player
   selects which to keep. With the standard numbers the hand limit equals the final limit, so this
   step is a no-op; it exists because the config carries separate `bag`/`hand`/`final` numbers, and
   the classic "draft 4 abilities, discard 1" phrasing and the powered/overdraft variants need it.
4. **Slice assembly** — see §3.7.
5. The player confirms. The draft is `done` when all players have confirmed.

### 3.7 Slice assembly and the map

A drafted hand contains 3 blue + 2 red tiles = **exactly 5 tiles**, which is exactly the shape of a
Milty slice (`App\Draft\Slice` requires 5 tiles). So:

* The assembly UI shows the existing 5-position slice layout (`img/slice-layout.png`, positions 0–4
  as documented in `Slice::tileArrangementIsValid()`), plus the home-system position.
* The player clicks/drags their 5 drafted tiles into the 5 positions; their drafted `HOMESYSTEM`
  sits at H.
* `Slice` is reused unchanged to compute optimal resources/influence, wormholes and legendaries, and
  to **warn** (not block) on adjacent anomalies — in Franken the player chooses the layout, so this
  is advisory rather than a generation constraint.
* Once every player has a confirmed slice and a `DRAFTORDER` position, the existing map tab,
  `js/generate-map.js` and the TTS map string work with no changes beyond being fed the assembled
  slices instead of generated ones.

---

## 4. Content sources

Franken mode does **not** use the 5-value `App\TwilightImperium\Edition` enum (that stays Milty's).
It uses AsyncTI4's `source` strings via a new `App\Franken\ComponentSource` value object, seeded
from `data/franken/sources.json` (33 sources today).

* **Official**: `base`, `pok`, `thunders_edge`, `twilights_fall`, `codex1`–`codex4`.
* **Community**: `ds` (Discordant Stars), `uncharted_space`, `absol`, `monuments`, `blue_reverie`,
  `balacasi`, `franken`, `theodisi` (Lost Legacies), `gamma`, `black_spectrum`, `expanded_fracture`,
  `erwans_gambit`, `muaat_mania`, `action_deck_2`, `lost_star_charts_of_ixth`, `promises_promises`,
  `viability_patch`, `eronous`, `luminous`, `fowplus`, `spice_thyme`, `untangled_space`,
  `addiction`, `combosloth`, `newdawn`.

Only sources that actually contribute faction components appear in the UI; the rest (action-card or
objective packs) are filtered out by the sync script.

### 4.1 Known content limitation — tiles

Card categories (abilities, leaders, tech, PNs, flagships, mechs, commodities, starting tech/fleet,
breakthroughs) are pure text and support **every** source.

`HOMESYSTEM`, `BLUETILE` and `REDTILE` are limited to what `data/tiles.json` already contains — 188
tiles (blue 70 / green 66 / red 34 / hyperlane 18) covering BaseGame, PoK, Thunder's Edge,
Discordant Stars and DS+. A home system whose tile is not in `tiles.json`, or whose tile has no
matching file in `img/tiles/`, is dropped from the `HOMESYSTEM` pool while that faction's other
components stay draftable; the same applies to a `BLUETILE`/`REDTILE` with no art (currently two
Discordant Stars anomaly tiles, 4224 and 4225 — see `docs/frankendraft-plan.md`). Extending
`tiles.json` (and `img/tiles/`) is a separate, additive task.

---

## 5. Data pipeline

New CLI script, run manually, output committed, **no network access at runtime**:

```
php bin/sync-franken-data.php [--ref=master]
```

It fetches from `AsyncTI4/TI4_map_generator_bot`: `data/factions/*.json`, `abilities/*.json`,
`leaders/*.json`, `technologies/*.json`, `promissory_notes/*.json`, `units/*.json`,
`breakthroughs/*.json`, `franken_errata/*.json`, `sources/sources.json` — and writes:

| File | Contents |
|---|---|
| `data/franken/components.json` | Every draftable item, flat: `{id, category, name, faction, faction_name, source, text, meta}` |
| `data/franken/errata.json` | Merged errata entries keyed by `CATEGORY:itemId` |
| `data/franken/sources.json` | `{source, name, canal: official/community, contributes: bool}` |
| `data/franken/factions.json` | Faction → component index (alias, name, source, homeSystem tile, homePlanets, commodities, startingFleet, startingTech, factionTech, abilities, leaders, promissoryNotes, units) |

Category derivation from AsyncTI4 faction records:

| Category | Derived from |
|---|---|
| ABILITY | `factions[].abilities[]` → `abilities/*.json` |
| TECH | `factions[].factionTech[]` → `technologies/*.json` |
| AGENT / COMMANDER / HERO | `factions[].leaders[]` → `leaders/*.json`, split by leader type |
| MECH / FLAGSHIP | `factions[].units[]` → `units/*.json`, filtered by unit type |
| PN | `factions[].promissoryNotes[]` → `promissory_notes/*.json` |
| STARTINGTECH | `factions[].startingTech[]` — one item per faction (the whole set) |
| STARTINGFLEET | `factions[].startingFleet` — one item per faction |
| COMMODITIES | `factions[].commodities` — one item per faction |
| HOMESYSTEM | `factions[].homeSystem` mapped onto a `data/tiles.json` tile (§4.1) |
| BREAKTHROUGH | `breakthroughs/*.json` by faction |
| BLUETILE / REDTILE | `data/tiles.json`, `type` = blue / red, filtered by enabled sources |
| DRAFTORDER | generated: positions `1..N` |

**Licensing note, to be kept in the repo**: AsyncTI4's Unlicense covers software and data but
explicitly **not art assets**. Franken component cards are therefore rendered as **text**, reusing
only the faction icons already present in `img/factions/`. No card art is imported.

---

## 6. Domain model

New namespace `App\Franken`, parallel to `App\Draft`, following the existing conventions (readonly
value objects, one command per file, a `*Test.php` beside every class).

```
app/Franken/
  FrankenDraft.php            aggregate root (id, stage, round, players, bags, log, settings, secrets)
  FrankenSettings.php         sources, player names, seed, draft_order_mode, per-category limits
  FrankenPlayer.php           id, name, claimed, seat, hand, picks_this_round, ready, assembly
  DraftCategory.php           enum, 16 cases + bagCount()/handLimit()/finalLimit()/label()
  ComponentId.php             "CATEGORY:itemId" value object
  Component.php               one draftable item + its text/meta
  ComponentRepository.php     loads data/franken/components.json, statically cached (like Faction::all())
  ComponentSource.php         source value object + official/community flag
  Errata.php                  undraftable / alwaysAddToPool / swaps / grants / alternateText
  Bag.php                     id + ordered ComponentId list
  Hand.php                    per-category collections + limit checks
  Assembly.php                chosen swaps, granted items, trimmed selection, slice arrangement
  Round.php                   round number, quota, ready set, barrier logic
  Commands/
    GenerateFrankenDraft.php
    BuildComponentPools.php   applies sources + errata + exclusions -> per-category pools
    BuildBags.php             seeded deal into N bags
    FrankenPick.php
    FrankenUnpick.php
    FrankenSetReady.php
    AdvanceRound.php          barrier check + bag rotation
    SubmitAssembly.php
    UndoFrankenPick.php       admin
  Exceptions/
    InsufficientComponentsException.php
    InvalidFrankenPickException.php
    InvalidAssemblyException.php
```

### 6.1 Repository

`DraftRepository` gains a mode-aware factory. `load()` returns `Draft|FrankenDraft`; both implement
a small `PersistableDraft` interface (`id`, `mode()`, `toFileContent()`). Dispatch on
`$data['mode'] ?? 'milty'`.

---

## 7. Persisted JSON

```jsonc
{
  "id": "6810b63d6fd9d",
  "mode": "franken",
  "done": false,
  "config": {
    "mode": "franken",
    "players": ["Ann", "Ben", "Cleo"],
    "seed": 12345,
    "preset_draft_order": false,
    "draft_order_mode": "drafted",
    "sources": ["base", "pok", "codex1", "ds"],
    "limits": { "ABILITY": { "bag": 3, "hand": 3, "final": 3 }, "...": {} },
    "picks_first_round": 3,
    "picks_per_round": 2
  },
  "franken": {
    "stage": "bag_draft",              // bag_draft | assembly | done
    "round": 4,
    "quota": 2,
    "bags": {
      "bag_1": { "id": "bag_1", "items": ["ABILITY:mitosis", "BLUETILE:26", "..."] }
    },
    "bag_holder": { "<playerId>": "bag_3" },
    "players": {
      "<playerId>": {
        "id": "...", "name": "Ann", "claimed": true, "seat": 0,
        "hand": ["ABILITY:mitosis", "AGENT:solagent", "..."],
        "picks_this_round": 1,
        "ready": false,
        "assembly": {
          "confirmed": false,
          "swaps": { "ABILITY:star_forge": "MECH:muaat_mech" },
          "granted": ["ABILITY:mitosis"],
          "dropped": [],
          "slice": ["19", "20", "21", "22", "23"],
          "home_system": "HOMESYSTEM:sol"
        }
      }
    },
    "log": [
      { "round": 1, "player": "<playerId>", "action": "pick", "item": "ABILITY:mitosis" },
      { "round": 1, "action": "pass_bags" }
    ]
  },
  "secrets": { "admin_pass": "…", "<playerId>": "…" }
}
```

`secrets` is written to file but stripped from API responses, exactly as today.

---

## 8. HTTP API

Existing endpoints keep working unchanged for Milty drafts. New routes in `app/routes.php`:

| Method + route | Body / query | Effect |
|---|---|---|
| `POST /api/generate` | `mode=franken` + Franken settings | Creates a Franken draft. Same response shape (`{id, admin}`). |
| `GET /api/draft/{id}` | optional `player` + `secret`, or `admin` | Returns the draft, **redacted** per §9. |
| `POST /api/franken/pick` | `id, player, secret\|admin, round, item` | Take an item from your current bag. |
| `POST /api/franken/unpick` | `id, player, secret\|admin, round, item` | Return an item taken this round. |
| `POST /api/franken/ready` | `id, player, secret\|admin, round, ready` | Set/clear ready-to-pass; triggers the barrier check. |
| `POST /api/franken/assembly` | `id, player, secret\|admin, swaps, dropped, slice[], confirm` | Save or confirm assembly. |
| `POST /api/undo` | `id, admin` | Admin: undo the last pick (branches on mode). |
| `POST /api/claim`, `POST /api/restore` | unchanged | Reused as-is. |

Errors reuse the existing `{error: "..."}` JSON shape and status codes.

### 8.1 Concurrency control

Milty is turn-based, so the current optimistic check (`index == count(log)`) suffices there. Franken
picks are simultaneous, so that check would reject almost every pick. Instead:

* The API check becomes **semantic**: a pick is rejected if the item is no longer in the bag, the
  player's quota for the round is spent, the category is at its hand limit, or the round has already
  advanced (`round` mismatch).
* `DraftRepository` gains `mutate(string $id, callable $fn)` performing an atomic
  read-modify-write:
  * `LocalDraftRepository`: `flock(LOCK_EX)` over the draft file.
  * `S3DraftRepository`: read ETag, conditional `PutObject` with `If-Match`, retry up to 3 times on
    412.
* All Franken write commands go through `mutate()`. This is the single highest-risk change: without
  it, two players picking in the same second silently lose one pick.

---

## 9. Visibility rules

The bag you currently hold is private; what people have already drafted is public.

| Data | Spectator (no credentials) | Player | Admin |
|---|---|---|---|
| Settings, stage, round, ready flags, seat order | ✅ | ✅ | ✅ |
| Own bag contents | — | ✅ | ✅ |
| Other players' bag contents | count only | count only | ✅ |
| All hands (already-drafted items) | ✅ | ✅ | ✅ |
| Assembly choices | ✅ once confirmed | ✅ own always | ✅ |
| Log | ✅ | ✅ | ✅ |
| Secrets | never | never | never |

`GET /api/draft/{id}` therefore takes optional `player`+`secret` (or `admin`) parameters — the
frontend already holds both in `localStorage` — and redacts server-side. Redaction is a
`FrankenDraft::toArray(?PlayerId $viewer, bool $isAdmin)` responsibility, unit-tested.

---

## 10. Frontend

New files, mirroring the existing structure (vanilla JS + jQuery, no build step):

* `templates/franken-draft.php` — tabs **Draft · My Faction · Map · Log · Config · Session**,
  reusing the existing tab markup, popups, loading overlay and session/claim blocks verbatim.
* `js/franken-draft.js` — state render, pick/unpick/ready, assembly, polling.
* `css/_franken.scss`, imported by `css/style.scss` — component card grid, category rails, slice
  builder.
* `templates/generate.php` + `js/main.js` — mode switch and the Franken settings block.

Key UI behaviours:

* **Draft tab**: your bag as component cards grouped by category; a header showing
  `Round 4 · 2 picks left`; a player rail with everyone's ready state and hand counts; your hand
  below, grouped by category with `x/y` counters. Cards whose category is full render disabled with
  the reason.
* **Polling**: the current app only refreshes on `visibilitychange`. Franken adds a 5 s poll while
  the draft is active (paused when the tab is hidden), because progress now depends on other players
  acting simultaneously.
* **Round barrier feedback**: when you are ready and others are not, show "waiting for Ben, Cleo".
  When the barrier trips, animate the hand-off and show "Round 5 — new bag".
* **My Faction tab**: the assembled faction sheet — grants, swap choices, per-category final
  selection, and the slice builder with live optimal res/inf plus the anomaly-adjacency warning.
* **Map tab**: unchanged `js/generate-map.js`, fed the assembled slices + drafted draft order.

---

## 11. Validation and failure modes

| Case | Handling |
|---|---|
| Enabled sources cannot fill a category (`bagCount × players > pool size`) | Generation fails with a precise per-category message ("HOMESYSTEM needs 12 items, 9 available — enable more sources"). Mirrors AsyncTI4's "one or more bags are missing components". |
| A player cannot complete a category because the pool ran dry in circulation | Bag composition (2 per category per bag) makes this unlikely, but the hand panel warns when a still-needed category has no remaining source in circulation, and the admin gets a **force-complete** action granting a random legal remaining item. |
| Player takes the last legal item and the bag is otherwise unusable | Auto-ready (§3.4.2). |
| Two players pick the same item from the same bag | Impossible — one bag has one holder. Concurrency is between different bags, handled by §8.1. |
| Slice arrangement with adjacent anomalies | Warned, not blocked. |
| Draft resumed on another device | Existing `Secrets` / `restore` flow, unchanged. |

---

## 12. Explicitly out of scope for v1

* Powered / One-pick / Overdraft / FrankenDraz / Twilight's Fall / Inaugural Splice variants (the
  config carries per-category numbers, so these become presets later).
* Alliance / team mode in Franken.
* Fog of War.
* Importing card art.
* Extending `data/tiles.json` with tiles from community sources miltydraft does not ship.
* Any change to the Milty mode's behaviour.

---

## 13. Open questions

1. **Repo licence** — `shenanigans-be/miltydraft` ships no LICENSE file. Decide before publishing a
   fork (private/self-hosted use is unaffected).
2. **Upstream tracking** — keep `origin` pointing at upstream and develop on a branch so upstream
   Milty fixes stay mergeable?
3. **Which AsyncTI4 ref to pin** for the data sync (a tag vs `master`), and how often to re-sync.
